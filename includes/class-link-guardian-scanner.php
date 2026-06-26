<?php
/**
 * Broken internal-link scanner.
 *
 * Walks published content in batches (driven by AJAX), extracts internal
 * links, and flags the ones that no longer resolve. Results stream back to
 * the admin UI with a "create redirect" shortcut for each broken link.
 *
 * @package LinkGuardian
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Link_Guardian_Scanner
 */
class Link_Guardian_Scanner {

	/**
	 * Transient key prefix for per-URL probe results.
	 */
	const CACHE_PREFIX = 'lg_scan_';

	/**
	 * Remaining HTTP probes allowed for the current batch.
	 *
	 * @var int
	 */
	protected $http_budget = 0;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_ajax_lg_scan_batch', array( $this, 'ajax_scan_batch' ) );
	}

	/**
	 * Post types worth scanning (public + has content).
	 *
	 * @return string[]
	 */
	protected function scannable_post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );

		/**
		 * Filter the post types scanned for broken internal links.
		 *
		 * @param string[] $types Post type slugs.
		 */
		$types = (array) apply_filters( 'link_guardian_scannable_post_types', array_values( $types ) );

		// Never let a filter empty the list down to malformed SQL; fall back to 'post'.
		return ! empty( $types ) ? array_values( $types ) : array( 'post' );
	}

	/**
	 * Count posts that will be scanned.
	 *
	 * @return int
	 */
	public function count_scannable() {
		global $wpdb;

		$types = $this->scannable_post_types();
		if ( empty( $types ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// Table name and the placeholder list are code-controlled; every post type
		// value is bound through prepare().
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_status = 'publish' AND post_type IN ( {$placeholders} )",
			$types
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * AJAX entry point for one batch.
	 *
	 * @return void
	 */
	public function ajax_scan_batch() {
		check_ajax_referer( 'lg_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'link-guardian' ) ), 403 );
		}

		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$limit  = isset( $_POST['limit'] ) ? min( 50, max( 1, (int) $_POST['limit'] ) ) : 20;

		$total  = $this->count_scannable();
		$result = $this->scan_batch( $offset, $limit );

		// Terminate authoritatively when a batch returns no rows, even if the raw
		// COUNT and the (filterable) WP_Query set disagree — prevents a runaway
		// loop that never advances.
		$processed = $offset + $result['processed'];
		$done      = ( 0 === $result['processed'] ) || $processed >= $total;

		wp_send_json_success(
			array(
				'total'     => $total,
				'processed' => $processed,
				'broken'    => $result['broken'],
				'done'      => $done,
			)
		);
	}

	/**
	 * Scan one batch of posts.
	 *
	 * @param int $offset Offset into the post list.
	 * @param int $limit  Batch size.
	 * @return array { @type int $processed, @type array $broken }
	 */
	public function scan_batch( $offset, $limit ) {
		$types = $this->scannable_post_types();
		if ( empty( $types ) ) {
			return array(
				'processed' => 0,
				'broken'    => array(),
			);
		}

		/**
		 * Maximum HTTP probes issued in a single batch, to cap self-targeted
		 * requests on link-heavy content.
		 *
		 * @param int $budget Default 300.
		 */
		$this->http_budget = (int) apply_filters( 'link_guardian_probe_budget', 300 );

		$query = new WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'posts_per_page'         => $limit,
				'offset'                 => $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'fields'                 => 'ids',
			)
		);

		$broken = array();

		foreach ( $query->posts as $post_id ) {
			$content = get_post_field( 'post_content', $post_id, 'raw' );
			if ( '' === $content ) {
				continue;
			}

			foreach ( $this->extract_internal_links( $content ) as $url ) {
				if ( $this->is_broken( $url ) ) {
					$broken[] = array(
						'post_id'    => (int) $post_id,
						'post_title' => get_the_title( $post_id ),
						'edit_link'  => get_edit_post_link( $post_id, 'raw' ),
						'url'        => $url,
						'path'       => Link_Guardian_Redirects::normalize_path( $url ),
					);
				}
			}
		}

		return array(
			'processed' => count( $query->posts ),
			'broken'    => $broken,
		);
	}

	/**
	 * Pull internal link URLs out of raw post content.
	 *
	 * @param string $content Raw post content.
	 * @return string[] Unique internal URLs (capped per post).
	 */
	public function extract_internal_links( $content ) {
		if ( ! preg_match_all( '/<a\s[^>]*href\s*=\s*("|\')(.*?)\1/i', $content, $matches ) ) {
			return array();
		}

		$links = array();
		foreach ( $matches[2] as $href ) {
			$href = trim( html_entity_decode( $href, ENT_QUOTES ) );

			if ( '' === $href || '#' === $href[0] ) {
				continue;
			}
			if ( preg_match( '#^(mailto:|tel:|javascript:|data:|ftp:)#i', $href ) ) {
				continue;
			}
			if ( ! $this->is_internal( $href ) ) {
				continue;
			}

			$links[ $href ] = $href;
		}

		/**
		 * Maximum distinct links probed per post.
		 *
		 * @param int $max Default 100.
		 */
		$max = (int) apply_filters( 'link_guardian_max_links_per_post', 100 );

		return array_slice( array_values( $links ), 0, max( 1, $max ) );
	}

	/**
	 * Is this href internal to the current site (same scheme host AND port)?
	 *
	 * The port check matters: without it, http://example.com:6379/ would pass the
	 * host comparison and let the scanner probe an arbitrary internal service.
	 *
	 * @param string $href Link href.
	 * @return bool
	 */
	protected function is_internal( $href ) {
		$home = home_url();
		$host = wp_parse_url( $href, PHP_URL_HOST );

		if ( empty( $host ) ) {
			// Relative link: internal only if it actually points at a path.
			$path = (string) wp_parse_url( $href, PHP_URL_PATH );
			return '' !== ltrim( $path, '/' );
		}

		if ( strtolower( $host ) !== strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) ) ) {
			return false;
		}

		// Ports must match too — block same-host, alternate-port targets.
		return (int) wp_parse_url( $href, PHP_URL_PORT ) === (int) wp_parse_url( $home, PHP_URL_PORT );
	}

	/**
	 * Decide whether an internal URL is broken, cached per URL.
	 *
	 * @param string $url Internal URL or path.
	 * @return bool
	 */
	public function is_broken( $url ) {
		$absolute  = Link_Guardian_Redirects::absolute_target( $url );
		$cache_key = self::CACHE_PREFIX . md5( $absolute );

		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return ( '1' === (string) $cached );
		}

		$broken = $this->probe( $absolute );

		// null = "couldn't determine this run" (probe budget exhausted): treat as
		// not-broken for now and don't poison the cache.
		if ( null === $broken ) {
			return false;
		}

		set_transient( $cache_key, $broken ? '1' : '0', 10 * MINUTE_IN_SECONDS );

		return $broken;
	}

	/**
	 * Probe a single URL: cheap WP lookup first, then a single bounded request.
	 *
	 * @param string $absolute Absolute URL.
	 * @return bool|null True if broken, false if reachable, null if not probed.
	 */
	protected function probe( $absolute ) {
		// Fast path: does this resolve to a known post?
		if ( url_to_postid( $absolute ) > 0 ) {
			return false;
		}

		// An active redirect will rescue the link, so it isn't broken.
		$path = Link_Guardian_Redirects::normalize_path( $absolute );
		if ( link_guardian()->redirects()->get_active_by_source( $path ) ) {
			return false;
		}

		// HTTP fallback (covers terms, archives, custom routes, files) — bounded.
		if ( $this->http_budget <= 0 ) {
			return null;
		}
		--$this->http_budget;

		// redirection => 0 means we never follow a 3xx to another host, which
		// closes the open-redirect SSRF vector; a 3xx is < 400 so the link is
		// still correctly treated as reachable. TLS verification stays on.
		$args = array(
			'timeout'     => 3,
			'redirection' => 0,
			'user-agent'  => 'LinkGuardian/' . LINK_GUARDIAN_VERSION,
		);

		$response = wp_remote_head( $absolute, $args );
		if ( is_wp_error( $response ) ) {
			// Some servers reject HEAD; retry with GET before declaring it broken.
			$response = wp_remote_get( $absolute, $args );
		}
		if ( is_wp_error( $response ) ) {
			return true;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		return ( $code >= 400 || 0 === $code );
	}
}
