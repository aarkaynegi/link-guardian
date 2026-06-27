<?php
/**
 * Main plugin orchestrator. Wires the moving parts together.
 *
 * @package LinkGuardian
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Link_Guardian
 */
class Link_Guardian {

	/**
	 * Redirect store + front-end handler.
	 *
	 * @var Link_Guardian_Redirects
	 */
	protected $redirects;

	/**
	 * Slug-change watcher.
	 *
	 * @var Link_Guardian_Slug_Watcher
	 */
	protected $watcher;

	/**
	 * Broken-link scanner.
	 *
	 * @var Link_Guardian_Scanner
	 */
	protected $scanner;

	/**
	 * Admin UI.
	 *
	 * @var Link_Guardian_Admin
	 */
	protected $admin;

	/**
	 * REST API.
	 *
	 * @var Link_Guardian_REST
	 */
	protected $rest;

	/**
	 * Build and boot the plugin.
	 */
	public function __construct() {
		$this->redirects = new Link_Guardian_Redirects();
		$this->watcher   = new Link_Guardian_Slug_Watcher( $this->redirects );
		$this->scanner   = new Link_Guardian_Scanner();
		$this->rest      = new Link_Guardian_REST( $this->redirects );
		$this->admin     = new Link_Guardian_Admin( $this->redirects, $this->scanner );

		$this->init();
	}

	/**
	 * Register hooks across the components.
	 *
	 * @return void
	 */
	protected function init() {
		// Translations for plugins hosted on WordPress.org load automatically
		// (since WP 4.6), so no load_plugin_textdomain() call is needed.
		$this->redirects->init();
		$this->watcher->init();
		$this->scanner->init();
		$this->rest->init();

		if ( is_admin() ) {
			$this->admin->init();
		}
	}

	/**
	 * Accessor: redirect store.
	 *
	 * @return Link_Guardian_Redirects
	 */
	public function redirects() {
		return $this->redirects;
	}

	/**
	 * Accessor: scanner.
	 *
	 * @return Link_Guardian_Scanner
	 */
	public function scanner() {
		return $this->scanner;
	}
}
