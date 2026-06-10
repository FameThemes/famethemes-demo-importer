<?php
/**
 * Install Google Fonts into the WP Font Library on behalf of Customify
 * Adapter's typography step.
 *
 * Walked over by `Customify_Adapter::apply_typography()` after the
 * import job's `applying_options` phase finishes.
 *
 * Architecture (mirrors Blocksify's design-library font installer —
 * the only known-working reference implementation for self-hosted
 * Google Fonts on WP 6.5+):
 *
 *   1. Catalogue + Google CSS — resolve every (weight, style) variant
 *      a family ships. The theme's bundled `google-fonts.json`
 *      provides the list of variants per family; the actual file
 *      URLs come from Google's CSS endpoint (modern UA → woff2).
 *      Deduped to one entry per (weight, style); Google ships one
 *      `@font-face` per unicode subset which we collapse.
 *
 *   2. Download + sideload — each remote URL is streamed to a temp
 *      file, magic-byte validated (`wOF2` / `wOFF` / `\x00\x01\x00\x00`
 *      / `OTTO`), renamed to the canonical extension, then handed to
 *      `wp_handle_sideload()` with the `_wp_filter_font_directory`
 *      filter active so it lands at the path WP's Font Library
 *      expects.
 *
 *   3. REST insert — both family + face CPTs go through
 *      `rest_do_request` to the canonical font-library REST routes
 *      (`/wp/v2/font-families` and `…/font-faces`). Going through
 *      REST guarantees the controller's slug/duplicate checks,
 *      `_wp_font_face_file` post_meta seeding, and validation pass
 *      run identically to a UI-driven install.
 *
 *   4. user_has_cap grant — the import job runs without a session, so
 *      `current_user_can()` checks inside the REST controllers fail.
 *      A short-lived `user_has_cap` filter grants `edit_theme_options`
 *      + `unfiltered_upload` for the duration of the install, then
 *      removes itself.
 *
 *   5. Global-styles activation — registering the family in the
 *      user's `wp_global_styles` post is what flips the Font Library
 *      UI from "X/Y inactive" to "Y/Y active" and what makes the
 *      family available in the editor's font picker. Cache flush
 *      after the write so the UI doesn't read a stale resolver
 *      snapshot.
 *
 * Idempotent on re-install: an existing family slug is wiped (family
 * CPT + face CPTs + global-styles entry deleted) before the fresh
 * install runs, so a bad earlier run can be repaired by re-importing
 * the same template.
 */

namespace FT_Demo_Importer\Adapters\Customify;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Font_Installer {

	private const FAMILY_POST_TYPE = 'wp_font_family';
	private const FACE_POST_TYPE   = 'wp_font_face';

	/** @var callable|null */
	private $cap_grant_filter = null;

	/**
	 * Remote URL → sideloaded local URL, reset per install(). Variable
	 * fonts reuse one physical file across many (weight, subset) face
	 * entries — without this cache the same .woff2 would be downloaded
	 * and sideloaded once per face, leaving -1/-2/… duplicates in the
	 * fonts dir.
	 *
	 * @var array<string, string>
	 */
	private array $sideloaded_by_url = [];

