<?php
/**
 * GET-only HTTP client for the PM Templates **public catalog** REST API
 * (`/wp-json/pm-templates/v1/public/...`).
 *
 * PM Templates is the reworked successor to Blocksify Design Studio; its
 * public catalog serves template browse/detail/filters with NO authentication.
 * This client keeps the legacy "Studio" method surface (`get('templates')`,
 * `get('templates/{id}')`, `get('categories')`, `get('me')`) and normalizes
 * every catalog response back into the old Studio shape, so the proxy, import
 * Steps, and React UI consume it unchanged. See {@see get()} for the mapping.
 *
 * Same-origin in-process dispatch is preserved for dev installs; the
 * `X-PMBD-Api-Key` header is still sent when a key is configured (the public
 * catalog ignores it — kept only for backward compatibility).
 *
 * Read-only by design: the importer never writes back, so there's no
 * POST/PATCH/DELETE surface here.
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
	 * GET a studio-shaped resource. Internally this talks to the PM Templates
	 * **public catalog** (`pm-templates/v1/public/*`, no auth) and normalizes
	 * every response back into the legacy Studio response shape the proxy,
	 * import Steps, and React UI still consume — so the rest of the plugin needs
	 * no changes.
	 *
	 * Supported legacy paths → pm-templates public catalog:
	 *   me               → ping /filters (connection test)
	 *   categories       → /filters (categories facet)
	 *   templates        → /templates (list, unwrapped-friendly)
	 *   templates/{id}   → /templates/{id} (detail)
	 *
	 * @return array{status:int, body:mixed, error:?string}
	 */
	public function get( string $path, array $query = [] ): array {
		$path = ltrim( $path, '/' );

		// Connection test — pm-templates has no /me; ping the public catalog
		// and synthesize a Studio-shaped `{label, scope}` body.
		if ( 'me' === $path ) {
			$ping = $this->request( 'GET', 'filters', [ 'query' => [] ] );
			if ( $ping['status'] >= 200 && $ping['status'] < 300 ) {
				return [
					'status' => 200,
					'body'   => [ 'label' => 'PM Templates (public catalog)', 'scope' => 'read' ],
					'error'  => null,
				];
			}
			return [
				'status' => $ping['status'] ?: 502,
				'body'   => null,
				'error'  => $ping['error'] ?? __( 'PM Templates catalog unreachable.', 'famethemes-demo-importer' ),
			];
		}

		// Category pills — pm-templates exposes facets at /filters.
		if ( 'categories' === $path ) {
			$res = $this->request( 'GET', 'filters', [ 'query' => [] ] );
			if ( is_array( $res['body'] ) ) {
				$res['body'] = $this->normalize_categories( $res['body'] );
			}
			return $res;
		}

		// Template list.
		if ( 'templates' === $path ) {
			$res = $this->request( 'GET', 'templates', [ 'query' => $this->map_list_query( $query ) ] );
			if ( is_array( $res['body'] ) ) {
				$res['body'] = $this->normalize_list( $res['body'] );
			}
			return $res;
		}

		// Template detail.
		if ( preg_match( '#^templates/(\d+)$#', $path, $m ) ) {
			$res = $this->request( 'GET', 'templates/' . $m[1], [ 'query' => $query ] );
			if ( is_array( $res['body'] ) ) {
				$res['body'] = $this->normalize_item( $res['body'] );
			}
			return $res;
		}

		// Unknown path — pass through untouched.
		return $this->request( 'GET', $path, [ 'query' => $query ] );
	}

	/**
	 * Map the legacy list query onto the public catalog's params. Drops
	 * theme/view_context (the catalog isn't theme-scoped) and clamps the
	 * "all" sentinel (`per_page = -1`) to the catalog's 100 max.
	 *
	 * @param array<string,mixed> $query
	 * @return array<string,mixed>
	 */
	private function map_list_query( array $query ): array {
		$out = [];
		foreach ( [ 'search', 'category', 'type', 'plugin', 'page', 'orderby', 'order' ] as $k ) {
			if ( isset( $query[ $k ] ) && '' !== $query[ $k ] ) {
				$out[ $k ] = $query[ $k ];
			}
		}
		$per_page        = (int) ( $query['per_page'] ?? 0 );
		$out['per_page'] = ( $per_page <= 0 || $per_page > 100 ) ? 100 : $per_page;
		return $out;
	}

	/**
	 * Normalize a public-catalog list `{ items, pagination }` into the shape
	 * the grid consumes: keep the `items` wrapper (the JS already unwraps it)
	 * but map each item to the legacy card shape.
	 *
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>
	 */
	private function normalize_list( array $body ): array {
		$items = $body['items'] ?? ( $this->is_list( $body ) ? $body : [] );
		$items = array_map( [ $this, 'normalize_item' ], is_array( $items ) ? $items : [] );
		return [
			'items'      => $items,
			'pagination' => $body['pagination'] ?? null,
		];
	}

	/**
	 * Map one catalog item (list card OR full detail) to the legacy Studio
	 * shape. Detail-only fields (`assets`, `requirements`, `pages`) pass
	 * through untouched via the merge.
	 *
	 * @param array<string,mixed> $item
	 * @return array<string,mixed>
	 */
	private function normalize_item( array $item ): array {
		$thumb = is_array( $item['thumbnail'] ?? null ) ? $item['thumbnail'] : null;
		$sizes = is_array( $thumb['sizes'] ?? null ) ? $thumb['sizes'] : [];

		$preview_image = null;
		$thumb_url     = '';
		if ( null !== $thumb && ! empty( $thumb['url'] ) ) {
			$flat          = [ 'url' => (string) $thumb['url'] ];
			$preview_image = [
				'url'    => (string) $thumb['url'],
				'full'   => $sizes['full'] ?? $flat,
				'medium' => $sizes['medium'] ?? $flat,
				'thumb'  => $sizes['thumb'] ?? $flat,
			];
			$thumb_url = (string) ( $sizes['thumb']['url'] ?? $thumb['url'] );
		}

		$categories = is_array( $item['categories'] ?? null ) ? $item['categories'] : [];
		$cat_slugs  = [];
		foreach ( $categories as $c ) {
			if ( is_array( $c ) && ! empty( $c['slug'] ) ) {
				$cat_slugs[] = (string) $c['slug'];
			}
		}

		// `excerpt` in the catalog IS the keywords array.
		$keywords = is_array( $item['excerpt'] ?? null ) ? $item['excerpt'] : [];

		return array_merge( $item, [
			'name'           => (string) ( $item['title'] ?? '' ),
			'preview_image'  => $preview_image,
			'thumb_url'      => $thumb_url,
			'keywords'       => $keywords,
			'category_slugs' => $cat_slugs,
			'is_pro'         => false,
			// Defensive: the Style step reads `theme_options.*`; provide an
			// empty object so property access never throws when a template
			// ships no theme options.
			'theme_options'  => isset( $item['theme_options'] ) && is_array( $item['theme_options'] )
				? $item['theme_options']
				: new \stdClass(),
		] );
	}

	/**
	 * Extract the categories facet from a `/filters` response into the bare
	 * `[{slug,name,count}]` array the sidebar expects.
	 *
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_categories( array $filters ): array {
		$cats = $filters['categories'] ?? [];
		return is_array( $cats ) ? array_values( $cats ) : [];
	}

	/**
	 * @param array<mixed> $arr
	 */
	private function is_list( array $arr ): bool {
		return $arr === array_values( $arr );
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

		// The public catalog is not theme-scoped, so no `theme` param is
		// attached (the old Studio API filtered by it; pm-templates does not).
		if ( ! isset( $extra['query'] ) || ! is_array( $extra['query'] ) ) {
			$extra['query'] = [];
		}

		if ( $this->is_same_origin_studio() ) {
			return $this->dispatch_in_process( $method, $path, $extra );
		}

		$url = trailingslashit( $this->options->studio_url() ) . 'wp-json/pm-templates/v1/public/' . ltrim( $path, '/' );
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

		$route   = '/pm-templates/v1/public/' . ltrim( $path, '/' );
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
