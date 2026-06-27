<?php
/**
 * REST API: inspect and audit the redirect graph in a single request.
 *
 * Routes (namespace link-guardian/v1):
 *   GET /redirects        List every redirect rule.
 *   GET /audit            Whole-graph health: loops, multi-hop chains, connected
 *                         links, and dead-end targets — all in one call.
 *   GET /trace?url=...    Follow one URL through the chain and show every hop.
 *
 * All routes require the `manage_options` capability.
 *
 * @package LinkGuardian
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Link_Guardian_REST
 */
class Link_Guardian_REST {

	const NAMESPACE = 'link-guardian/v1';

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
	 * Hook into the REST lifecycle.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/redirects',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_redirects' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/audit',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'audit' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/trace',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'trace' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'url' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $value ) {
							return is_string( $value ) && '' !== trim( $value );
						},
					),
				),
			)
		);
	}

	/**
	 * Capability gate for every route.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * GET /redirects
	 *
	 * @return WP_REST_Response
	 */
	public function list_redirects() {
		$rows  = $this->redirects->get_all();
		$items = array();

		foreach ( $rows as $row ) {
			$items[] = array(
				'id'            => (int) $row->id,
				'source_path'   => $row->source_path,
				'target_url'    => $row->target_url,
				'redirect_type' => (int) $row->redirect_type,
				'is_active'     => 1 === (int) $row->is_active,
				'is_auto'       => 1 === (int) $row->is_auto,
				'post_id'       => (int) $row->post_id,
				'hits'          => (int) $row->hits,
				'last_hit'      => $row->last_hit,
				'created_at'    => $row->created_at,
			);
		}

		return new WP_REST_Response(
			array(
				'count' => count( $items ),
				'items' => $items,
			),
			200
		);
	}

	/**
	 * GET /audit — the whole-graph health check.
	 *
	 * @return WP_REST_Response
	 */
	public function audit() {
		return new WP_REST_Response( $this->redirects->audit(), 200 );
	}

	/**
	 * GET /trace?url=... — follow a single URL through the chain.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function trace( WP_REST_Request $request ) {
		$url     = (string) $request->get_param( 'url' );
		$path    = Link_Guardian_Redirects::normalize_path( $url );
		$walk    = $this->redirects->walk_chain( $path );
		$pattern = false;

		// The front end falls back to wildcard/regex rules when no exact rule
		// matches, so trace must too — otherwise a pattern-covered URL would be
		// reported as not redirected.
		if ( ! $walk['matched'] ) {
			$pat = $this->redirects->resolve_pattern_chain( $path );
			if ( null !== $pat ) {
				$pattern          = true;
				$walk['matched']  = true;
				$walk['first_id'] = $pat['first_id'];
				$walk['type']     = $pat['type'];
				$walk['terminal'] = $pat['target'];
				$walk['external'] = ! Link_Guardian_Redirects::is_same_host( $pat['target'] );
				$walk['hops']     = array( $path, $pat['target'] );
			}
		}

		return new WP_REST_Response(
			array(
				'input'    => $url,
				'path'     => $path,
				'matched'  => (bool) $walk['matched'],
				'pattern'  => $pattern,
				'loop'     => (bool) $walk['loop'],
				'external' => (bool) $walk['external'],
				'hops'     => $walk['hops'],
				'terminal' => $walk['terminal'],
				'status'   => (int) $walk['type'],
			),
			200
		);
	}
}
