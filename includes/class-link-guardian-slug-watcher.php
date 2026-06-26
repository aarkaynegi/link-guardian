<?php
/**
 * Watches for slug/permalink changes and reacts:
 *   1. Creates an automatic 301 redirect old URL -> new URL.
 *   2. Rewrites the old link inside every other piece of content (async).
 *
 * This is the feature that sets Link Guardian apart from redirect-only tools.
 *
 * @package LinkGuardian
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Link_Guardian_Slug_Watcher
 */
class Link_Guardian_Slug_Watcher {

	/**
	 * Cron hook that performs the cross-content link rewrite in the background.
	 */
	const REWRITE_HOOK = 'link_guardian_rewrite_batch';

	/**
	 * Posts processed per background pass.
	 */
	const REWRITE_BATCH = 50;

	/**
	 * Redirect store.
	 *
	 * @var Link_Guardian_Redirects
	 */
	protected $redirects;

	/**
	 * Constructor.
	 *
	 * @param Link_Guardian_Redirects $redirects Redirect store.
	 */
	public function __construct( Link_Guardian_Redirects $redirects ) {
		$this->redirects = $redirects;
	}

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'post_updated', array( $this, 'on_post_updated' ), 10, 3 );
		add_action( self::REWRITE_HOOK, array( $this, 'run_rewrite_batch' ), 10, 4 );
	}

	/**
	 * Read a boolean setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Default value.
	 * @return bool
	 */
	protected function setting( $key, $fallback = 1 ) {
		$settings = get_option( 'link_guardian_settings', array() );
		$value    = isset( $settings[ $key ] ) ? $settings[ $key ] : $fallback;
		return (bool) $value;
	}

	/**
	 * Fired whenever a post is updated.
	 *
	 * @param int     $post_id     Post id.
	 * @param WP_Post $post_after  Post object after the update.
	 * @param WP_Post $post_before Post object before the update.
	 * @return void
	 */
	public function on_post_updated( $post_id, $post_after, $post_before ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Only public post types have meaningful public URLs.
		$type_object = get_post_type_object( $post_after->post_type );
		if ( ! $type_object || empty( $type_object->public ) ) {
			return;
		}

		// We only care once the OLD version was publicly live, and the new one stays public.
		if ( 'publish' !== $post_before->post_status || 'publish' !== $post_after->post_status ) {
			return;
		}

		// Did anything that affects the permalink actually change?
		$slug_changed   = $post_before->post_name !== $post_after->post_name;
		$parent_changed = (int) $post_before->post_parent !== (int) $post_after->post_parent;
		if ( ! $slug_changed && ! $parent_changed ) {
			return;
		}

		$old_url = get_permalink( $post_before );
		$new_url = get_permalink( $post_after );
		if ( ! $old_url || ! $new_url || $old_url === $new_url ) {
			return;
		}

		/**
		 * Fires before Link Guardian processes a permalink change.
		 *
		 * @param int    $post_id Post id.
		 * @param string $old_url Old permalink.
		 * @param string $new_url New permalink.
		 */
		do_action( 'link_guardian_before_slug_change', $post_id, $old_url, $new_url );

		if ( $this->setting( 'auto_redirect' ) ) {
			$this->create_redirect( $post_id, $old_url, $new_url );
		}

		// Cross-content rewriting edits other authors' posts, so require the
		// capability now (at request time, with the real user). Only genuine
		// system contexts (cron / WP-CLI) are exempt — "no current user" alone
		// is NOT treated as authorisation.
		$is_system   = wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
		$can_rewrite = $is_system || current_user_can( 'edit_others_posts' );

		/**
		 * Filter whether the current actor may trigger cross-content link rewriting.
		 *
		 * @param bool $can_rewrite Default permission decision.
		 * @param int  $post_id     Post id whose slug changed.
		 */
		$can_rewrite = (bool) apply_filters( 'link_guardian_can_rewrite_links', $can_rewrite, $post_id );

		if ( $this->setting( 'auto_rewrite' ) && $can_rewrite ) {
			// Offload the fan-out to cron so a heavily-linked page never hangs the
			// editor's save request (and can never leave a half-rewritten DB).
			wp_schedule_single_event( time() + 1, self::REWRITE_HOOK, array( (int) $post_id, $old_url, $new_url, 0 ) );
		}
	}

	/**
	 * Create / update the automatic redirect, without ever clobbering an
	 * admin-managed (manual) rule.
	 *
	 * @param int    $post_id Post id.
	 * @param string $old_url Old permalink.
	 * @param string $new_url New permalink.
	 * @return void
	 */
	protected function create_redirect( $post_id, $old_url, $new_url ) {
		$settings = get_option( 'link_guardian_settings', array() );
		$type     = isset( $settings['redirect_type'] ) ? (int) $settings['redirect_type'] : 301;
		$new_path = Link_Guardian_Redirects::normalize_path( $new_url );

		// Never overwrite a manual rule an admin created for this source.
		$existing = $this->redirects->get_by_source( $old_url );
		if ( $existing && 0 === (int) $existing->is_auto ) {
			return;
		}

		// The new URL is now a live page, so an AUTO rule must not redirect away
		// from it (this defuses the rename-back loop). Leave manual rules alone so
		// a low-privilege author can't delete an admin's redirect by renaming onto it.
		$at_new = $this->redirects->get_by_source( $new_path );
		if ( $at_new && 1 === (int) $at_new->is_auto ) {
			$this->redirects->delete_by_source( $new_path );
		}

		$this->redirects->upsert(
			array(
				'source_path'   => $old_url,
				'target_url'    => $new_path,
				'redirect_type' => $type,
				'is_active'     => 1,
				'is_auto'       => 1,
				'post_id'       => (int) $post_id,
			)
		);

		// Chain healing for AUTO rules only: anything that pointed at the OLD url
		// now points straight at the new path (avoids 301 -> 301 hops).
		$this->retarget_chains( $old_url, $new_path );
	}

	/**
	 * Re-point auto redirects whose target was the old URL to the new path.
	 *
	 * @param string $old_url  Old permalink.
	 * @param string $new_path New normalised path.
	 * @return void
	 */
	protected function retarget_chains( $old_url, $new_path ) {
		global $wpdb;
		$table    = Link_Guardian_Redirects::table();
		$old_path = Link_Guardian_Redirects::normalize_path( $old_url );

		foreach ( array_unique( array( $old_url, $old_path ) ) as $needle ) {
			// Table name is code-controlled; all values are bound through prepare().
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET target_url = %s WHERE target_url = %s AND is_auto = 1",
					$new_path,
					$needle
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Background worker: rewrite the old link to the new link across content.
	 *
	 * Walks the matching posts with an ID cursor (so it always terminates, even
	 * for matches that contain the URL as plain text and never get rewritten),
	 * processes one bounded batch, then re-schedules itself for the next slice.
	 *
	 * @param int    $post_id  The post whose slug changed (skipped).
	 * @param string $old_url  Old permalink.
	 * @param string $new_url  New permalink.
	 * @param int    $after_id ID cursor — only posts with a greater ID are processed.
	 * @return void
	 */
	public function run_rewrite_batch( $post_id, $old_url, $new_url, $after_id = 0 ) {
		global $wpdb;

		$old_path = Link_Guardian_Redirects::normalize_path( $old_url );
		$new_path = Link_Guardian_Redirects::normalize_path( $new_url );
		$like     = '%' . $wpdb->esc_like( $old_path ) . '%';

		$replacements = $this->build_replacements( $old_url, $new_url, $old_path, $new_path );

		// Table name is code-controlled; values are bound through prepare().
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_status IN ( 'publish', 'pending', 'draft', 'private', 'future' )
				   AND post_type NOT IN ( 'revision' )
				   AND ID <> %d
				   AND ID > %d
				   AND post_content LIKE %s
				 ORDER BY ID ASC
				 LIMIT %d",
				(int) $post_id,
				(int) $after_id,
				$like,
				self::REWRITE_BATCH
			)
		);

		if ( empty( $ids ) ) {
			/** This action is documented above in on_post_updated(). */
			do_action( 'link_guardian_links_rewritten', (int) $post_id, 0 );
			return;
		}

		$processed = 0;

		foreach ( $ids as $id ) {
			$content = get_post_field( 'post_content', $id, 'raw' );
			if ( '' === $content ) {
				continue;
			}

			$new_content = strtr( $content, $replacements );
			if ( $new_content === $content ) {
				continue;
			}

			// Direct write: a mechanical href swap must not re-run KSES on another
			// author's post (which could strip markup they can't re-save) nor fire
			// a full save cycle for every matched post.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->posts,
				array( 'post_content' => $new_content ),
				array( 'ID' => (int) $id ),
				array( '%s' ),
				array( '%d' )
			);
			clean_post_cache( (int) $id );
			++$processed;
		}

		do_action( 'link_guardian_links_rewritten', (int) $post_id, $processed );

		// A full batch means more rows may remain; continue from the last ID on a
		// fresh cron tick. The cursor strictly advances, so this always terminates.
		if ( count( $ids ) >= self::REWRITE_BATCH ) {
			$last = (int) end( $ids );
			wp_schedule_single_event( time() + 1, self::REWRITE_HOOK, array( (int) $post_id, $old_url, $new_url, $last ) );
		}
	}

	/**
	 * Build the quote-anchored search/replace map.
	 *
	 * Every key is a complete href attribute value, so a sibling URL that merely
	 * shares the old URL as a prefix (e.g. /about vs /a) can never be partially
	 * rewritten. Absolute and root-relative forms are both covered, with and
	 * without a trailing slash.
	 *
	 * @param string $old_url  Old absolute permalink.
	 * @param string $new_url  New absolute permalink.
	 * @param string $old_path Old normalised path.
	 * @param string $new_path New normalised path.
	 * @return array
	 */
	protected function build_replacements( $old_url, $new_url, $old_path, $new_path ) {
		$pairs = array();

		$url_variants = array( $old_url, untrailingslashit( $old_url ), trailingslashit( $old_url ) );
		foreach ( array_unique( $url_variants ) as $variant ) {
			$pairs[ 'href="' . $variant . '"' ] = 'href="' . $new_url . '"';
			$pairs[ "href='" . $variant . "'" ] = "href='" . $new_url . "'";
		}

		$path_variants = array( $old_path, untrailingslashit( $old_path ), trailingslashit( $old_path ) );
		foreach ( array_unique( $path_variants ) as $variant ) {
			if ( '' === $variant || '/' === $variant ) {
				continue;
			}
			$pairs[ 'href="' . $variant . '"' ] = 'href="' . $new_path . '"';
			$pairs[ "href='" . $variant . "'" ] = "href='" . $new_path . "'";
		}

		return $pairs;
	}
}
