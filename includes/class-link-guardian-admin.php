<?php
/**
 * Admin UI: redirect manager, broken-link scanner page, and settings.
 *
 * @package LinkGuardian
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Link_Guardian_Admin
 */
class Link_Guardian_Admin {

	const MENU_SLUG     = 'link-guardian';
	const SETTINGS_KEY  = 'link_guardian_settings';
	const SETTINGS_PAGE = 'link_guardian_settings_group';

	/**
	 * Redirect store.
	 *
	 * @var Link_Guardian_Redirects
	 */
	protected $redirects;

	/**
	 * Scanner.
	 *
	 * @var Link_Guardian_Scanner
	 */
	protected $scanner;

	/**
	 * Hook suffixes for our screens (used to scope asset loading).
	 *
	 * @var array
	 */
	protected $screens = array();

	/**
	 * Constructor.
	 *
	 * @param Link_Guardian_Redirects $redirects Redirect store.
	 * @param Link_Guardian_Scanner   $scanner   Scanner.
	 */
	public function __construct( Link_Guardian_Redirects $redirects, Link_Guardian_Scanner $scanner ) {
		$this->redirects = $redirects;
		$this->scanner   = $scanner;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Form handlers (Post/Redirect/Get).
		add_action( 'admin_post_lg_add_redirect', array( $this, 'handle_add_redirect' ) );
		add_action( 'admin_post_lg_delete_redirect', array( $this, 'handle_delete_redirect' ) );
		add_action( 'admin_post_lg_toggle_redirect', array( $this, 'handle_toggle_redirect' ) );
		add_action( 'admin_post_lg_update_redirect', array( $this, 'handle_update_redirect' ) );

		add_filter( 'plugin_action_links_' . LINK_GUARDIAN_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	// --- Menu ---

	/**
	 * Register the admin menu + sub-pages.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->screens[] = add_menu_page(
			__( 'Link Guardian', 'link-guardian' ),
			__( 'Link Guardian', 'link-guardian' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_redirects_page' ),
			'dashicons-admin-links',
			72
		);

		$this->screens[] = add_submenu_page(
			self::MENU_SLUG,
			__( 'Redirects', 'link-guardian' ),
			__( 'Redirects', 'link-guardian' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_redirects_page' )
		);

		$this->screens[] = add_submenu_page(
			self::MENU_SLUG,
			__( 'Broken Links', 'link-guardian' ),
			__( 'Broken Links', 'link-guardian' ),
			'manage_options',
			self::MENU_SLUG . '-scan',
			array( $this, 'render_scanner_page' )
		);

		$this->screens[] = add_submenu_page(
			self::MENU_SLUG,
			__( 'Audit', 'link-guardian' ),
			__( 'Audit', 'link-guardian' ),
			'manage_options',
			self::MENU_SLUG . '-audit',
			array( $this, 'render_audit_page' )
		);

		$this->screens[] = add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'link-guardian' ),
			__( 'Settings', 'link-guardian' ),
			'manage_options',
			self::MENU_SLUG . '-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Add a quick "Settings" link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '-settings' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'link-guardian' ) . '</a>' );
		return $links;
	}

	// --- Assets ---

	/**
	 * Enqueue CSS/JS only on our screens.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, $this->screens, true ) ) {
			return;
		}

		wp_enqueue_style(
			'link-guardian-admin',
			LINK_GUARDIAN_URL . 'assets/admin.css',
			array(),
			LINK_GUARDIAN_VERSION
		);

		wp_enqueue_script(
			'link-guardian-admin',
			LINK_GUARDIAN_URL . 'assets/admin.js',
			array( 'jquery' ),
			LINK_GUARDIAN_VERSION,
			true
		);

		wp_localize_script(
			'link-guardian-admin',
			'LinkGuardian',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'lg_admin' ),
				'redirectsUrl' => admin_url( 'admin.php?page=' . self::MENU_SLUG ),
				'restUrl'      => esc_url_raw( trailingslashit( rest_url( Link_Guardian_REST::NAMESPACE ) ) ),
				'restNonce'    => wp_create_nonce( 'wp_rest' ),
				'i18n'         => array(
					'scanning' => __( 'Scanning…', 'link-guardian' ),
					'done'     => __( 'Scan complete.', 'link-guardian' ),
					'noBroken' => __( 'No broken internal links found. ', 'link-guardian' ),
					'foundOne' => __( 'broken link found so far…', 'link-guardian' ),
					'error'    => __( 'Something went wrong during the scan.', 'link-guardian' ),
					'fixLabel' => __( 'Create redirect', 'link-guardian' ),
					'auditing' => __( 'Analysing redirects…', 'link-guardian' ),
					'auditErr' => __( 'Could not load the audit.', 'link-guardian' ),
					'allClear' => __( 'All clear — no loops, chains, or dead ends found.', 'link-guardian' ),
					'loopsHd'  => __( 'Redirect loops (blocked at serve time)', 'link-guardian' ),
					'chainsHd' => __( 'Multi-hop chains (auto-collapsed to one hop)', 'link-guardian' ),
					'connHd'   => __( 'Connected links (a target is itself a redirect)', 'link-guardian' ),
					'deadHd'   => __( 'Dead-end targets (resolve to no known post)', 'link-guardian' ),
				),
			)
		);
	}

	// --- Settings API ---

	/**
	 * Register settings, sections and fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::SETTINGS_PAGE,
			self::SETTINGS_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(
					'auto_redirect' => 1,
					'auto_rewrite'  => 1,
					'redirect_type' => 301,
				),
			)
		);

		add_settings_section(
			'lg_main',
			__( 'Automatic behaviour', 'link-guardian' ),
			function () {
				echo '<p>' . esc_html__( 'Control what Link Guardian does when you change a post or page slug.', 'link-guardian' ) . '</p>';
			},
			self::SETTINGS_PAGE
		);

		add_settings_field(
			'auto_redirect',
			__( 'Auto-create redirects', 'link-guardian' ),
			array( $this, 'field_checkbox' ),
			self::SETTINGS_PAGE,
			'lg_main',
			array(
				'key'   => 'auto_redirect',
				'label' => __( 'Create a redirect from the old URL to the new one when a slug changes.', 'link-guardian' ),
			)
		);

		add_settings_field(
			'auto_rewrite',
			__( 'Auto-fix internal links', 'link-guardian' ),
			array( $this, 'field_checkbox' ),
			self::SETTINGS_PAGE,
			'lg_main',
			array(
				'key'   => 'auto_rewrite',
				'label' => __( 'Rewrite links inside your other content to point at the new URL.', 'link-guardian' ),
			)
		);

		add_settings_field(
			'redirect_type',
			__( 'Redirect type', 'link-guardian' ),
			array( $this, 'field_redirect_type' ),
			self::SETTINGS_PAGE,
			'lg_main'
		);
	}

	/**
	 * Sanitize the settings array.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();
		$type  = isset( $input['redirect_type'] ) ? (int) $input['redirect_type'] : 301;

		return array(
			'auto_redirect' => empty( $input['auto_redirect'] ) ? 0 : 1,
			'auto_rewrite'  => empty( $input['auto_rewrite'] ) ? 0 : 1,
			'redirect_type' => in_array( $type, array( 301, 302 ), true ) ? $type : 301,
		);
	}

	/**
	 * Render a single checkbox field.
	 *
	 * @param array $args Field args.
	 * @return void
	 */
	public function field_checkbox( $args ) {
		$settings = get_option( self::SETTINGS_KEY, array() );
		$checked  = ! empty( $settings[ $args['key'] ] );
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> %4$s</label>',
			esc_attr( self::SETTINGS_KEY ),
			esc_attr( $args['key'] ),
			checked( $checked, true, false ),
			esc_html( $args['label'] )
		);
	}

	/**
	 * Render the redirect-type select.
	 *
	 * @return void
	 */
	public function field_redirect_type() {
		$settings = get_option( self::SETTINGS_KEY, array() );
		$current  = isset( $settings['redirect_type'] ) ? (int) $settings['redirect_type'] : 301;
		?>
		<select name="<?php echo esc_attr( self::SETTINGS_KEY ); ?>[redirect_type]">
			<option value="301" <?php selected( $current, 301 ); ?>><?php esc_html_e( '301 — Permanent (recommended)', 'link-guardian' ); ?></option>
			<option value="302" <?php selected( $current, 302 ); ?>><?php esc_html_e( '302 — Temporary', 'link-guardian' ); ?></option>
		</select>
		<?php
	}

	// --- Form handlers ---

	/**
	 * Handle the "add redirect" form.
	 *
	 * @return void
	 */
	public function handle_add_redirect() {
		check_admin_referer( 'lg_add_redirect' );
		$this->require_cap();

		$source     = isset( $_POST['source_path'] ) ? sanitize_text_field( wp_unslash( $_POST['source_path'] ) ) : '';
		$target     = isset( $_POST['target_url'] ) ? sanitize_text_field( wp_unslash( $_POST['target_url'] ) ) : '';
		$type       = isset( $_POST['redirect_type'] ) ? (int) $_POST['redirect_type'] : 301;
		$match_type = isset( $_POST['match_type'] ) ? Link_Guardian_Redirects::sanitize_match_type( sanitize_key( wp_unslash( $_POST['match_type'] ) ) ) : 'exact';
		$exceptions = isset( $_POST['exceptions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['exceptions'] ) ) : '';

		// Loop prevention applies to exact rules; pattern rules are guarded at serve time.
		if ( 'exact' === $match_type && '' !== $source && '' !== $target && $this->redirects->would_create_cycle( $source, $target ) ) {
			$this->redirect_back( 'loop' );
		}

		$id = $this->redirects->upsert(
			array(
				'source_path'   => $source,
				'target_url'    => $target,
				'match_type'    => $match_type,
				'exceptions'    => $exceptions,
				'redirect_type' => $type,
				'is_active'     => 1,
				'is_auto'       => 0,
			)
		);

		$this->redirect_back( $id ? 'added' : 'error' );
	}

	/**
	 * Handle deletion.
	 *
	 * @return void
	 */
	public function handle_delete_redirect() {
		check_admin_referer( 'lg_delete_redirect' );
		$this->require_cap();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$this->redirects->delete( $id );
		$this->redirect_back( 'deleted' );
	}

	/**
	 * Handle active/inactive toggle.
	 *
	 * @return void
	 */
	public function handle_toggle_redirect() {
		check_admin_referer( 'lg_toggle_redirect' );
		$this->require_cap();
		$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$active = isset( $_POST['active'] ) ? (int) $_POST['active'] : 0;
		$this->redirects->set_active( $id, $active );
		$this->redirect_back( 'updated' );
	}

	/**
	 * Handle the "edit redirect" form — updates an existing rule's target + type.
	 *
	 * @return void
	 */
	public function handle_update_redirect() {
		check_admin_referer( 'lg_update_redirect' );
		$this->require_cap();

		$id         = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$target     = isset( $_POST['target_url'] ) ? sanitize_text_field( wp_unslash( $_POST['target_url'] ) ) : '';
		$type       = isset( $_POST['redirect_type'] ) ? (int) $_POST['redirect_type'] : 301;
		$exceptions = isset( $_POST['exceptions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['exceptions'] ) ) : null;

		$row = $id ? $this->redirects->get( $id ) : null;
		if ( ! $row ) {
			$this->redirect_back( 'error' );
		}

		// Loop protection on edits (exact rules only; patterns are guarded at serve time).
		if ( 'exact' === $row->match_type && '' !== $target && $this->redirects->would_create_cycle( $row->source_path, $target ) ) {
			$this->redirect_back( 'loop' );
		}

		$ok = $this->redirects->update_target( $id, $target, $type, $exceptions );
		$this->redirect_back( $ok ? 'updated' : 'error' );
	}

	/**
	 * Capability guard for form handlers. Each handler verifies its own nonce
	 * via check_admin_referer() before any request data is read.
	 *
	 * @return void
	 */
	protected function require_cap() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'link-guardian' ), 403 );
		}
	}

	/**
	 * Redirect back to the redirects screen with a status notice.
	 *
	 * @param string $status Status slug.
	 * @return void
	 */
	protected function redirect_back( $status ) {
		$url = add_query_arg(
			array(
				'page'   => self::MENU_SLUG,
				'lg_msg' => $status,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Print an admin notice from the lg_msg query arg.
	 *
	 * @return void
	 */
	protected function maybe_notice() {
		$msg = isset( $_GET['lg_msg'] ) ? sanitize_key( wp_unslash( $_GET['lg_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $msg ) {
			return;
		}

		$map = array(
			'added'   => array( 'success', __( 'Redirect added.', 'link-guardian' ) ),
			'deleted' => array( 'success', __( 'Redirect deleted.', 'link-guardian' ) ),
			'updated' => array( 'success', __( 'Redirect updated.', 'link-guardian' ) ),
			'error'   => array( 'error', __( 'Could not save the redirect. Check the source and target.', 'link-guardian' ) ),
			'loop'    => array( 'error', __( 'That redirect would create a loop, so it was not saved.', 'link-guardian' ) ),
		);

		if ( ! isset( $map[ $msg ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $map[ $msg ][0] ),
			esc_html( $map[ $msg ][1] )
		);
	}

	// --- Page renderers ---

	/**
	 * Redirects manager page.
	 *
	 * @return void
	 */
	public function render_redirects_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$paged   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$prefill = isset( $_GET['lg_prefill_source'] ) ? sanitize_text_field( wp_unslash( $_GET['lg_prefill_source'] ) ) : '';
		$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
		$msg     = isset( $_GET['lg_msg'] ) ? sanitize_key( wp_unslash( $_GET['lg_msg'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$edit_row = $edit_id ? $this->redirects->get( $edit_id ) : null;

		// Reveal the add/edit panel automatically when editing, after a failed
		// submission, or when prefilled from the broken-link scanner.
		$panel_open = $edit_row || '' !== $prefill || in_array( $msg, array( 'loop', 'error' ), true );

		$per_page    = 20;
		$data        = $this->redirects->query(
			array(
				'per_page' => $per_page,
				'page'     => $paged,
				'search'   => $search,
			)
		);
		$total_pages = (int) ceil( $data['total'] / $per_page );
		?>
		<div class="wrap link-guardian-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Link Guardian — Redirects', 'link-guardian' ); ?></h1>
			<button type="button" class="page-title-action lg-add-toggle" aria-controls="lg-add-panel" aria-expanded="<?php echo $panel_open ? 'true' : 'false'; ?>"><?php esc_html_e( 'Add Redirect', 'link-guardian' ); ?></button>
			<hr class="wp-header-end">
			<?php $this->maybe_notice(); ?>

			<div class="lg-card lg-add-panel" id="lg-add-panel"<?php echo $panel_open ? '' : ' hidden'; ?>>
				<?php if ( $edit_row ) : ?>
					<h2 id="lg-edit"><?php esc_html_e( 'Edit redirect', 'link-guardian' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="lg_update_redirect">
						<input type="hidden" name="id" value="<?php echo (int) $edit_row->id; ?>">
						<?php wp_nonce_field( 'lg_update_redirect' ); ?>
						<div class="lg-form-row">
							<div class="lg-field lg-field--type">
								<label for="lg-edit-match"><?php esc_html_e( 'Match', 'link-guardian' ); ?></label>
								<input type="text" id="lg-edit-match" value="<?php echo esc_attr( ucfirst( $edit_row->match_type ) ); ?>" readonly>
							</div>
							<div class="lg-field">
								<label for="lg-edit-source"><?php esc_html_e( 'Source', 'link-guardian' ); ?></label>
								<input type="text" id="lg-edit-source" value="<?php echo esc_attr( $edit_row->source_path ); ?>" readonly>
							</div>
							<div class="lg-field">
								<label for="lg-target"><?php esc_html_e( 'Target', 'link-guardian' ); ?></label>
								<input type="text" id="lg-target" name="target_url" value="<?php echo esc_attr( $edit_row->target_url ); ?>" required>
							</div>
							<div class="lg-field lg-field--type">
								<label for="lg-type"><?php esc_html_e( 'Type', 'link-guardian' ); ?></label>
								<select id="lg-type" name="redirect_type">
									<option value="301" <?php selected( (int) $edit_row->redirect_type, 301 ); ?>><?php esc_html_e( '301 — Permanent', 'link-guardian' ); ?></option>
									<option value="302" <?php selected( (int) $edit_row->redirect_type, 302 ); ?>><?php esc_html_e( '302 — Temporary', 'link-guardian' ); ?></option>
								</select>
							</div>
						</div>
						<?php if ( 'exact' !== $edit_row->match_type ) : ?>
							<div class="lg-exceptions-row">
								<label for="lg-edit-exceptions"><?php esc_html_e( 'Exceptions — paths to skip (one per line, * allowed)', 'link-guardian' ); ?></label>
								<textarea id="lg-edit-exceptions" name="exceptions" rows="3" class="large-text"><?php echo esc_textarea( (string) $edit_row->exceptions ); ?></textarea>
							</div>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'The source is the rule’s identity. To change it, delete this rule and add a new one.', 'link-guardian' ); ?></p>
						<p>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Update redirect', 'link-guardian' ); ?></button>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Cancel', 'link-guardian' ); ?></a>
						</p>
					</form>
				<?php else : ?>
					<h2><?php esc_html_e( 'Add a redirect', 'link-guardian' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="lg_add_redirect">
						<?php wp_nonce_field( 'lg_add_redirect' ); ?>
						<div class="lg-form-row">
							<div class="lg-field lg-field--type">
								<label for="lg-match-type"><?php esc_html_e( 'Match', 'link-guardian' ); ?></label>
								<select id="lg-match-type" name="match_type" class="lg-match-type">
									<option value="exact"><?php esc_html_e( 'Exact', 'link-guardian' ); ?></option>
									<option value="wildcard"><?php esc_html_e( 'Wildcard', 'link-guardian' ); ?></option>
									<option value="regex"><?php esc_html_e( 'Regex', 'link-guardian' ); ?></option>
								</select>
							</div>
							<div class="lg-field">
								<label for="lg-source"><?php esc_html_e( 'Source', 'link-guardian' ); ?></label>
								<input type="text" id="lg-source" name="source_path" class="lg-source-input" placeholder="/old-url" value="<?php echo esc_attr( $prefill ); ?>" required>
							</div>
							<div class="lg-field">
								<label for="lg-target"><?php esc_html_e( 'Target', 'link-guardian' ); ?></label>
								<input type="text" id="lg-target" name="target_url" class="lg-target-input" placeholder="/new-url or https://example.com/page" required>
							</div>
							<div class="lg-field lg-field--type">
								<label for="lg-type"><?php esc_html_e( 'Type', 'link-guardian' ); ?></label>
								<select id="lg-type" name="redirect_type">
									<option value="301"><?php esc_html_e( '301 — Permanent', 'link-guardian' ); ?></option>
									<option value="302"><?php esc_html_e( '302 — Temporary', 'link-guardian' ); ?></option>
								</select>
							</div>
						</div>
						<p class="description lg-match-help"></p>
						<div class="lg-exceptions-row" hidden>
							<label for="lg-exceptions"><?php esc_html_e( 'Exceptions — paths to skip (one per line, * allowed)', 'link-guardian' ); ?></label>
							<textarea id="lg-exceptions" name="exceptions" rows="3" class="large-text" placeholder="/blog/keep-this&#10;/blog/legacy-*"></textarea>
						</div>
						<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Add redirect', 'link-guardian' ); ?></button></p>
					</form>
				<?php endif; ?>
			</div>

			<div class="lg-card lg-table-card">
				<form method="get" class="lg-search">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
					<label class="screen-reader-text" for="lg-search-input"><?php esc_html_e( 'Search redirects', 'link-guardian' ); ?></label>
					<input type="search" id="lg-search-input" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search redirects…', 'link-guardian' ); ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Search', 'link-guardian' ); ?></button>
				</form>

				<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Source', 'link-guardian' ); ?></th>
								<th><?php esc_html_e( 'Match', 'link-guardian' ); ?></th>
								<th><?php esc_html_e( 'Target', 'link-guardian' ); ?></th>
								<th><?php esc_html_e( 'Type', 'link-guardian' ); ?></th>
								<th><?php esc_html_e( 'Origin', 'link-guardian' ); ?></th>
								<th><?php esc_html_e( 'Hits', 'link-guardian' ); ?></th>
								<th><?php esc_html_e( 'Status', 'link-guardian' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'link-guardian' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php if ( empty( $data['items'] ) ) : ?>
							<tr><td colspan="8"><?php esc_html_e( 'No redirects yet. Change a published slug and one will appear here automatically.', 'link-guardian' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $data['items'] as $row ) : ?>
								<tr>
									<td><code><?php echo esc_html( $row->source_path ); ?></code></td>
									<td><span class="lg-badge<?php echo 'exact' === $row->match_type ? '' : ' lg-badge--pattern'; ?>"><?php echo esc_html( ucfirst( $row->match_type ) ); ?></span></td>
									<td><code><?php echo esc_html( $row->target_url ); ?></code></td>
									<td><?php echo (int) $row->redirect_type; ?></td>
									<td>
										<?php if ( 1 === (int) $row->is_auto ) : ?>
											<span class="lg-badge lg-badge--auto"><?php esc_html_e( 'Auto', 'link-guardian' ); ?></span>
										<?php else : ?>
											<span class="lg-badge"><?php esc_html_e( 'Manual', 'link-guardian' ); ?></span>
										<?php endif; ?>
									</td>
									<td><?php echo (int) $row->hits; ?></td>
									<td>
										<?php if ( 1 === (int) $row->is_active ) : ?>
											<span class="lg-dot lg-dot--on"></span><?php esc_html_e( 'Active', 'link-guardian' ); ?>
										<?php else : ?>
											<span class="lg-dot lg-dot--off"></span><?php esc_html_e( 'Paused', 'link-guardian' ); ?>
										<?php endif; ?>
									</td>
									<td class="lg-actions">
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&edit=' . (int) $row->id ) ); ?>#lg-edit"><?php esc_html_e( 'Edit', 'link-guardian' ); ?></a>
										&nbsp;|&nbsp;
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
											<input type="hidden" name="action" value="lg_toggle_redirect">
											<input type="hidden" name="id" value="<?php echo (int) $row->id; ?>">
											<input type="hidden" name="active" value="<?php echo 1 === (int) $row->is_active ? 0 : 1; ?>">
											<?php wp_nonce_field( 'lg_toggle_redirect' ); ?>
											<button type="submit" class="button-link">
												<?php echo 1 === (int) $row->is_active ? esc_html__( 'Pause', 'link-guardian' ) : esc_html__( 'Resume', 'link-guardian' ); ?>
											</button>
										</form>
										&nbsp;|&nbsp;
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this redirect?', 'link-guardian' ) ); ?>');">
											<input type="hidden" name="action" value="lg_delete_redirect">
											<input type="hidden" name="id" value="<?php echo (int) $row->id; ?>">
											<?php wp_nonce_field( 'lg_delete_redirect' ); ?>
											<button type="submit" class="button-link lg-link-danger"><?php esc_html_e( 'Delete', 'link-guardian' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>

					<?php if ( $total_pages > 1 ) : ?>
						<div class="tablenav"><div class="tablenav-pages">
							<?php
							echo wp_kses_post(
								paginate_links(
									array(
										'base'      => add_query_arg( 'paged', '%#%' ),
										'format'    => '',
										'current'   => $paged,
										'total'     => $total_pages,
										'prev_text' => '&laquo;',
										'next_text' => '&raquo;',
									)
								)
							);
							?>
						</div></div>
					<?php endif; ?>
				</div>
		</div>
		<?php
	}

	/**
	 * Broken-link scanner page.
	 *
	 * @return void
	 */
	public function render_scanner_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$total = $this->scanner->count_scannable();
		?>
		<div class="wrap link-guardian-wrap">
			<h1><?php esc_html_e( 'Link Guardian — Broken Internal Links', 'link-guardian' ); ?></h1>
			<p class="description">
				<?php
				printf(
					/* translators: %d: number of published posts/pages. */
					esc_html__( 'Scan all %d published items for internal links that no longer resolve.', 'link-guardian' ),
					(int) $total
				);
				?>
			</p>

			<p>
				<button type="button" class="button button-primary" id="lg-scan-start" data-total="<?php echo (int) $total; ?>">
					<?php esc_html_e( 'Scan now', 'link-guardian' ); ?>
				</button>
			</p>

			<div id="lg-scan-progress" class="lg-progress" hidden>
				<div class="lg-progress__bar"><span></span></div>
				<p class="lg-progress__label"></p>
			</div>

			<table class="wp-list-table widefat fixed striped" id="lg-scan-results" hidden>
				<thead>
					<tr>
						<th><?php esc_html_e( 'Found in', 'link-guardian' ); ?></th>
						<th><?php esc_html_e( 'Broken link', 'link-guardian' ); ?></th>
						<th><?php esc_html_e( 'Action', 'link-guardian' ); ?></th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Redirect audit page — consumes the REST API to show the whole graph at once.
	 *
	 * @return void
	 */
	public function render_audit_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap link-guardian-wrap">
			<h1><?php esc_html_e( 'Link Guardian — Redirect Audit', 'link-guardian' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Checks every redirect in one pass: loops, multi-hop chains, connected links, and dead-end targets. Powered by the Link Guardian REST API.', 'link-guardian' ); ?>
				<code>GET <?php echo esc_html( rest_url( Link_Guardian_REST::NAMESPACE . '/audit' ) ); ?></code>
			</p>

			<p>
				<button type="button" class="button button-primary" id="lg-audit-run"><?php esc_html_e( 'Run audit', 'link-guardian' ); ?></button>
			</p>

			<div id="lg-audit-summary" class="lg-summary" hidden></div>
			<div id="lg-audit-body"></div>
		</div>
		<?php
	}

	/**
	 * Settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap link-guardian-wrap">
			<h1><?php esc_html_e( 'Link Guardian — Settings', 'link-guardian' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::SETTINGS_PAGE );
				do_settings_sections( self::SETTINGS_PAGE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
