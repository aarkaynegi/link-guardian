<?php
/**
 * Handles activation: creates the redirects table and seeds default settings.
 *
 * @package LinkGuardian
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Link_Guardian_Activator
 */
class Link_Guardian_Activator {

	/**
	 * Return the fully-qualified redirects table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'lg_redirects';
	}

	/**
	 * Run on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_table();
		self::seed_settings();
		update_option( 'link_guardian_db_version', LINK_GUARDIAN_DB_VERSION );

		// Make sure pretty-permalink rewrite is flushed so our redirect handler is reliable.
		flush_rewrite_rules();
	}

	/**
	 * Run on deactivation. We intentionally keep data; full removal happens in uninstall.php.
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Create (or upgrade) the redirects table using dbDelta.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta is picky about formatting: two spaces after PRIMARY KEY, lowercase "key", etc.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_path varchar(190) NOT NULL,
			target_url text NOT NULL,
			match_type varchar(10) NOT NULL DEFAULT 'exact',
			exceptions text NULL,
			redirect_type smallint(5) unsigned NOT NULL DEFAULT 301,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			is_auto tinyint(1) NOT NULL DEFAULT 0,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			last_hit datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY source_path (source_path),
			KEY post_id (post_id),
			KEY match_type (match_type)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Run pending schema upgrades for already-active installs (called on load).
	 *
	 * The dbDelta() routine is idempotent and adds any missing columns/keys, so
	 * we simply re-run it when the stored DB version is behind the code version.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( (string) get_option( 'link_guardian_db_version' ) === (string) LINK_GUARDIAN_DB_VERSION ) {
			return;
		}
		self::create_table();
		update_option( 'link_guardian_db_version', LINK_GUARDIAN_DB_VERSION );
	}

	/**
	 * Seed default settings only if they do not already exist.
	 *
	 * @return void
	 */
	public static function seed_settings() {
		if ( false === get_option( 'link_guardian_settings', false ) ) {
			add_option(
				'link_guardian_settings',
				array(
					'auto_redirect' => 1,
					'auto_rewrite'  => 1,
					'redirect_type' => 301,
				)
			);
		}
	}
}
