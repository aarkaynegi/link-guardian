<?php
/**
 * Redirect store + front-end redirect handler.
 *
 * Owns the redirects table: CRUD helpers, path normalisation, and the
 * `template_redirect` listener that actually sends visitors to the new URL.
 *
 * @package LinkGuardian
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Link_Guardian_Redirects
 */
class Link_Guardian_Redirects {

	/**
	 * Hard ceiling on how many hops a chain may have before we treat it as a loop.
	 */
	const MAX_HOPS = 25;

	/**
	 * Register front-end hooks.
	 *
	 * @return void
	 */
	public function init() {
		// Priority 1 so we run before redirect_canonical() and the 404 handler.
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
	}

	/**
	 * Table name helper.
	 *
	 * @return string
	 */
	public static function table() {
		return Link_Guardian_Activator::table_name();
	}

	// --- Path helpers ---

	/**
	 * Normalise a URL or path into a comparable, site-root-relative path.
	 *
	 * - Strips scheme/host, query string and fragment.
	 * - Removes the install sub-directory prefix (for sites in /blog etc.).
	 * - Decodes %20 style escapes, ensures a single leading slash and no
	 *   trailing slash (except for the site root "/").
	 *
	 * @param string $url_or_path Raw URL or path.
	 * @return string Normalised path, e.g. "/old-post".
	 */
	public static function normalize_path( $url_or_path ) {
		$url_or_path = (string) $url_or_path;
		$parts       = wp_parse_url( $url_or_path );
		$path        = isset( $parts['path'] ) ? $parts['path'] : '/';

		// Strip the home path prefix on sub-directory installs.
		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( $home_path && '/' !== $home_path && 0 === strpos( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) - 1 );
		}

		$path = rawurldecode( $path );
		$path = '/' . ltrim( $path, '/' );

		if ( strlen( $path ) > 1 ) {
			$path = rtrim( $path, '/' );
		}