	/**
	 * Install one font family with every variant the catalogue carries.
	 *
	 * @return int|null Family CPT post id (or null on hard failure).
	 */
	public function install( string $family ): ?int {
		if ( '' === $family ) {
			return null;
		}
		if ( ! function_exists( 'wp_get_font_dir' ) ) {
			return null;  // WP < 6.5
		}

		$slug = sanitize_title( $family );
		if ( '' === $slug ) {
			return null;
		}

		// Wipe + reinstall — guarantees clean state even after a bad
		// earlier run wrote broken files or a corrupt global-styles
		// entry. See class docblock.
		$existing = get_page_by_path( $slug, OBJECT, self::FAMILY_POST_TYPE );
		if ( $existing instanceof \WP_Post ) {
			$this->wipe_existing_family( (int) $existing->ID, $slug );
		}

		$variants = $this->resolve_variants( $family );
		if ( empty( $variants ) ) {
			return null;
		}
		$this->sideloaded_by_url = [];

		// Grant the caps the font REST routes gate on. The cron user
		// context has no session, so without this every REST call
		// fails the permissions check.
		$this->grant_font_caps();
		try {
			$family_id = $this->rest_create_family( $family, $slug );
			if ( null === $family_id ) {
				return null;
			}

			$installed_faces = [];
			foreach ( $variants as $variant ) {
				$face_settings = $this->install_one_face( $family, $family_id, $variant );
				if ( null !== $face_settings ) {
					$installed_faces[] = $face_settings;
				}
			}
			if ( empty( $installed_faces ) ) {
				wp_delete_post( $family_id, true );
				return null;
			}

			$this->activate_in_global_styles( $family, $slug, $installed_faces );
			return $family_id;
		} finally {
			$this->revoke_font_caps();
		}
	}

	// ─────────────────────────────────────────────────────── CAP GRANT

	private function grant_font_caps(): void {
		$this->cap_grant_filter = static function ( $allcaps ) {
			$allcaps['edit_theme_options'] = true;
			$allcaps['edit_others_posts']  = true;
			$allcaps['unfiltered_upload']  = true;
			return $allcaps;
		};
		add_filter( 'user_has_cap', $this->cap_grant_filter );
	}

	private function revoke_font_caps(): void {
		if ( null !== $this->cap_grant_filter ) {
			remove_filter( 'user_has_cap', $this->cap_grant_filter );
			$this->cap_grant_filter = null;
		}
	}

	// ─────────────────────────────────────────────────── FAMILY (REST)

	private function rest_create_family( string $family, string $slug ): ?int {
		$settings = [
			'name'       => $family,
			'slug'       => $slug,
			'fontFamily' => sprintf( '"%s", sans-serif', $family ),
		];
		$req = new \WP_REST_Request( 'POST', '/wp/v2/font-families' );
		// Controller schema declares font_family_settings as string;
		// its sanitize callback JSON-decodes it.
		$req->set_param( 'font_family_settings', wp_json_encode( $settings ) );

		$res = rest_do_request( $req );
		if ( $res->is_error() ) {
			$this->log_rest_error( 'family', $slug, $res );
			return null;
		}
		$data = $res->get_data();
		$id   = (int) ( is_array( $data ) ? ( $data['id'] ?? 0 ) : 0 );
		return $id > 0 ? $id : null;
	}

	// ────────────────────────────────────────────────────── FACE (REST)

	/**
	 * @return array<string,mixed>|null Face settings written to global
	 *                                  styles (with local URL) on
	 *                                  success, null on failure.
	 */
	private function install_one_face( string $family, int $family_id, array $variant ): ?array {
		$remote = (string) $variant['url'];
		if ( isset( $this->sideloaded_by_url[ $remote ] ) ) {
			$local_url = $this->sideloaded_by_url[ $remote ];
		} else {
			$file = $this->download_font_to_temp( $remote );
			if ( null === $file ) {
				return null;
			}
			$local_url = $this->sideload_font_to_uploads( $file );
			if ( null === $local_url ) {
				@unlink( $file['tmp_name'] );
				return null;
			}
			$this->sideloaded_by_url[ $remote ] = $local_url;
		}

		$face_settings = [
			'fontFamily' => sprintf( '"%s", sans-serif', $family ),
			'fontStyle'  => $variant['style'],
			'fontWeight' => $variant['weight'],
			'src'        => $local_url,
		];
		// Subset files (one per unicode range) MUST carry their range —
		// without it the browser uses the partial file for all glyphs
		// and anything outside the subset renders in the fallback font.
		if ( ! empty( $variant['unicode_range'] ) ) {
			$face_settings['unicodeRange'] = $variant['unicode_range'];
		}
		$req = new \WP_REST_Request( 'POST', "/wp/v2/font-families/{$family_id}/font-faces" );
		$req->set_param( 'font_face_settings', wp_json_encode( $face_settings ) );

		$res = rest_do_request( $req );
		if ( $res->is_error() ) {
			$this->log_rest_error( 'face', $family . ' ' . $variant['weight'] . ' ' . $variant['style'], $res );
			return null;
		}
		return $face_settings;
	}

