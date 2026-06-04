<?php
/**
 * GET-only HTTP client for the Blocksify Design Studio's REST API
 * (`/wp-json/blocksify-design-studio/v1/...`).
 *
 * Ported from `blocksify-design-importer/includes/Studio/RemoteClient.php`
 * — same architecture (same-origin in-process dispatch for dev installs,
 * `X-PMBD-Api-Key` header per request, streaming download) but rewired
 * onto FT_Demo_Importer's {@see Options_Store} for credential lookup.
 *
 * Read-only by design: the generic importer never writes back to the
 * Studio, so there's no POST/PATCH/DELETE surface here.
 */

namespace FT_Demo_Importer\Studio;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use FT_Demo_Importer\Settings\Options_Store;

class Remote_Client {

	public const HEADER = 'X-PMBD-Api-Key';

	private Options_Store $options;

	public function __construct( Options_Store $options ) {
		$this->options = $options;
	}

	/**
	 * @return array{status:int, body:mixed, error:?string}
	 */
	public function get( string $path, array $query = [] ): array {
		return $this->request( 'GET', $path, [ 'query' => $query ] );
	}

	/**
	 * Stream a binary asset to disk — used by AssetFetcher to pull
	 * content.json / options.json / uploads.zip without buffering
	 * (uploads.zip can hit 200 MB). Returns true on 2xx + file written.
	 *
	 * Same-origin short-circuit: when the Studio happens to live on the
	 * same WordPress install (dev convenience), HTTP loopback would
	 * deadlock single-threaded PHP servers, so we map the URL back to a
	 * local uploads path and copy from disk instead.
	 */
	public function download( string $url, string $target_path, int $timeout = 300 ): bool {
		if ( '' === $url || '' === $target_path ) {
			return false;
		}
		if ( ! wp_mkdir_p( dirname( $target_path ) ) ) {
			return false;
		}

		if ( $this->is_same_origin( $url ) ) {
			$local = $this->local_path_for_url( $url );
			if ( null !== $local && is_readable( $local ) ) {
				return (bool) @copy( $local, $target_path );
			}
			// Fallthrough to HTTP if URL doesn't map to local uploads
			// (e.g. plugin-served URL that isn't an attachment).
		}

		$headers = [];
		$key     = $this->options->studio_key();
		if ( '' !== $key ) {
			$headers[ self::HEADER ] = $key;
		}
		$response = wp_remote_get(
			$url,
			[
				'timeout'  => $timeout,
				'stream'   => true,
				'filename' => $target_path,
				'headers'  => $headers,
			]
		);
		if ( is_wp_error( $response ) ) {
			@unlink( $target_path );
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			@unlink( $target_path );
			return false;
		}
		return file_exists( $target_path );
	}

	// ----------------------------------------------------------------------
	// Internals
	// ----------------------------------------------------------------------

