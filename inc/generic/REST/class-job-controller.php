<?php
/**
 * REST endpoints that drive the job lifecycle from the browser:
 *
 *   POST /ft-demo-importer/v1/theme/jobs              → create + enqueue
 *   GET  /ft-demo-importer/v1/theme/jobs/latest       → fetch latest (UI convenience)
 *   GET  /ft-demo-importer/v1/theme/jobs/{id}         → poll status + progress
 *   POST /ft-demo-importer/v1/theme/jobs/{id}/cancel  → flag for cancellation
 *
 * Auth shape mirrors {@see Studio_Proxy_Controller} — `manage_options`
 * + standard `X-WP-Nonce`. Job objects are returned as-is from
 * {@see Job_Store::get()}.
 */

namespace FT_Demo_Importer\REST;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use FT_Demo_Importer\Jobs\Importer_Runner;
use FT_Demo_Importer\Jobs\Job_Store;

class Job_Controller {

	public const NAMESPACE = 'ft-demo-importer/v1';

	private Job_Store       $jobs;
	private Importer_Runner $runner;

	public function __construct( Job_Store $jobs, Importer_Runner $runner ) {
		$this->jobs   = $jobs;
		$this->runner = $runner;
	}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$perm = [ $this, 'check_permission' ];

		register_rest_route( self::NAMESPACE, '/theme/jobs', [
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => [ $this, 'create_job' ],
		] );
		register_rest_route( self::NAMESPACE, '/theme/jobs/latest', [
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => [ $this, 'get_latest' ],
		] );
		register_rest_route( self::NAMESPACE, '/theme/jobs/(?P<id>[a-z0-9]+)', [
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => [ $this, 'get_job' ],
		] );
		register_rest_route( self::NAMESPACE, '/theme/jobs/(?P<id>[a-z0-9]+)/cancel', [
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => [ $this, 'cancel_job' ],
		] );
	}

	public function check_permission( \WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'ft_demo_importer_forbidden',
				__( 'You need `manage_options` to control imports.', 'famethemes-demo-importer' ),
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

	public function create_job( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = [];
		}

		$template_id = (int) ( $body['template_id'] ?? 0 );
		if ( $template_id <= 0 ) {
			return new \WP_Error( 'ft_demo_importer_bad_template', 'template_id is required.', [ 'status' => 400 ] );
		}

		// Defaults match blocksify-design-importer's contract — import
		// everything + overwrite + replace settings on first run. UI
		// (B6 wizard) can flip individual flags via the body.
		$config = [
			'template_id'        => $template_id,
			'import_content'     => true,
			'import_uploads'     => true,
			'overwrite_existing' => true,
			'replace_settings'   => true,
			'plugins_skip'       => [],
			'custom_logo_id'     => 0,
			'custom_logo_url'    => '',
		];

		foreach ( [ 'import_content', 'import_uploads', 'overwrite_existing', 'replace_settings' ] as $key ) {
			if ( array_key_exists( $key, $body ) ) {
				$config[ $key ] = (bool) $body[ $key ];
			}
		}
		if ( isset( $body['plugins_skip'] ) && is_array( $body['plugins_skip'] ) ) {
			$config['plugins_skip'] = array_values( array_filter( array_map(
				static fn( $s ) => is_string( $s ) ? sanitize_key( $s ) : '',
				$body['plugins_skip']
			) ) );
		}
		if ( isset( $body['custom_logo_id'] ) ) {
			$config['custom_logo_id'] = max( 0, (int) $body['custom_logo_id'] );
		}
		if ( isset( $body['custom_logo_url'] ) && is_string( $body['custom_logo_url'] ) ) {
			$config['custom_logo_url'] = esc_url_raw( $body['custom_logo_url'] );
		}

		/**
		 * Filter the job config before it's persisted + enqueued.
		 *
		 * @param array<string,mixed> $config Final config — same keys as the request body.
		 * @param array<string,mixed> $body   Raw request body.
		 */
		$config = (array) apply_filters( 'ft_demo_importer_job_config', $config, $body );

		// Wipe leftover jobs (stuck `queued` from prior loopback failures
		// would otherwise sit alongside the new one and confuse polling).
		$discarded = $this->jobs->discard_pending();

		$job_id = $this->jobs->create( $config );
		if ( ! empty( $discarded ) ) {
			$this->jobs->log( $job_id, sprintf(
				/* translators: 1: number, 2: comma-separated IDs */
				__( 'Discarded %1$d pending job(s) before starting: %2$s', 'famethemes-demo-importer' ),
				count( $discarded ),
				implode( ', ', $discarded )
			) );
		}
		$this->runner->enqueue( $job_id );

		return new \WP_REST_Response( [ 'job_id' => $job_id ], 202 );
	}

	public function get_job( \WP_REST_Request $request ) {
		$id  = (string) $request->get_param( 'id' );
		$job = $this->jobs->get( $id );
		if ( null === $job ) {
			return new \WP_Error( 'ft_demo_importer_job_not_found', 'Job not found.', [ 'status' => 404 ] );
		}
		return new \WP_REST_Response( $job, 200 );
	}

	public function get_latest( \WP_REST_Request $request ) {
		$job = $this->jobs->latest();
		return new \WP_REST_Response( $job ?? new \stdClass(), 200 );
	}

	public function cancel_job( \WP_REST_Request $request ) {
		$id  = (string) $request->get_param( 'id' );
		$job = $this->jobs->get( $id );
		if ( null === $job ) {
			return new \WP_Error( 'ft_demo_importer_job_not_found', 'Job not found.', [ 'status' => 404 ] );
		}
		$this->jobs->request_cancel( $id );
		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}
}
