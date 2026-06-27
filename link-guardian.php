<?php
/**
 * Plugin Name:       Link Guardian
 * Plugin URI:        https://github.com/aarkaynegi/link-guardian
 * Description:       Keeps your internal links healthy. When you change a post or page slug, Link Guardian automatically creates a 301 redirect <em>and</em> rewrites the old links inside your other content. It also scans your whole site for broken internal links.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            aarkaynegi
 * Author URI:        https://github.com/aarkaynegi
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       link-guardian
 * Domain Path:       /languages
 *
 * @package LinkGuardian
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

define( 'LINK_GUARDIAN_VERSION', '1.0.0' );
define( 'LINK_GUARDIAN_DB_VERSION', '1' );
define( 'LINK_GUARDIAN_FILE', __FILE__ );
define( 'LINK_GUARDIAN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LINK_GUARDIAN_URL', plugin_dir_url( __FILE__ ) );
define( 'LINK_GUARDIAN_BASENAME', plugin_basename( __FILE__ ) );

require_once LINK_GUARDIAN_DIR . 'includes/class-link-guardian-activator.php';
require_once LINK_GUARDIAN_DIR . 'includes/class-link-guardian-redirects.php';
require_once LINK_GUARDIAN_DIR . 'includes/class-link-guardian-slug-watcher.php';
require_once LINK_GUARDIAN_DIR . 'includes/class-link-guardian-scanner.php';
require_once LINK_GUARDIAN_DIR . 'includes/class-link-guardian-rest.php';
require_once LINK_GUARDIAN_DIR . 'includes/class-link-guardian-admin.php';
require_once LINK_GUARDIAN_DIR . 'includes/class-link-guardian.php';

// Activation / deactivation.
register_activation_hook( __FILE__, array( 'Link_Guardian_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Link_Guardian_Activator', 'deactivate' ) );

/**
 * Boot the plugin once all plugins are loaded.
 *
 * @return Link_Guardian
 */
function link_guardian() {
	static $instance = null;
	if ( null === $instance ) {
		$instance = new Link_Guardian();
	}
	return $instance;
}
add_action( 'plugins_loaded', 'link_guardian' );