	private function request( string $method, string $path, array $extra ): array {
		// Only the URL is required — the Studio's public read endpoints
		// (`GET /templates`, `/templates/{id}`, `/categories`, `/taxonomies`)
		// don't need an API key. The key, when set, is sent on every
		// request anyway so the Studio can identify the caller for
		// `/me`, rate-limit tracking, and any future scope-gated routes.
		// `Options_Store::studio_url()` always returns a non-empty
		// value (default = DEFAULT_STUDIO_URL), so this branch only
		// triggers if a filter / constant explicitly clears it.
		if ( '' === $this->options->studio_url() ) {
			return [
				'status' => 0,
				'body'   => null,
				'error'  => __( 'Studio URL not configured.', 'famethemes-demo-importer' ),
			];
		}

		// Auto-attach the contributor site's active theme to every request
		// via the `theme` query param — the Studio uses it for filtering
		// (list endpoints) and for analytics elsewhere. Caller can override
		// by including its own `theme` in `$extra['query']`.
		if ( ! isset( $extra['query'] ) || ! is_array( $extra['query'] ) ) {
			$extra['query'] = [];
		}
		if ( ! array_key_exists( 'theme', $extra['query'] ) || '' === $extra['query']['theme'] ) {
			$extra['query']['theme'] = get_stylesheet();
		}

		if ( $this->is_same_origin_studio() ) {
			return $this->dispatch_in_process( $method, $path, $extra );
		}

		$url = trailingslashit( $this->options->studio_url() ) . 'wp-json/blocksify-design-studio/v1/' . ltrim( $path, '/' );
		if ( ! empty( $extra['query'] ) ) {
			$url = add_query_arg( $extra['query'], $url );
		}

		// Only send the auth header when a key is actually set — sending
		// an empty `X-PMBD-Api-Key` would make the Studio reject the
		// request as a malformed authenticated call on scope-gated
		// routes, instead of falling through to the public-read path.
		$headers = [];
		$key     = $this->options->studio_key();
		if ( '' !== $key ) {
			$headers[ self::HEADER ] = $key;
		}

		$response = wp_remote_request( $url, [
			'method'  => $method,
			'timeout' => 30,
			'headers' => $headers,
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'status' => 0, 'body' => null, 'error' => $response->get_error_message() ];
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		return [
			'status' => $status,
			'body'   => null === $decoded ? $body : $decoded,
			'error'  => $status >= 400
				? ( is_array( $decoded ) && isset( $decoded['message'] ) ? (string) $decoded['message'] : "HTTP $status" )
				: null,
		];
	}

	/**
	 * In-process REST dispatch — when Studio lives on the same install.
	 * Builds a WP_REST_Request and runs it through the Studio's controllers
	 * directly so we don't deadlock the dev server's single PHP worker.
	 *
	 * @return array{status:int, body:mixed, error:?string}
	 */
	private function dispatch_in_process( string $method, string $path, array $extra ): array {
		if ( ! did_action( 'rest_api_init' ) ) {
			rest_get_server();
			do_action( 'rest_api_init' );
		}

		$route   = '/blocksify-design-studio/v1/' . ltrim( $path, '/' );
		$request = new \WP_REST_Request( strtoupper( $method ), $route );
		// Only set the auth header when a key is actually configured —
		// see request() for the rationale.
		$key = $this->options->studio_key();
		if ( '' !== $key ) {
			$request->set_header( self::HEADER, $key );
		}

		if ( ! empty( $extra['query'] ) && is_array( $extra['query'] ) ) {
			foreach ( $extra['query'] as $key => $value ) {
				$request->set_param( (string) $key, $value );
			}
		}

		$response = rest_get_server()->dispatch( $request );

		if ( $response instanceof \WP_Error ) {
			$status = (int) ( $response->get_error_data()['status'] ?? 500 );
			return [
				'status' => $status,
				'body'   => [ 'code' => $response->get_error_code(), 'message' => $response->get_error_message() ],
				'error'  => $response->get_error_message(),
			];
		}

		$status = (int) $response->get_status();
		$data   = $response->get_data();

		return [
			'status' => $status,
			'body'   => $data,
			'error'  => $status >= 400
				? ( is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : "HTTP $status" )
				: null,
		];
	}

	private function is_same_origin_studio(): bool {
		$server_host = wp_parse_url( $this->options->studio_url(), PHP_URL_HOST );
		$home_host   = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return is_string( $server_host ) && is_string( $home_host )
			&& strtolower( $server_host ) === strtolower( $home_host );
	}

	private function is_same_origin( string $url ): bool {
		$host    = wp_parse_url( $url, PHP_URL_HOST );
		$my_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return is_string( $host ) && is_string( $my_host )
			&& strtolower( $host ) === strtolower( $my_host );
	}

	/**
	 * Translate a same-origin attachment URL back to its local file path
	 * so {@see download()} can copy from disk instead of HTTP-fetching
	 * its own server.
	 */
	private function local_path_for_url( string $url ): ?string {
		$upload  = wp_get_upload_dir();
		$baseurl = trailingslashit( (string) ( $upload['baseurl'] ?? '' ) );
		$basedir = trailingslashit( (string) ( $upload['basedir'] ?? '' ) );
		if ( '' === $baseurl || '' === $basedir ) {
			return null;
		}
		// Strip protocol for resilience to http/https mismatch.
		$rel_url  = preg_replace( '#^https?:#i', '', $url );
		$rel_base = preg_replace( '#^https?:#i', '', $baseurl );
		if ( ! is_string( $rel_url ) || ! is_string( $rel_base ) ) {
			return null;
		}
		if ( 0 !== strpos( $rel_url, $rel_base ) ) {
			return null;
		}
		$relative = ltrim( substr( $rel_url, strlen( $rel_base ) ), '/' );
		$relative = (string) preg_replace( '/[?#].*$/', '', $relative );
		return '' === $relative ? null : $basedir . $relative;
	}
}
