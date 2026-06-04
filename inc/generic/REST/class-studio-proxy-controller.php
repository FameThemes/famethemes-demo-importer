<?php
/**
 * REST proxy that exposes the configured Studio's read endpoints to the
 * admin-side JS without leaking the API key to the browser. Routes live
 * under `ft-demo-importer/v1/` and are gated by `manage_options` + the
 * standard `X-WP-Nonce` REST nonce.
 *
 *   GET /studio/me                  → studio /me (test connection)
 *   GET /studio/templates           → studio /templates (filtered to active theme)
 *   GET /studio/templates/{id}      → studio /templates/{id} (manifest with asset URLs)
 *   GET /studio/categories          → studio /categories?type=template
 *
 * Job endpoints (POST /jobs, GET /jobs/{id}, POST /jobs/{id}/cancel) live
 * in {@see Job_Controller} (Phase B4) — kept separate so the read proxy
 * here is callable even before the job pipeline lands.
 */

namespace FT_Demo_Importer\REST;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use FT_Demo_Importer\Studio\Remote_Client;

class Studio_Proxy_Controller {

	public const NAMESPACE = 'ft-demo-importer/v1';

	private Remote_Client $client;

	public function __construct( Remote_Client $client ) {
		$this->client = $client;
	}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$perm = [ $this, 'check_permission' ];

		register_rest_route( self::NAMESPACE, '/studio/me', [
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => [ $this, 'get_me' ],
		] );
		register_rest_route( self::NAMESPACE, '/studio/templates', [
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => [ $this, 'list_templates' ],
		] );
		register_rest_route( self::NAMESPACE, '/studio/templates/(?P<id>\d+)', [
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => [ $this, 'get_template' ],
		] );
		register_rest_route( self::NAMESPACE, '/studio/categories', [
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => [ $this, 'list_categories' ],
		] );
	}

	public function check_permission( \WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'ft_demo_importer_forbidden',
				__( 'You need `manage_options` to use the importer.', 'famethemes-demo-importer' ),
				[ 'status' => 403 ]
			);
		}
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error(
				'ft_demo_importer_bad_nonce',
				__( 'Invalid nonce.', 'famethemes-demo-importer' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	// ----------------------------------------------------------------------

	public function get_me( \WP_REST_Request $request ) {
		return $this->respond( $this->client->get( 'me' ) );
	}

	public function list_templates( \WP_REST_Request $request ) {
		$theme = (string) $request->get_param( 'theme' );
		if ( '' === $theme ) {
			$theme = get_stylesheet();
		}
		$query = [ 'theme' => $theme ];
		foreach ( [ 'search', 'category', 'page', 'per_page', 'view_context' ] as $k ) {
			$v = $request->get_param( $k );
			if ( null !== $v && '' !== $v ) {
				$query[ $k ] = $v;
			}
		}
		return $this->respond( $this->client->get( 'templates', $query ) );
	}

	public function get_template( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		return $this->respond( $this->client->get( "templates/{$id}" ) );
	}

	public function list_categories( \WP_REST_Request $request ) {
		// `view_context=site` keeps counts strictly scoped to the active
		// theme — matches what list_templates filters by, so sidebar
		// counts don't drift from grid contents.
		return $this->respond( $this->client->get( 'categories', [
			'type'         => 'template',
			'view_context' => 'site',
		] ) );
	}

	// ----------------------------------------------------------------------

	private function respond( array $res ) {
		// Network unreachable / not configured — surface as 502 so the JS
		// can show a connection-failed message without parsing the body.
		if ( null !== $res['error'] && 0 === $res['status'] ) {
			return new \WP_Error( 'ft_demo_importer_upstream_unreachable', $res['error'], [ 'status' => 502 ] );
		}
		return new \WP_REST_Response( $res['body'], max( 200, $res['status'] ) );
	}
}