	// ─────────────────────────────────────────────── DOWNLOAD + SIDELOAD

	/**
	 * Stream a remote font URL into a temp file and return a
	 * `$_FILES`-shape array suitable for `wp_handle_sideload()`.
	 *
	 * Validation: HTTP 2xx, non-empty file, magic-byte signature
	 * (`wOF2` / `wOFF` / `\x00\x01\x00\x00` / `OTTO` / `true` /
	 * `typ1`), Content-Length match. Files failing any gate are
	 * unlinked.
	 *
	 * @return array{name:string, type:string, tmp_name:string, error:int, size:int}|null
	 */
	private function download_font_to_temp( string $url ): ?array {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$basename = (string) wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '' === $basename ) {
			$basename = 'font-' . wp_generate_password( 8, false );
		}
		$tmp_path = wp_tempnam( $basename );
		if ( ! $tmp_path ) {
			return null;
		}

		$response = wp_safe_remote_get( $url, [
			'timeout'     => 30,
			'redirection' => 5,
			'stream'      => true,
			'filename'    => $tmp_path,
		] );
		if ( is_wp_error( $response ) ) {
			@unlink( $tmp_path );
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			@unlink( $tmp_path );
			return null;
		}
		$size = (int) @filesize( $tmp_path );
		if ( $size <= 0 ) {
			@unlink( $tmp_path );
			return null;
		}

		// Magic-byte check.
		$magic = (string) @file_get_contents( $tmp_path, false, null, 0, 4 );
		if ( strlen( $magic ) < 4 ) {
			@unlink( $tmp_path );
			return null;
		}
		static $ext_by_magic = [
			'wOF2'             => 'woff2',
			'wOFF'             => 'woff',
			"\x00\x01\x00\x00" => 'ttf',
			'OTTO'             => 'otf',
			'true'             => 'ttf',
			'typ1'             => 'ttf',
		];
		if ( ! isset( $ext_by_magic[ $magic ] ) ) {
			@unlink( $tmp_path );
			return null;
		}
		$canonical_ext = $ext_by_magic[ $magic ];

		// Content-Length sanity (skip on chunked).
		$expected = wp_remote_retrieve_header( $response, 'content-length' );
		if ( '' !== (string) $expected && (int) $expected !== $size ) {
			@unlink( $tmp_path );
			return null;
		}

		// Rename to canonical extension so `wp_handle_upload`'s
		// MIME sniff agrees with the file content.
		$current_ext = strtolower( (string) pathinfo( $tmp_path, PATHINFO_EXTENSION ) );
		if ( $current_ext !== $canonical_ext ) {
			$new_path = preg_replace( '/\.[^.\/\\\\]+$/', '', $tmp_path ) . '.' . $canonical_ext;
			if ( @rename( $tmp_path, $new_path ) ) {
				$tmp_path = $new_path;
			}
		}
		$final_name = preg_replace( '/\.[^.\/\\\\]+$/', '', wp_basename( $tmp_path ) ) . '.' . $canonical_ext;

