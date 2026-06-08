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
		register_rest_route( self::NAMESPACE, '/studio/templates/(?P<id>\d+)/options', [
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => [ $this, 'get_template_options' ],
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

	/**
	 * Generic transient cache wrapper around any proxy call. Every
	 * caching endpoint funnels through here so the `?no_cache=1`
	 * bypass behaves identically across the namespace — same flag,
	 * same delete-on-bypass semantics, same `X-FDI-Cache` header
	 * vocabulary (`HIT` / `MISS` / `BYPASS`). All routes already gate
	 * on `manage_options` + REST nonce, so any caller hitting an
	 * endpoint at all already has permission to flip `?no_cache=1`
	 * — no extra capability check needed.
	 *
	 * `$fetch` should return one of:
	 *   - `[ 'status' => int, 'body' => mixed ]`  (cached on 2xx)
	 *   - `\WP_Error`                              (propagated, never cached)
	 *
	 * The cache key passed in is namespaced with `ft_demo_importer_`
	 * here so callers don't have to remember the prefix.
	 *
	 * @param \WP_REST_Request $request
	 * @param string           $key      Caller-computed unique key
	 *                                   (e.g. md5 of a query map or
	 *                                    a URL basename). No prefix.
	 * @param int              $ttl_secs Transient TTL.
	 * @param callable         $fetch    Closure that performs the
	 *                                    remote / heavy work on miss.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function cached_proxy( \WP_REST_Request $request, string $key, int $ttl_secs, callable $fetch ) {
		$cache_key = 'ft_demo_importer_' . $key;
		$no_cache  = (bool) $request->get_param( 'no_cache' );

		if ( $no_cache ) {
			// Wipe + skip — wiping (vs. just skipping the read) means
			// any subsequent visitor without the bypass flag immediately
			// gets the freshly-fetched payload instead of the
			// pre-bypass stale entry.
			delete_transient( $cache_key );
		} else {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && array_key_exists( 'body', $cached ) ) {
				$response = new \WP_REST_Response( $cached['body'], (int) ( $cached['status'] ?? 200 ) );
				$response->header( 'X-FDI-Cache', 'HIT' );
				return $response;
			}
		}

		$result = $fetch();
		if ( is_wp_error( $result ) ) {
			return $result; // errors never cached
		}

		$status = (int) ( $result['status'] ?? 200 );
		$body   = $result['body'] ?? null;
		if ( $status >= 200 && $status < 300 ) {
			set_transient( $cache_key, [
				'status' => $status,
				'body'   => $body,
			], $ttl_secs );
		}

		$response = new \WP_REST_Response( $body, $status );
		$response->header( 'X-FDI-Cache', $no_cache ? 'BYPASS' : 'MISS' );
		return $response;
	}

	public function get_me( \WP_REST_Request $request ) {
		return $this->respond( $this->client->get( 'me' ) );
	}

	/**
	 * Cache TTL for list_templates proxy responses. The remote round-trip
	 * to the studio takes ~2-3 seconds from low-bandwidth links (VN ↔
	 * US server cold, before our snapshot rollout reaches the host); a
	 * 10-minute transient cuts every repeat open of the Starter
	 * Templates page down to a local DB read (~50ms). The catalog
	 * itself changes slowly (rarely more than once per day), so 10
	 * minutes is well within "freshness the user expects".
	 *
	 * Cache key is composed of every query param the request actually
	 * carries, so different filters / view contexts cache separately
	 * and stale-cross-contamination is impossible.
	 */
	private const LIST_TEMPLATES_TTL = 10 * MINUTE_IN_SECONDS;

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

		// Cache key: stable serialize of the full query map (sorted so
		// `?theme=x&search=y` and `?search=y&theme=x` collapse to one
		// entry) hashed to keep the option name under WP's 172-char
		// transient-key budget.
		ksort( $query );
		$cache_key = 'tpl_list_' . md5( wp_json_encode( $query ) );

		return $this->cached_proxy( $request, $cache_key, self::LIST_TEMPLATES_TTL, function () use ( $query ) {
			$res = $this->client->get( 'templates', $query );
			if ( 0 === $res['status'] && null !== $res['error'] ) {
				return new \WP_Error( 'ft_demo_importer_upstream_unreachable', $res['error'], [ 'status' => 502 ] );
			}
			return [ 'status' => (int) $res['status'], 'body' => $res['body'] ];
		} );
	}

	/**
	 * Detail cache — same justification as list_templates: the
	 * underlying CDN response is stable for hours, the wizard's
	 * Preview flow reuses the same detail across re-opens, and the
	 * Studio's options-URL embed means a content change always
	 * tumbles into a fresh cache key downstream
	 * (`get_template_options` keys on basename).
	 */
	private const TEMPLATE_DETAIL_TTL = 10 * MINUTE_IN_SECONDS;

	public function get_template( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( $id <= 0 ) {
			return new \WP_Error( 'ft_demo_importer_bad_template_id', 'Invalid template id.', [ 'status' => 400 ] );
		}
		$cache_key = 'tpl_detail_' . $id;

		return $this->cached_proxy( $request, $cache_key, self::TEMPLATE_DETAIL_TTL, function () use ( $id ) {
			$res = $this->client->get( "templates/{$id}" );
			if ( 0 === $res['status'] && null !== $res['error'] ) {
				return new \WP_Error( 'ft_demo_importer_upstream_unreachable', $res['error'], [ 'status' => 502 ] );
			}
			return [ 'status' => (int) $res['status'], 'body' => $res['body'] ];
		} );
	}

	/**
	 * Proxy a fetch of the template's bundled `options.json`.
	 *
	 * The Studio CDN does not allow cross-origin GETs from the wp-admin
	 * origin, so the wizard can't `fetch()` the file from JS directly.
	 * The URL comes back from `GET /templates/{id}` under
	 * `assets.options.url`. We re-query that detail call here (cheap
	 * for the Studio — same path the detail panel already hits), peel
	 * out the asset URL, then stream the JSON server-side and hand the
	 * parsed body back. JS sees a same-origin REST call → no CORS.
	 *
	 * The endpoint returns the FULL parsed options blob — callers slice
	 * what they need (right now: `theme.mods.customify_color_palettes`,
	 * but future Style-step features will read more fields here too).
	 */
	/**
	 * 2h TTL — short enough that a template revision propagates
	 * soon after the contributor publishes, long enough that
	 * repeated wizard opens of the same template don't hammer the
	 * Studio CDN. The content-hashed filename also self-busts:
	 * when the file regenerates, basename changes → new cache key.
	 */
	private const TEMPLATE_OPTIONS_TTL = 2 * HOUR_IN_SECONDS;

	public function get_template_options( \WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( $id <= 0 ) {
			return new \WP_Error( 'ft_demo_importer_bad_template_id', 'Invalid template id.', [ 'status' => 400 ] );
		}

		// Resolve the options URL from the template detail. NOT cached
		// here — caller already paid for the detail on the wizard's
		// previous request, and `get_template`'s own transient covers
		// the repeat. Detail fetch is mandatory because the cache key
		// for the JSON itself is the content-hashed BASENAME (see
		// below), which only becomes available once we've seen the URL.
		$detail_res = $this->client->get( "templates/{$id}" );
		if ( 0 === $detail_res['status'] && null !== $detail_res['error'] ) {
			return new \WP_Error( 'ft_demo_importer_upstream_unreachable', $detail_res['error'], [ 'status' => 502 ] );
		}
		$detail = $detail_res['body'];
		if ( ! is_array( $detail ) ) {
			return new \WP_Error( 'ft_demo_importer_bad_detail', 'Template detail not parseable.', [ 'status' => 502 ] );
		}
		$url = (string) ( $detail['assets']['options']['url'] ?? $detail['options_url'] ?? '' );
		if ( '' === $url ) {
			return new \WP_Error( 'ft_demo_importer_no_options_url', 'Template has no options.json URL.', [ 'status' => 404 ] );
		}

		// Cache key — the Studio embeds a content hash in the file
		// name (`options-8eaa5cfc.json`), so keying on basename gives
		// us automatic cache-busting whenever the template is
		// regenerated upstream. md5 keeps the transient name short
		// + within the wp_options column character limit.
		$basename  = (string) wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$cache_key = 'tpl_opts_' . md5( $basename );

		return $this->cached_proxy( $request, $cache_key, self::TEMPLATE_OPTIONS_TTL, function () use ( $url ) {
			// `wp_safe_remote_get` enforces SSRF guards (rejects local
			// / private addresses), and we only ever hand the URL we
			// just received from the Studio's own detail payload, so
			// there's no way for a caller to coerce this into fetching
			// arbitrary URLs.
			$res = wp_safe_remote_get( $url, [
				'timeout'     => 15,
				'redirection' => 3,
			] );
			if ( is_wp_error( $res ) ) {
				return new \WP_Error( 'ft_demo_importer_options_unreachable', $res->get_error_message(), [ 'status' => 502 ] );
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( $code < 200 || $code >= 300 ) {
				return new \WP_Error( 'ft_demo_importer_options_http_status', "options.json returned HTTP {$code}.", [ 'status' => 502 ] );
			}
			$body   = wp_remote_retrieve_body( $res );
			$parsed = json_decode( $body, true );
			if ( ! is_array( $parsed ) ) {
				return new \WP_Error( 'ft_demo_importer_options_not_json', 'options.json was not valid JSON.', [ 'status' => 502 ] );
			}
			return [ 'status' => 200, 'body' => $parsed ];
		} );
	}

	/**
	 * Categories rarely change. 30 min TTL keeps the sidebar pills
	 * snappy without making fresh categories invisible for too long.
	 */
	private const CATEGORIES_TTL = 30 * MINUTE_IN_SECONDS;

	public function list_categories( \WP_REST_Request $request ) {
		// `view_context=site` keeps counts strictly scoped to the active
		// theme — matches what list_templates filters by, so sidebar
		// counts don't drift from grid contents.
		$query     = [ 'type' => 'template', 'view_context' => 'site' ];
		$cache_key = 'tpl_cats_' . md5( wp_json_encode( $query ) );

		return $this->cached_proxy( $request, $cache_key, self::CATEGORIES_TTL, function () use ( $query ) {
			$res = $this->client->get( 'categories', $query );
			if ( 0 === $res['status'] && null !== $res['error'] ) {
				return new \WP_Error( 'ft_demo_importer_upstream_unreachable', $res['error'], [ 'status' => 502 ] );
			}
			return [ 'status' => (int) $res['status'], 'body' => $res['body'] ];
		} );
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