		return $path;
	}

	/**
	 * Resolve a stored target (which may be relative) into an absolute URL.
	 *
	 * @param string $target Stored target URL or path.
	 * @return string
	 */
	public static function absolute_target( $target ) {
		if ( preg_match( '#^https?://#i', $target ) ) {
			return $target;
		}
		return home_url( '/' . ltrim( $target, '/' ) );
	}

	/**
	 * Sanitise a redirect target. Only http/https absolute URLs or site-root
	 * relative paths are allowed — javascript:, data:, mailto:, vbscript: and
	 * protocol-relative ("//evil.com") targets are rejected so a stored redirect
	 * can never become an XSS or host-smuggling vector.
	 *
	 * @param string $target Raw target.
	 * @return string Safe target, or '' if it must be rejected.
	 */
	public static function sanitize_target( $target ) {
		$target = trim( (string) $target );
		if ( '' === $target ) {
			return '';
		}

		// Protocol-relative URLs could smuggle an arbitrary host: reject.
		if ( 0 === strpos( $target, '//' ) ) {
			return '';
		}

		// Anything carrying an explicit scheme must be http(s).
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $target ) ) {
			if ( ! preg_match( '#^https?://#i', $target ) ) {
				return '';
			}
			return esc_url_raw( $target, array( 'http', 'https' ) );
		}

		// Otherwise treat it as a path and normalise the leading slash.
		return '/' . ltrim( $target, '/' );
	}

	// --- CRUD ---

	/**
	 * Insert or update a redirect, keyed on its (unique) source path.
	 *
	 * @param array $args {
	 *     Redirect fields.
	 *
	 *     @type string $source_path   Required. Source URL or path.
	 *     @type string $target_url    Required. Destination URL or path.
	 *     @type int    $redirect_type 301 or 302.
	 *     @type int    $is_active     1/0.
	 *     @type int    $is_auto       1/0.
	 *     @type int    $post_id       Associated post id.
	 * }
	 * @return int|false Row id on success, false on failure.
	 */
	public function upsert( $args ) {
		global $wpdb;

		$defaults = array(
			'source_path'   => '',
			'target_url'    => '',
			'redirect_type' => 301,
			'is_active'     => 1,
			'is_auto'       => 0,
			'post_id'       => 0,
		);
		$args     = wp_parse_args( $args, $defaults );

		$source = self::normalize_path( $args['source_path'] );
		$target = self::sanitize_target( $args['target_url'] );

		if ( '' === $source || '' === $target ) {
			return false;
		}

		// Refuse anything that would create a redirect loop: a direct self-loop, or a
		// multi-hop A -> B -> ... -> A cycle that closes through the existing rules.
		if ( $this->would_create_cycle( $source, $target ) ) {
			return false;
		}

		$type = in_array( (int) $args['redirect_type'], array( 301, 302, 307, 308 ), true ) ? (int) $args['redirect_type'] : 301;

		$existing = $this->get_by_source( $source );

		$data = array(
			'source_path'   => $source,
			'target_url'    => $target,
			'redirect_type' => $type,
			'is_active'     => $args['is_active'] ? 1 : 0,
			'is_auto'       => $args['is_auto'] ? 1 : 0,
			'post_id'       => (int) $args['post_id'],
		);

		if ( $existing ) {
			$wpdb->update(
				self::table(),
				$data,
				array( 'id' => $existing->id ),
				array( '%s', '%s', '%d', '%d', '%d', '%d' ),
				array( '%d' )
			);
			return (int) $existing->id;
		}

		$data['hits']       = 0;
		$data['created_at'] = current_time( 'mysql' );

		$ok = $wpdb->insert(
			self::table(),
			$data,
			array( '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Fetch a redirect by its normalised source path.
	 *
	 * @param string $source_path Source path (will be normalised).
	 * @return object|null
	 */
	public function get_by_source( $source_path ) {
		global $wpdb;
		$source = self::normalize_path( $source_path );
		$table  = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_path = %s", $source ) );
	}

	/**
	 * Fetch the active redirect for a source path, if any.
	 *
	 * @param string $source_path Source path.
	 * @return object|null
	 */
	public function get_active_by_source( $source_path ) {
		$row = $this->get_by_source( $source_path );
		return ( $row && 1 === (int) $row->is_active ) ? $row : null;
	}

	/**
	 * Delete a redirect by id.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Delete any redirect whose source matches the given path.
	 *
	 * Used when a slug becomes a live URL again (rename-back) so the now-live URL
	 * is never redirected away from itself.
	 *
	 * @param string $source_path Source path (will be normalised).
	 * @return int Rows deleted.
	 */
	public function delete_by_source( $source_path ) {
		global $wpdb;
		$source = self::normalize_path( $source_path );
		return (int) $wpdb->delete( self::table(), array( 'source_path' => $source ), array( '%s' ) );
	}

	/**
	 * Toggle the active flag.
	 *
	 * @param int $id     Row id.
	 * @param int $active 1/0.
	 * @return bool
	 */
	public function set_active( $id, $active ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom redirects table; no core API.
		return (bool) $wpdb->update(
			self::table(),
			array( 'is_active' => $active ? 1 : 0 ),
			array( 'id' => (int) $id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Fetch a single redirect by id.
	 *
	 * @param int $id Row id.
	 * @return object|null
	 */
	public function get( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; name from $wpdb->prefix, value bound via prepare().
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
	}

	/**
	 * Update an existing redirect's target + type (keyed by id). The source path
	 * is the rule's identity and is not changed here.
	 *
	 * @param int    $id     Row id.
	 * @param string $target New target URL or path.
	 * @param int    $type   301 or 302.
	 * @return bool
	 */
	public function update_target( $id, $target, $type ) {
		global $wpdb;
		$target = self::sanitize_target( $target );
		if ( '' === $target ) {
			return false;
		}
		$type = in_array( (int) $type, array( 301, 302, 307, 308 ), true ) ? (int) $type : 301;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom redirects table; no core API.
		return (bool) $wpdb->update(
			self::table(),
			array(
				'target_url'    => $target,
				'redirect_type' => $type,
			),
			array( 'id' => (int) $id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Paginated fetch for the admin list table.
	 *
	 * @param array $args { @type int $per_page, @type int $page, @type string $search }.
	 * @return array { @type array $items, @type int $total }
	 */
	public function query( $args = array() ) {
		global $wpdb;

		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$search   = isset( $args['search'] ) ? trim( $args['search'] ) : '';
		$offset   = ( $page - 1 ) * $per_page;
		$table    = self::table();

		$where  = 'WHERE 1=1';
		$params = array();
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND ( source_path LIKE %s OR target_url LIKE %s )';
			$params[] = $like;
			$params[] = $like;
		}

		// $table is code-controlled and $where contains only literal SQL with %s
		// placeholders; every user value is bound through prepare( $sql, $params ).
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$table} {$where}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$list_params   = $params;
		$list_params[] = $per_page;
		$list_params[] = $offset;
		$list_sql      = "SELECT * FROM {$table} {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
		$items         = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Total redirect count (for dashboard widgets / menu badge).
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Fetch every redirect (used by the audit API).
	 *
	 * @return object[]
	 */
	public function get_all() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" );
	}

	// --- Front-end handler ---

	/**
	 * Inspect the current request and redirect if a rule matches.
	 *
	 * @return void
	 */
	public function maybe_redirect() {
		if ( is_admin() || is_robots() || is_favicon() ) {
			return;
		}

		$request = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $request ) {
			return;
		}

		$path     = self::normalize_path( $request );
		$resolved = $this->resolve_chain( $path );
		if ( null === $resolved ) {
			return;
		}

		// Final safety net: never redirect a path back onto itself.
		if ( self::is_same_host( $resolved['target'] ) && self::normalize_path( $resolved['target'] ) === $path ) {
			return;
		}

		$this->increment_hit( $resolved['first_id'] );

		// wp_redirect (not wp_safe_redirect): admins may intentionally point a
		// redirect off-site, and the target was sanitised on save.
		wp_redirect( $resolved['target'], $resolved['type'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	// --- Chain resolution + loop protection ---

	/**
	 * Walk the redirect chain starting at a path, collapsing multiple hops and
	 * detecting cycles. This is the single source of truth used by the front-end
	 * handler, the audit API and the single-URL tracer.
	 *
	 * @param string $start_path Path to start from.
	 * @return array {
	 *     @type bool   $matched   Whether any active rule matched the start path.
	 *     @type array  $hops      Ordered list of paths visited (last item may be an external URL).
	 *     @type bool   $loop      Whether a cycle / runaway chain was detected.
	 *     @type bool   $external  Whether the chain terminates off-site.
	 *     @type string $terminal  Absolute URL of the final destination.
	 *     @type int    $type      HTTP status to use (taken from the first rule).
	 *     @type int    $first_id  Row id of the first matched rule.
	 * }
	 */
	public function walk_chain( $start_path ) {
		$start_path = self::normalize_path( $start_path );

		$out = array(
			'matched'  => false,
			'hops'     => array( $start_path ),
			'loop'     => false,
			'external' => false,
			'terminal' => self::absolute_target( $start_path ),
			'type'     => 301,
			'first_id' => 0,
		);

		$first = $this->get_active_by_source( $start_path );
		if ( ! $first ) {
			return $out;
		}

		$out['matched']  = true;
		$out['first_id'] = (int) $first->id;
		$type            = (int) $first->redirect_type;
		$out['type']     = in_array( $type, array( 301, 302, 307, 308 ), true ) ? $type : 301;

		$visited = array( $start_path => true );
		$current = $first;
		$hops    = 0;

		while ( true ) {
			$target_abs = self::absolute_target( $current->target_url );

			// Off-site target: the chain ends here.
			if ( ! self::is_same_host( $target_abs ) ) {
				$out['external'] = true;
				$out['terminal'] = $target_abs;
				$out['hops'][]   = $target_abs;
				break;
			}

			$target_path = self::normalize_path( $target_abs );

			// We have seen this path before -> cycle.
			if ( isset( $visited[ $target_path ] ) ) {
				$out['loop']     = true;
				$out['hops'][]   = $target_path;
				$out['terminal'] = $target_abs;
				break;
			}

			$out['hops'][]           = $target_path;
			$visited[ $target_path ] = true;

			$next = $this->get_active_by_source( $target_path );
			if ( ! $next ) {
				// Reached a path with no further rule: terminal destination.
				$out['terminal'] = $target_abs;
				break;
			}

			$current = $next;

			if ( ++$hops > self::MAX_HOPS ) {
				$out['loop']     = true;
				$out['terminal'] = $target_abs;
				break;
			}
		}

		return $out;
	}

	/**
	 * Resolve a request path to a single, loop-safe destination.
	 *
	 * @param string $path Requested path.
	 * @return array|null { @type string $target, @type int $type, @type int $first_id } or null
	 *                    when nothing matches or a loop is detected.
	 */
	public function resolve_chain( $path ) {
		$walk = $this->walk_chain( $path );

		if ( ! $walk['matched'] ) {
			return null;
		}

		if ( $walk['loop'] ) {
			$this->flag_loop( $walk['hops'] );
			return null;
		}

		return array(
			'target'   => $walk['terminal'],
			'type'     => $walk['type'],
			'first_id' => $walk['first_id'],
		);
	}

	/**
	 * Would adding a rule source -> target close a loop through existing rules?
	 *
	 * Walks forward from the proposed target; if it ever arrives back at the
	 * proposed source (or runs away past MAX_HOPS), the new rule would create a
	 * cycle and must be refused.
	 *
	 * @param string $source Proposed source (will be normalised).
	 * @param string $target Proposed target.
	 * @return bool
	 */
	public function would_create_cycle( $source, $target ) {
		$source     = self::normalize_path( $source );
		$target_abs = self::absolute_target( $target );

		// An off-site target can never loop back into the site.
		if ( ! self::is_same_host( $target_abs ) ) {
			return false;
		}

		$path    = self::normalize_path( $target_abs );
		$visited = array();
		$hops    = 0;

		while ( true ) {
			if ( $path === $source ) {
				return true; // Chain returns to the source we are about to add.
			}
			if ( isset( $visited[ $path ] ) ) {
				return false; // A pre-existing cycle that does not involve our source.
			}

			$visited[ $path ] = true;

			$rule = $this->get_active_by_source( $path );
			if ( ! $rule ) {
				return false; // Terminal: no loop.
			}

			$next_abs = self::absolute_target( $rule->target_url );
			if ( ! self::is_same_host( $next_abs ) ) {
				return false; // Leaves the site: no loop.
			}

			$path = self::normalize_path( $next_abs );

			if ( ++$hops > self::MAX_HOPS ) {
				return true; // Treat a runaway chain as a loop.
			}
		}
	}

	/**
	 * Audit every redirect: surface loops, multi-hop chains, and "connected"
	 * rules whose target is itself another rule's source. Powers the REST API.
	 *
	 * @return array
	 */
	public function audit() {
		$all      = $this->get_all();
		$active   = array();
		$inactive = 0;
		$auto     = 0;

		foreach ( $all as $rule ) {
			if ( 1 === (int) $rule->is_active ) {
				$active[ $rule->source_path ] = $rule;
			} else {
				++$inactive;
			}
			if ( 1 === (int) $rule->is_auto ) {
				++$auto;
			}
		}

		$loops       = array();
		$loop_keys   = array();
		$chains      = array();
		$connected   = array();
		$broken_dest = array();

		foreach ( $active as $source => $rule ) {
			$walk = $this->walk_chain( $source );

			if ( $walk['loop'] ) {
				$key = $this->loop_signature( $walk['hops'] );
				if ( ! isset( $loop_keys[ $key ] ) ) {
					$loop_keys[ $key ] = true;
					$loops[]           = $walk['hops'];
				}
			} elseif ( count( $walk['hops'] ) > 2 ) {
				// More than one hop = a chain we collapse at serve time.
				$chains[] = array(
					'source'   => $source,
					'path'     => $walk['hops'],
					'terminal' => $walk['terminal'],
					'hops'     => count( $walk['hops'] ) - 1,
					'external' => $walk['external'],
				);
			}

			// "Connected": this rule's direct target is itself an active source.
			$direct_abs = self::absolute_target( $rule->target_url );
			if ( self::is_same_host( $direct_abs ) ) {
				$direct_path = self::normalize_path( $direct_abs );
				if ( isset( $active[ $direct_path ] ) ) {
					$connected[] = array(
						'source' => $source,
						'target' => $direct_path,
					);
				} elseif ( ! $walk['loop'] && ! $walk['external'] && 0 === url_to_postid( $direct_abs ) ) {
					// Terminal target that resolves to no known post (possible dead end).
					$broken_dest[] = array(
						'source'   => $source,
						'terminal' => $walk['terminal'],
					);
				}
			}
		}

		return array(
			'summary'     => array(
				'total'     => count( $all ),
				'active'    => count( $active ),
				'inactive'  => $inactive,
				'auto'      => $auto,
				'loops'     => count( $loops ),
				'chains'    => count( $chains ),
				'connected' => count( $connected ),
			),
			'loops'       => $loops,
			'chains'      => $chains,
			'connected'   => $connected,
			'broken_dest' => $broken_dest,
		);
	}

	/**
	 * Order-independent signature for a cycle so we dedupe equivalent loops.
	 *
	 * @param array $hops Hop list ending where the cycle closed.
	 * @return string
	 */
	protected function loop_signature( $hops ) {
		$nodes = array_unique( $hops );
		sort( $nodes );
		return implode( '>', $nodes );
	}

	/**
	 * Record that a loop was hit at serve time (debug log only).
	 *
	 * @param array $hops Hop list.
	 * @return void
	 */
	protected function flag_loop( $hops ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Link Guardian: redirect loop detected (' . implode( ' -> ', (array) $hops ) . '); redirect aborted to avoid an infinite loop.' );
		}
	}

	/**
	 * Bump the hit counter + last_hit timestamp for a rule.
	 *
	 * @param int $id Row id.
	 * @return void
	 */
	public function increment_hit( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, last_hit = %s WHERE id = %d", current_time( 'mysql' ), (int) $id ) );
	}

	/**
	 * Whether a URL points at the current site's host.
	 *
	 * @param string $url URL to test.
	 * @return bool
	 */
	public static function is_same_host( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $host ) ) {
			return true; // relative URL.
		}
		return strtolower( $host ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}
}