		return [
			'name'     => $final_name,
			'type'     => $this->mime_for_extension( $canonical_ext ),
			'tmp_name' => $tmp_path,
			'error'    => 0,
			'size'     => $size,
		];
	}

	/**
	 * Move a validated temp font file into the canonical fonts dir
	 * and return its public URL.
	 *
	 * Filters:
	 *   - `upload_mimes` → widen allowed list to woff2/woff/ttf/otf.
	 *   - `upload_dir` → route into `wp-content/uploads/fonts/` (or
	 *     `wp-content/fonts/` on newer WP) via the core helper
	 *     `_wp_filter_font_directory`.
	 */
	private function sideload_font_to_uploads( array $file ): ?string {
		if ( ! function_exists( 'wp_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$mimes_filter = [ '\\WP_Font_Utils', 'get_allowed_font_mime_types' ];
		add_filter( 'upload_mimes', $mimes_filter );
		if ( function_exists( '_wp_filter_font_directory' ) ) {
			add_filter( 'upload_dir', '_wp_filter_font_directory' );
		}

		$allowed_mimes = class_exists( '\\WP_Font_Utils' ) && method_exists( '\\WP_Font_Utils', 'get_allowed_font_mime_types' )
			? \WP_Font_Utils::get_allowed_font_mime_types()
			: [
				'otf'   => 'application/vnd.ms-opentype',
				'ttf'   => PHP_VERSION_ID >= 70400 ? 'font/sfnt' : 'application/font-sfnt',
				'woff'  => PHP_VERSION_ID >= 80112 ? 'font/woff'  : 'application/font-woff',
				'woff2' => PHP_VERSION_ID >= 80112 ? 'font/woff2' : 'application/font-woff2',
			];

		$overrides = [
			'test_form' => false,
			'mimes'     => $allowed_mimes,
		];

		// `wp_handle_sideload` takes its first arg by reference —
		// must hold the array in a variable.
		$file_ref = $file;
		$sideload = wp_handle_sideload( $file_ref, $overrides );

		remove_filter( 'upload_mimes', $mimes_filter );
		if ( function_exists( '_wp_filter_font_directory' ) ) {
			remove_filter( 'upload_dir', '_wp_filter_font_directory' );
		}

		if ( ! is_array( $sideload ) || isset( $sideload['error'] ) || empty( $sideload['url'] ) ) {
			// Log so we can see why uploads silently fail.
			$err = is_array( $sideload ) && isset( $sideload['error'] ) ? (string) $sideload['error'] : 'unknown sideload error';
			error_log( '[fdi font] sideload failed: ' . $err . ' (file=' . ( $file['name'] ?? '?' ) . ')' );
			return null;
		}
		return (string) $sideload['url'];
	}

	private function mime_for_extension( string $ext ): string {
		static $map = [
			'woff2' => 'font/woff2',
			'woff'  => 'font/woff',
			'ttf'   => 'font/ttf',
			'otf'   => 'font/otf',
		];
		return $map[ $ext ] ?? 'application/octet-stream';
	}

	private function log_rest_error( string $kind, string $context, \WP_REST_Response $res ): void {
		$err  = $res->as_error();
		$data = $res->get_data();
		$msg  = is_wp_error( $err )
			? $err->get_error_message()
			: ( is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'unknown' );
		error_log( sprintf( '[fdi font] REST %s install failed for %s: %s', $kind, $context, $msg ) );
	}

	// ─────────────────────────────────────────── VARIANT RESOLUTION

	/**
	 * Primary source: WP core's bundled "google-fonts" font collection —
	 * the exact face list the Font Library UI installs when the user
	 * picks the family by hand. One face per (weight, style), each src a
	 * single FULL-COVERAGE file (all unicode subsets in one .woff2), so
	 * Noto Sans is 18 faces, not 18 × N-subsets. No `unicode-range`
	 * bookkeeping needed.
	 *
	 * Fallback: the Google css2 endpoint (subset slicing + unicodeRange)
	 * for installs where the collection isn't available — core older
	 * than the collection API, or s.w.org unreachable from this host.
	 *
	 * @return array<int, array{url:string, weight:string, style:string, unicode_range?:string}>
	 */
	private function resolve_variants( string $family ): array {
		$variants = $this->collection_variants( $family );
		if ( ! empty( $variants ) ) {
			return $variants;
		}

		$catalogue = $this->catalogue_variants( $family );
		if ( empty( $catalogue ) ) {
			return [];
		}
		$css = $this->fetch_google_fonts_css( $family, $catalogue );
		if ( '' === $css ) {
			return [];
		}
		return $this->parse_font_face_blocks( $css );
	}

	/**
	 * Look the family up in core's "google-fonts" collection and map its
	 * fontFace list to our variant shape. Empty array when the family
	 * isn't in the collection or the collection can't load (triggers the
	 * css2 fallback in {@see resolve_variants()}).
	 *
	 * The first call may fetch + cache the collection JSON from s.w.org
	 * (core stores it in a site transient) — slow once, instant after.
	 *
	 * @return array<int, array{url:string, weight:string, style:string}>
	 */
	private function collection_variants( string $family ): array {
		if ( ! class_exists( '\\WP_Font_Library' ) ) {
			return [];
		}
		$collection = \WP_Font_Library::get_instance()->get_font_collection( 'google-fonts' );
		if ( ! $collection instanceof \WP_Font_Collection ) {
			return [];
		}
		$data = $collection->get_data();
		if ( is_wp_error( $data ) || empty( $data['font_families'] ) || ! is_array( $data['font_families'] ) ) {
			return [];
		}

		foreach ( $data['font_families'] as $entry ) {
			$settings = is_array( $entry ) ? ( $entry['font_family_settings'] ?? null ) : null;
			if ( ! is_array( $settings ) || ( $settings['name'] ?? '' ) !== $family ) {
				continue;
			}
			$out = [];
			foreach ( (array) ( $settings['fontFace'] ?? [] ) as $face ) {
				if ( ! is_array( $face ) ) {
					continue;
				}
				$src = $face['src'] ?? '';
				if ( is_array( $src ) ) {
					$src = reset( $src );
				}
				if ( ! is_string( $src ) || '' === $src ) {
					continue;
				}
				$out[] = [
					'url'    => $src,
					'weight' => isset( $face['fontWeight'] ) ? (string) $face['fontWeight'] : '400',
					'style'  => isset( $face['fontStyle'] ) ? (string) $face['fontStyle'] : 'normal',
				];
			}
			return $out;
		}
		return [];
	}

	/**
	 * @return string[]
	 */
	private function catalogue_variants( string $family ): array {
		$path = get_template_directory() . '/build/fonts/google-fonts.json';
		if ( ! is_readable( $path ) ) {
			return [];
		}
		$raw = (string) file_get_contents( $path );
		$catalog = json_decode( $raw, true );
		if ( ! is_array( $catalog ) ) {
			return [];
		}
		$entry = $catalog[ $family ] ?? null;
		if ( ! is_array( $entry ) ) {
			return [];
		}
		$variants = $entry['variants'] ?? [];
		return is_array( $variants )
			? array_values( array_filter( array_map( 'strval', $variants ) ) )
			: [];
	}

	private function fetch_google_fonts_css( string $family, array $variants ): string {
		$tuples = [];
		foreach ( $variants as $v ) {
			$weight = $this->normalize_weight( $v );
			$style  = false !== strpos( $v, 'italic' ) ? 1 : 0;
			$tuples[ $style . ',' . $weight ] = [ (int) $style, $weight ];
		}
		if ( empty( $tuples ) ) {
			return '';
		}
		uksort( $tuples, static function ( $a, $b ) {
			[ $sa, $wa ] = array_map( 'intval', explode( ',', $a ) );
			[ $sb, $wb ] = array_map( 'intval', explode( ',', $b ) );
			if ( $sa !== $sb ) return $sa - $sb;
			return $wa - $wb;
		} );

		$pairs = [];
		foreach ( $tuples as [ $style, $weight ] ) {
			$pairs[] = $style . ',' . $weight;
		}
		$url = sprintf(
			'https://fonts.googleapis.com/css2?family=%s:ital,wght@%s&display=swap',
			rawurlencode( $family ),
			implode( ';', $pairs )
		);

		// Modern UA → woff2 (smaller, what Font Library prefers).
		$res = wp_safe_remote_get( $url, [
			'timeout'    => 15,
			'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 13_0) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
		] );
		if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) >= 300 ) {
			return '';
		}
		return (string) wp_remote_retrieve_body( $res );
	}

	/**
	 * Parse Google's response CSS into one face entry per subset block.
	 *
	 * CRITICAL: Google ships one `@font-face` per unicode subset and
	 * each block points at a DIFFERENT .woff2 holding only that
	 * subset's glyphs (cyrillic first, latin last). Keeping a single
	 * block per (weight, style) — as an earlier revision did — stores
	 * a Cyrillic-only file under the right family name, so Latin and
	 * Vietnamese text silently falls back to the next font in the
	 * stack. We must keep every wanted subset AND carry its
	 * `unicode-range` through to the Font Library face, mirroring
	 * exactly what the Google CDN serves.
	 *
	 * Subsets are limited to a filterable allowlist to bound the
	 * download count (each subset of each variant is a separate file).
	 *
	 * @return array<int, array{url:string, weight:string, style:string, unicode_range:string, subset:string}>
	 */
	private function parse_font_face_blocks( string $css ): array {
		$wanted = (array) apply_filters(
			'ft_demo_importer_font_subsets',
			[ 'latin', 'latin-ext', 'vietnamese' ]
		);

		$by_key = [];
		// Subset name arrives as a `/* latin */` comment right before
		// each block. Capture it together so we can filter.
		if ( ! preg_match_all( '/(?:\/\*\s*([\w-]+)\s*\*\/\s*)?@font-face\s*\{([^}]+)\}/i', $css, $blocks, PREG_SET_ORDER ) ) {
			return [];
		}
		foreach ( $blocks as $b ) {
			$subset = strtolower( trim( $b[1] ?? '' ) );
			$block  = $b[2];
			// Unlabelled blocks (no subset comment — e.g. a legacy-UA
			// response with one full-coverage file) always pass.
			if ( '' !== $subset && ! in_array( $subset, $wanted, true ) ) {
				continue;
			}
			$weight = '400';
			$style  = 'normal';
			if ( preg_match( '/font-weight:\s*([0-9]+)/i', $block, $m ) ) {
				$weight = $m[1];
			}
			if ( preg_match( '/font-style:\s*(italic|normal)/i', $block, $m ) ) {
				$style = strtolower( $m[1] );
			}
			$key = $weight . '|' . $style . '|' . $subset;
			if ( isset( $by_key[ $key ] ) ) {
				continue;
			}
			if ( ! preg_match( '/src:\s*url\(([^)]+)\)/i', $block, $m ) ) {
				continue;
			}
			$src = trim( $m[1], "'\" " );
			if ( '' === $src ) {
				continue;
			}
			$unicode_range = '';
			if ( preg_match( '/unicode-range:\s*([^;}]+)/i', $block, $m ) ) {
				$unicode_range = trim( $m[1] );
			}
			$by_key[ $key ] = [
				'url'           => $src,
				'weight'        => $weight,
				'style'         => $style,
				'unicode_range' => $unicode_range,
				'subset'        => $subset,
			];
		}
		return array_values( $by_key );
	}

	private function normalize_weight( string $variant_key ): string {
		$key = strtolower( str_replace( 'italic', '', $variant_key ) );
		$key = trim( $key );
		if ( '' === $key || 'regular' === $key ) {
			return '400';
		}
		$w = (int) $key;
		if ( $w < 100 ) return '400';
		if ( $w > 900 ) return '900';
		return (string) $w;
	}

	// ─────────────────────────────────────────── GLOBAL STYLES ACTIVATE

	/**
	 * @param array<int, array<string,mixed>> $face_settings_list
	 */
	private function activate_in_global_styles( string $family, string $slug, array $face_settings_list ): void {
		$post = $this->get_user_global_styles_post();
		if ( null === $post ) {
			return;
		}

		$content = json_decode( (string) $post->post_content, true );
		if ( ! is_array( $content ) ) {
			$content = [];
		}
		if ( ! isset( $content['version'] ) ) {
			$content['version'] = class_exists( '\\WP_Theme_JSON' ) ? \WP_Theme_JSON::LATEST_SCHEMA : 3;
		}
		$content['isGlobalStylesUserThemeJSON'] = true;

		if ( ! isset( $content['settings']['typography']['fontFamilies']['custom'] )
			|| ! is_array( $content['settings']['typography']['fontFamilies']['custom'] ) ) {
			$content['settings']['typography']['fontFamilies']['custom'] = [];
		}

		$entry = [
			'name'       => $family,
			'slug'       => $slug,
			'fontFamily' => sprintf( '"%s", sans-serif', $family ),
			'fontFace'   => $face_settings_list,
		];

		$found = false;
		foreach ( $content['settings']['typography']['fontFamilies']['custom'] as $i => $existing ) {
			if ( is_array( $existing ) && ( $existing['slug'] ?? '' ) === $slug ) {
				$content['settings']['typography']['fontFamilies']['custom'][ $i ] = $entry;
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			$content['settings']['typography']['fontFamilies']['custom'][] = $entry;
		}

		wp_update_post( [
			'ID'           => $post->ID,
			'post_content' => wp_slash( wp_json_encode( $content ) ),
		] );

		if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
			wp_clean_theme_json_cache();
		}
		if ( class_exists( '\\WP_Theme_JSON_Resolver' )
			&& method_exists( '\\WP_Theme_JSON_Resolver', 'clean_cached_data' ) ) {
			\WP_Theme_JSON_Resolver::clean_cached_data();
		}
	}

	private function get_user_global_styles_post(): ?\WP_Post {
		if ( ! class_exists( '\\WP_Theme_JSON_Resolver' )
			|| ! method_exists( '\\WP_Theme_JSON_Resolver', 'get_user_global_styles_post_id' ) ) {
			return null;
		}
		$post_id = (int) \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		if ( $post_id <= 0 ) {
			return null;
		}
		$post = get_post( $post_id );
		return $post instanceof \WP_Post ? $post : null;
	}

	// ──────────────────────────────────────────────────── WIPE

	private function wipe_existing_family( int $family_id, string $slug ): void {
		$faces = get_posts( [
			'post_type'      => self::FACE_POST_TYPE,
			'post_status'    => 'any',
			'post_parent'    => $family_id,
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );
		foreach ( $faces as $face_id ) {
			wp_delete_post( (int) $face_id, true );
		}
		wp_delete_post( $family_id, true );

		// Drop slug from global styles too.
		$post = $this->get_user_global_styles_post();
		if ( null === $post ) {
			return;
		}
		$content = json_decode( (string) $post->post_content, true );
		if ( ! is_array( $content ) ) {
			return;
		}
		$list = $content['settings']['typography']['fontFamilies']['custom'] ?? null;
		if ( ! is_array( $list ) ) {
			return;
		}
		$filtered = [];
		$changed  = false;
		foreach ( $list as $entry ) {
			if ( is_array( $entry ) && ( $entry['slug'] ?? '' ) === $slug ) {
				$changed = true;
				continue;
			}
			$filtered[] = $entry;
		}
		if ( ! $changed ) {
			return;
		}
		$content['settings']['typography']['fontFamilies']['custom'] = array_values( $filtered );
		wp_update_post( [
			'ID'           => $post->ID,
			'post_content' => wp_slash( wp_json_encode( $content ) ),
		] );
	}
}
