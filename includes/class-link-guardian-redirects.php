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
	 * Longest source the indexed source_path column can hold (see the
	 * varchar(190) column in Link_Guardian_Activator::create_table()).
	 */
	const MAX_SOURCE_LEN = 190;

	/**
	 * The HTTP status codes a redirect rule may use.
	 */
	const REDIRECT_TYPES = array( 301, 302, 307, 308 );

	/**
	 * Normalise a redirect status code, falling back to 301.
	 *
	 * @param mixed $type Raw type.
	 * @return int
	 */
	public static function normalize_type( $type ) {
		$type = (int) $type;
		return in_array( $type, self::REDIRECT_TYPES, true ) ? $type : 301;
	}

	/**
	 * Whether a target carries an unsafe scheme: a protocol-relative "//host"
	 * (host smuggling) or any explicit scheme that is not http(s). Shared
	 * open-redirect / XSS guard used when sanitising both exact and pattern targets.
	 *
	 * @param string $target Target string.
	 * @return bool
	 */
	protected static function has_unsafe_scheme( $target ) {
		if ( 0 === strpos( $target, '//' ) ) {
			return true;
		}
		return (bool) preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $target ) && ! preg_match( '#^https?://#i', $target );
	}

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

		// Strip the home path prefix on sub-directory installs (handling both the
		// "/blog/" and the no-trailing-slash "/blog" forms of the subdir root).
		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( $home_path && '/' !== $home_path ) {
			if ( rtrim( $home_path, '/' ) === $path ) {
				$path = '/';
			} elseif ( 0 === strpos( $path, $home_path ) ) {
				$path = substr( $path, strlen( $home_path ) - 1 );
			}
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
		if ( '' === $target || self::has_unsafe_scheme( $target ) ) {
			return '';
		}

		// An explicit http(s) URL is kept; anything else is treated as a path.
		if ( preg_match( '#^https?://#i', $target ) ) {
			return esc_url_raw( $target, array( 'http', 'https' ) );
		}
		return '/' . ltrim( $target, '/' );
	}

	// --- CRUD ---

	/**
	 * Insert or update a redirect, keyed on its (unique) source.
	 *
	 * @param array $args {
	 *     Redirect fields.
	 *
	 *     @type string $source_path   Required. Source URL/path, or a wildcard/regex pattern.
	 *     @type string $target_url    Required. Destination (supports $1, $2 capture refs for patterns).
	 *     @type string $match_type    'exact' (default), 'wildcard', or 'regex'.
	 *     @type string $exceptions    Newline-separated paths to exclude from a pattern match.
	 *     @type int    $redirect_type 301 or 302.
	 *     @type int    $is_active     1/0.
	 *     @type int    $is_auto       1/0.
	 *     @type int    $post_id       Associated post id.
	 * }
	 * @return int|false Row id on success, false on failure.
	 */
	public function upsert( $args ) {
		global $wpdb;

		$defaults   = array(
			'source_path'   => '',
			'target_url'    => '',
			'match_type'    => 'exact',
			'exceptions'    => '',
			'redirect_type' => 301,
			'is_active'     => 1,
			'is_auto'       => 0,
			'post_id'       => 0,
		);
		$args       = wp_parse_args( $args, $defaults );
		$match_type = self::sanitize_match_type( $args['match_type'] );

		if ( 'exact' === $match_type ) {
			$source = self::normalize_path( $args['source_path'] );
			$target = self::sanitize_target( $args['target_url'] );
		} else {
			$source = self::normalize_pattern_source( $args['source_path'], $match_type );
			$target = self::sanitize_pattern_target( $args['target_url'] );
		}

		// Reject empties, and exact sources too long for the indexed column
		// (pattern sources are already capped in normalize_pattern_source()).
		if ( '' === $source || '' === $target || ( 'exact' === $match_type && strlen( $source ) > self::MAX_SOURCE_LEN ) ) {
			return false;
		}

		if ( 'exact' === $match_type ) {
			// Refuse anything that would create a redirect loop.
			if ( $this->would_create_cycle( $source, $target ) ) {
				return false;
			}
		} elseif ( null === self::compile_pattern( $source, $match_type ) ) {
			// A pattern must compile to a valid, bounded expression.
			return false;
		}

		$type = self::normalize_type( $args['redirect_type'] );

		// Find the existing row to update. Exact rules look up by normalised path;
		// pattern rules are stored verbatim, so they must look up by literal source
		// (normalize_path() would mangle a regex and clobber an unrelated row).
		$existing = ( 'exact' === $match_type )
			? $this->get_by_source( $source )
			: $this->get_row_by_exact_source( $source );

		$data    = array(
			'source_path'   => $source,
			'target_url'    => $target,
			'match_type'    => $match_type,
			'exceptions'    => self::sanitize_exceptions( $args['exceptions'] ),
			'redirect_type' => $type,
			'is_active'     => $args['is_active'] ? 1 : 0,
			'is_auto'       => $args['is_auto'] ? 1 : 0,
			'post_id'       => (int) $args['post_id'],
		);
		$formats = array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' );

		$this->flush_cache();

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom redirects table; no core API.
			$wpdb->update( self::table(), $data, array( 'id' => $existing->id ), $formats, array( '%d' ) );
			return (int) $existing->id;
		}

		$data['hits']       = 0;
		$data['created_at'] = current_time( 'mysql' );
		$formats[]          = '%d';
		$formats[]          = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom redirects table; no core API.
		$ok = $wpdb->insert( self::table(), $data, $formats );

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/* ---- Pattern matching engine (wildcard / regex) ---- */

	/**
	 * Normalise a match type to one of: exact, wildcard, regex.
	 *
	 * @param string $type Raw type.
	 * @return string
	 */
	public static function sanitize_match_type( $type ) {
		$type = is_string( $type ) ? strtolower( trim( $type ) ) : 'exact';
		return in_array( $type, array( 'exact', 'wildcard', 'regex' ), true ) ? $type : 'exact';
	}

	/**
	 * Normalise a pattern source. Wildcards get a leading slash (keeping "*");
	 * regex sources are stored verbatim. Capped to fit the indexed column.
	 *
	 * @param string $source     Raw source.
	 * @param string $match_type wildcard|regex.
	 * @return string
	 */
	public static function normalize_pattern_source( $source, $match_type ) {
		$source = trim( (string) $source );
		if ( '' === $source ) {
			return '';
		}
		// Reject (rather than silently truncate) anything that would not fit the
		// indexed source_path column, so an over-long pattern fails predictably.
		$result = ( 'wildcard' === $match_type ) ? '/' . ltrim( $source, '/' ) : $source;
		return ( strlen( $result ) > self::MAX_SOURCE_LEN ) ? '' : $result;
	}

	/**
	 * Sanitise a pattern target: allow capture refs ($1) and paths/URLs, but
	 * reject dangerous schemes and protocol-relative hosts.
	 *
	 * @param string $target Raw target.
	 * @return string
	 */
	public static function sanitize_pattern_target( $target ) {
		$target = trim( (string) $target );
		if ( '' === $target || self::has_unsafe_scheme( $target ) ) {
			return '';
		}
		return $target; // Verbatim, to preserve $1/$2 capture refs.
	}

	/**
	 * Sanitise an exceptions list: trim lines, drop blanks, cap count and length.
	 *
	 * @param string $exceptions Raw newline-separated list.
	 * @return string
	 */
	public static function sanitize_exceptions( $exceptions ) {
		$exceptions = (string) $exceptions;
		if ( '' === trim( $exceptions ) ) {
			return '';
		}
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $exceptions ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
			if ( count( $out ) >= 100 ) {
				break;
			}
		}
		return substr( implode( "\n", $out ), 0, 5000 );
	}

	/**
	 * Compile a pattern source into a bounded, delimited regex, or null if invalid.
	 *
	 * @param string $source     Pattern source.
	 * @param string $match_type wildcard|regex.
	 * @return string|null
	 */
	public static function compile_pattern( $source, $match_type ) {
		$source = (string) $source;
		if ( '' === $source || strlen( $source ) > self::MAX_SOURCE_LEN ) {
			return null;
		}
		if ( 'wildcard' === $match_type ) {
			return '#^' . str_replace( '\*', '(.*)', preg_quote( $source, '#' ) ) . '$#';
		}
		if ( 'regex' === $match_type ) {
			$regex = '#' . $source . '#';
			// A compile error makes preg_match return false rather than 0/1.
			return ( false !== @preg_match( $regex, '' ) ) ? $regex : null; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return null;
	}

	/**
	 * Run preg_match() with a bounded backtrack limit so a pathological pattern
	 * fails fast instead of hanging the request (ReDoS guard).
	 *
	 * @param string $regex   Delimited regex.
	 * @param string $subject Subject path.
	 * @return bool True on match.
	 */
	protected static function bounded_match( $regex, $subject ) {
		$prev_backtrack = ini_get( 'pcre.backtrack_limit' );
		$prev_recursion = ini_get( 'pcre.recursion_limit' );
		ini_set( 'pcre.backtrack_limit', '100000' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		ini_set( 'pcre.recursion_limit', '10000' );  // phpcs:ignore WordPress.PHP.IniSet.Risky
		$result = @preg_match( $regex, $subject ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $prev_backtrack ) {
			ini_set( 'pcre.backtrack_limit', $prev_backtrack ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}
		if ( false !== $prev_recursion ) {
			ini_set( 'pcre.recursion_limit', $prev_recursion ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}
		return 1 === $result;
	}

	/**
	 * Active pattern (non-exact) rules, cached for the request.
	 *
	 * @return object[]
	 */
	public function get_pattern_rules() {
		$cached = wp_cache_get( 'pattern_rules', 'link_guardian' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; name from $wpdb->prefix; fixed condition, no user input; cached below.
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE is_active = 1 AND match_type <> 'exact' ORDER BY id ASC LIMIT 200" );
		wp_cache_set( 'pattern_rules', $rows, 'link_guardian' );
		return $rows;
	}

	/**
	 * Match a request path against the active pattern rules.
	 *
	 * @param string $path Requested path.
	 * @return array|null { @type string $target, @type int $type, @type int $first_id } or null.
	 */
	public function match_pattern( $path ) {
		foreach ( $this->get_pattern_rules() as $rule ) {
			$regex = self::compile_pattern( $rule->source_path, $rule->match_type );
			if ( null === $regex || ! self::bounded_match( $regex, $path ) ) {
				continue;
			}
			if ( self::path_excepted( $path, isset( $rule->exceptions ) ? $rule->exceptions : '' ) ) {
				continue;
			}

			$replacement = ( 'wildcard' === $rule->match_type )
				? self::wildcard_replacement( $rule->target_url )
				: $rule->target_url;

			$target = @preg_replace( $regex, $replacement, $path, 1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( null === $target || '' === $target ) {
				continue;
			}

			$absolute = self::absolute_target( $target );

			// Open-redirect guard: the redirect host must never be derived from a
			// visitor-supplied capture. An off-site target is only honoured when the
			// host is literal in the rule's template (captures stripped) — otherwise
			// a rule like "/go/* -> *" would let any visitor redirect anywhere.
			if ( ! self::is_same_host( $absolute ) ) {
				$template_host = wp_parse_url( self::absolute_target( self::strip_captures( $rule->target_url, $rule->match_type ) ), PHP_URL_HOST );
				$result_host   = wp_parse_url( $absolute, PHP_URL_HOST );
				if ( empty( $template_host ) || strtolower( (string) $template_host ) !== strtolower( (string) $result_host ) ) {
					continue;
				}
			}

			// Same-path guard so a pattern can never redirect a URL onto itself.
			if ( self::is_same_host( $absolute ) && self::normalize_path( $absolute ) === $path ) {
				continue;
			}

			return array(
				'target'   => $absolute,
				'type'     => self::normalize_type( $rule->redirect_type ),
				'first_id' => (int) $rule->id,
			);
		}
		return null;
	}

	/**
	 * Resolve a path that may match a pattern rule, following any further hops
	 * (exact or pattern) with a visited-set + hop cap, so pattern rules can never
	 * create an infinite browser redirect loop (e.g. /blog/* -> /blog/archive/*).
	 *
	 * @param string $path Requested path.
	 * @return array|null { @type string $target, @type int $type, @type int $first_id } or null.
	 */
	public function resolve_pattern_chain( $path ) {
		$visited = array( $path => true );
		$current = $path;
		$first   = null;
		$hops    = 0;

		while ( true ) {
			$rule = $this->get_active_by_source( $current );
			if ( $rule ) {
				$absolute = self::absolute_target( $rule->target_url );
				$type     = (int) $rule->redirect_type;
				$id       = (int) $rule->id;
			} else {
				$match = $this->match_pattern( $current );
				if ( null === $match ) {
					break; // Terminal: nothing redirects this path.
				}
				$absolute = $match['target'];
				$type     = $match['type'];
				$id       = $match['first_id'];
			}

			if ( null === $first ) {
				$first = array(
					'type' => self::normalize_type( $type ),
					'id'   => $id,
				);
			}

			// An off-site target ends the chain.
			if ( ! self::is_same_host( $absolute ) ) {
				return array(
					'target'   => $absolute,
					'type'     => $first['type'],
					'first_id' => $first['id'],
				);
			}

			$next = self::normalize_path( $absolute );
			if ( $next === $current || isset( $visited[ $next ] ) || ++$hops > self::MAX_HOPS ) {
				$this->flag_loop( array_keys( $visited ) );
				return null; // Loop or runaway chain — abort instead of redirecting.
			}

			$visited[ $next ] = true;
			$current          = $next;
		}

		if ( null === $first ) {
			return null;
		}

		// Reached a terminal path with no further rule: send the visitor there.
		return array(
			'target'   => self::absolute_target( $current ),
			'type'     => $first['type'],
			'first_id' => $first['id'],
		);
	}

	/**
	 * Whether a request path is covered by a rule's exception list.
	 *
	 * @param string $path       Request path.
	 * @param string $exceptions Newline-separated exceptions (exact or wildcard).
	 * @return bool
	 */
	protected static function path_excepted( $path, $exceptions ) {
		$exceptions = (string) $exceptions;
		if ( '' === trim( $exceptions ) ) {
			return false;
		}
		foreach ( preg_split( '/\r\n|\r|\n/', $exceptions ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( false !== strpos( $line, '*' ) ) {
				$regex = '#^' . str_replace( '\*', '.*', preg_quote( self::normalize_pattern_source( $line, 'wildcard' ), '#' ) ) . '$#';
				if ( self::bounded_match( $regex, $path ) ) {
					return true;
				}
			} elseif ( self::normalize_path( $line ) === $path ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Remove capture placeholders from a target template, leaving only its
	 * literal parts (used by the open-redirect host check).
	 *
	 * @param string $target     Target template.
	 * @param string $match_type wildcard|regex.
	 * @return string
	 */
	protected static function strip_captures( $target, $match_type ) {
		$target = (string) $target;
		if ( 'wildcard' === $match_type ) {
			return str_replace( '*', '', $target );
		}
		return (string) preg_replace( '/\$\{?\d+\}?|\\\\\d+/', '', $target );
	}

	/**
	 * Convert a wildcard target's "*" placeholders into ordered capture refs.
	 *
	 * @param string $target Wildcard target (with "*").
	 * @return string
	 */
	protected static function wildcard_replacement( $target ) {
		$parts = explode( '*', (string) $target );
		$out   = '';
		$last  = count( $parts ) - 1;
		foreach ( $parts as $i => $part ) {
			$out .= str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $part );
			if ( $i < $last ) {
				$out .= '$' . ( $i + 1 );
			}
		}
		return $out;
	}

	/**
	 * Clear the pattern-rule cache after any write.
	 *
	 * @return void
	 */
	protected function flush_cache() {
		wp_cache_delete( 'pattern_rules', 'link_guardian' );
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; name from $wpdb->prefix, value bound via prepare().
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_path = %s", $source ) );
	}

	/**
	 * Fetch a redirect by its exact (verbatim, un-normalised) source string.
	 * Used for pattern rules, whose source is stored as-is.
	 *
	 * @param string $source Literal source string.
	 * @return object|null
	 */
	public function get_row_by_exact_source( $source ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; name from $wpdb->prefix, value bound via prepare().
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_path = %s", (string) $source ) );
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
		$this->flush_cache();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom redirects table; no core API.
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
		$this->flush_cache();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom redirects table; no core API.
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
		$this->flush_cache();
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
	 * @param int         $id         Row id.
	 * @param string      $target     New target URL or path (capture refs allowed for patterns).
	 * @param int         $type       301 or 302.
	 * @param string|null $exceptions Optional new exceptions list (patterns); null leaves it unchanged.
	 * @return bool
	 */
	public function update_target( $id, $target, $type, $exceptions = null ) {
		global $wpdb;

		$row = $this->get( $id );
		if ( ! $row ) {
			return false;
		}

		$match_type = self::sanitize_match_type( $row->match_type );
		$target     = ( 'exact' === $match_type ) ? self::sanitize_target( $target ) : self::sanitize_pattern_target( $target );
		if ( '' === $target ) {
			return false;
		}

		$type    = self::normalize_type( $type );
		$data    = array(
			'target_url'    => $target,
			'redirect_type' => $type,
		);
		$formats = array( '%s', '%d' );

		if ( null !== $exceptions ) {
			$data['exceptions'] = self::sanitize_exceptions( $exceptions );
			$formats[]          = '%s';
		}

		$this->flush_cache();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom redirects table; no core API.
		return (bool) $wpdb->update( self::table(), $data, array( 'id' => (int) $id ), $formats, array( '%d' ) );
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
			// No exact rule matched: fall back to wildcard / regex pattern rules,
			// following any further hops with loop detection.
			$resolved = $this->resolve_pattern_chain( $path );
		}
		if ( null === $resolved ) {
			return;
		}

		// Final safety net: never redirect a path back onto itself.
		if ( self::is_same_host( $resolved['target'] ) && self::normalize_path( $resolved['target'] ) === $path ) {
			return;
		}

		$target = $resolved['target'];

		// Carry the incoming query string over when the target has none of its
		// own (so /old?utm=x -> /new becomes /new?utm=x). wp_redirect() strips
		// any CR/LF, so this cannot be used for header injection.
		$query = (string) wp_parse_url( $request, PHP_URL_QUERY );
		if ( '' !== $query && false === strpos( $target, '?' ) ) {
			$target .= '?' . $query;
		}

		$this->increment_hit( $resolved['first_id'] );

		// wp_redirect (not wp_safe_redirect): admins may intentionally point a
		// redirect off-site. Exact targets are sanitised on save; pattern targets
		// can only resolve off-site to a host that is literal in the admin's
		// template — match_pattern()'s open-redirect guard refuses any host that
		// came from a visitor-supplied capture.
		wp_redirect( $target, $resolved['type'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
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
		$out['type']     = self::normalize_type( $type );

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
	 * Audit every redirect. Exact rules are analysed for loops, multi-hop chains,
	 * "connected" links, and dead-end targets; pattern (wildcard/regex) rules are
	 * listed and checked for self-loops and invalid expressions. Powers the REST API.
	 *
	 * @return array
	 */
	public function audit() {
		$all      = $this->get_all();
		$exact    = array(); // Active exact rules, keyed by source.
		$patterns = array(); // Active pattern rules.
		$inactive = 0;
		$auto     = 0;

		foreach ( $all as $rule ) {
			if ( 1 === (int) $rule->is_active ) {
				if ( 'exact' === $rule->match_type ) {
					$exact[ $rule->source_path ] = $rule;
				} else {
					$patterns[] = $rule;
				}
			} else {
				++$inactive;
			}
			if ( 1 === (int) $rule->is_auto ) {
				++$auto;
			}
		}

		// --- Exact-rule analysis (walk_chain only follows exact sources) ---
		$loops       = array();
		$loop_keys   = array();
		$chains      = array();
		$connected   = array();
		$broken_dest = array();

		foreach ( $exact as $source => $rule ) {
			$walk = $this->walk_chain( $source );

			if ( $walk['loop'] ) {
				$key = $this->loop_signature( $walk['hops'] );
				if ( ! isset( $loop_keys[ $key ] ) ) {
					$loop_keys[ $key ] = true;
					$loops[]           = $walk['hops'];
				}
			} elseif ( count( $walk['hops'] ) > 2 ) {
				$chains[] = array(
					'source'   => $source,
					'path'     => $walk['hops'],
					'terminal' => $walk['terminal'],
					'hops'     => count( $walk['hops'] ) - 1,
					'external' => $walk['external'],
				);
			}

			$direct_abs = self::absolute_target( $rule->target_url );
			if ( self::is_same_host( $direct_abs ) ) {
				$direct_path = self::normalize_path( $direct_abs );
				if ( isset( $exact[ $direct_path ] ) ) {
					$connected[] = array(
						'source' => $source,
						'target' => $direct_path,
					);
				} elseif ( ! $walk['loop'] && ! $walk['external'] && 0 === url_to_postid( $direct_abs ) ) {
					$broken_dest[] = array(
						'source'   => $source,
						'terminal' => $walk['terminal'],
					);
				}
			}
		}

		// --- Pattern-rule analysis (self-loop heuristic + validity) ---
		$pattern_rows    = array();
		$pattern_loops   = 0;
		$pattern_invalid = 0;

		foreach ( $patterns as $rule ) {
			$valid            = ( null !== self::compile_pattern( $rule->source_path, $rule->match_type ) );
			$self_loop        = $valid && self::pattern_self_loops( $rule );
			$pattern_invalid += $valid ? 0 : 1;
			$pattern_loops   += $self_loop ? 1 : 0;

			$pattern_rows[] = array(
				'source'     => $rule->source_path,
				'match_type' => $rule->match_type,
				'target'     => $rule->target_url,
				'exceptions' => self::count_exceptions( isset( $rule->exceptions ) ? $rule->exceptions : '' ),
				'valid'      => $valid,
				'self_loop'  => $self_loop,
			);
		}

		return array(
			'summary'     => array(
				'total'           => count( $all ),
				'exact'           => count( $exact ),
				'patterns'        => count( $patterns ),
				'inactive'        => $inactive,
				'auto'            => $auto,
				'loops'           => count( $loops ),
				'chains'          => count( $chains ),
				'connected'       => count( $connected ),
				'pattern_loops'   => $pattern_loops,
				'pattern_invalid' => $pattern_invalid,
			),
			'loops'       => $loops,
			'chains'      => $chains,
			'connected'   => $connected,
			'broken_dest' => $broken_dest,
			'patterns'    => $pattern_rows,
		);
	}

	/**
	 * Count the non-empty lines in an exceptions list.
	 *
	 * @param string $exceptions Raw exceptions text.
	 * @return int
	 */
	protected static function count_exceptions( $exceptions ) {
		$exceptions = (string) $exceptions;
		if ( '' === trim( $exceptions ) ) {
			return 0;
		}
		return count( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $exceptions ) ) ) );
	}

	/**
	 * Heuristic: would a pattern's own output feed back into its source (a loop)?
	 * Builds a concrete target by filling captures with sample values and checks
	 * whether the source pattern re-matches it. Only same-host outputs can loop.
	 *
	 * @param object $rule Pattern rule row.
	 * @return bool
	 */
	protected static function pattern_self_loops( $rule ) {
		$regex = self::compile_pattern( $rule->source_path, $rule->match_type );
		if ( null === $regex ) {
			return false;
		}
		$template = ( 'wildcard' === $rule->match_type )
			? str_replace( '*', '{LGX}', (string) $rule->target_url )
			: (string) preg_replace( '/\$\{?\d+\}?|\\\\\d+/', '{LGX}', (string) $rule->target_url );

		foreach ( array( 'lg-sample', '1', 'a' ) as $sample ) {
			$abs = self::absolute_target( str_replace( '{LGX}', $sample, $template ) );
			if ( self::is_same_host( $abs ) && self::bounded_match( $regex, self::normalize_path( $abs ) ) ) {
				return true;
			}
		}
		return false;
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
