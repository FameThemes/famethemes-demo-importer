<?php
/**
 * Step 5 — apply the settings half of the template (options + theme mods +
 * customizer + widgets + plugin options).
 *
 * Ported from `blocksify-design-importer/includes/Theme/OptionsImporter.php`
 * with one addition: the runner can pass an `adapter_customizer_keys`
 * overlay (via $opts) that gets merged on top of options.json's own
 * customizer_keys block — that's how a theme-specific adapter contributes
 * extra post/page/media remappings without forking this class.
 *
 * Layers (each try/catch — one failure doesn't kill the rest):
 *   1. Checksums (always)        — verify content.json + uploads.zip sha256.
 *   2. Theme requirement (always)— log mismatch, never auto-switch.
 *   3. Core options    (gated)   — show_on_front / page_on_front / page_for_posts.
 *   4. Theme mods       (gated)  — set_theme_mod per key, `_ref` resolved.
 *   5. Customizer       (gated)  — same shape as theme mods.
 *   6. Widgets          (gated)  — sidebars_widgets + per-widget option keys.
 *   7. Plugin options   (gated)  — whitelisted `wp_options` rows.
 *
 * Gated layers run only when `opts.replace_settings === true`. On first
 * successful gated run, `ft_demo_importer_settings_applied=1` marker is
 * stamped so subsequent imports can show a "settings already applied —
 * really replace?" confirmation in the wizard.
 */

namespace FT_Demo_Importer\Steps;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Options_Importer {

	/** Sticky marker — UI checks this to decide whether to show the
	 *  Confirm step on subsequent imports. Set once, never auto-cleared. */
	public const OPTION_SETTINGS_APPLIED = 'ft_demo_importer_settings_applied';

	/**
	 * @param string                  $options_json_path
	 * @param array<string,int>       $ref_map  From Content_Importer.
	 * @param array{
	 *     replace_settings?:        bool,
	 *     content_json_path?:       string,
	 *     uploads_zip_path?:        string,
	 *     adapter_customizer_keys?: array,
	 * } $opts
	 *
	 * @return array{applied:array<string,bool>, warnings:string[]}
	 */
	public function apply( string $options_json_path, array $ref_map, array $opts = [] ): array {
		if ( '' === $options_json_path || ! is_readable( $options_json_path ) ) {
			throw new \RuntimeException( 'Could not read options.json' );
		}
		$raw = @file_get_contents( $options_json_path );
		if ( false === $raw ) {
			throw new \RuntimeException( 'Could not read options.json' );
		}
		$parsed = json_decode( $raw, true );
		if ( ! is_array( $parsed ) ) {
			throw new \RuntimeException( 'options.json is not valid JSON.' );
		}

		// Adapter overlay → merge into the parsed customizer_keys block so
		// downstream layers see one unified map.
		$adapter_keys = (array) ( $opts['adapter_customizer_keys'] ?? [] );
		if ( ! empty( $adapter_keys ) ) {
			$existing = isset( $parsed['customizer_keys'] ) && is_array( $parsed['customizer_keys'] )
				? $parsed['customizer_keys']
				: [];
			$parsed['customizer_keys'] = array_replace( $adapter_keys, $existing );
			// options.json wins on conflict — explicit per template trumps
			// adapter's theme-wide default.
		}

		$applied         = [];
		$warnings        = [];
		$replace_settings = ! empty( $opts['replace_settings'] );

		$this->verify_checksums( $parsed, $opts, $warnings );
		$applied['checksums'] = true;

		$this->check_theme_requirement( $parsed, $warnings );
		$applied['theme_check'] = true;

		if ( $replace_settings ) {
			$applied['core']           = $this->apply_core( $parsed, $ref_map, $warnings );
			$applied['theme_mods']     = $this->apply_theme_mods( $parsed, $ref_map, $warnings );
			$applied['customizer']     = $this->apply_customizer( $parsed, $ref_map, $warnings );
			$applied['widgets']        = $this->apply_widgets( $parsed, $ref_map, $warnings );
			$applied['plugin_options'] = $this->apply_plugin_options( $parsed, $ref_map, $warnings );
			update_option( self::OPTION_SETTINGS_APPLIED, 1, true );
		}

		return [ 'applied' => $applied, 'warnings' => $warnings ];
	}

	// ----------------------------------------------------------------------

	private function verify_checksums( array $parsed, array $opts, array &$warnings ): void {
		$checksums = $parsed['_meta']['checksums'] ?? null;
		if ( ! is_array( $checksums ) ) {
			return;
		}
		$pairs = [
			'content.json' => $opts['content_json_path'] ?? null,
			'uploads.zip'  => $opts['uploads_zip_path']  ?? null,
		];
		foreach ( $pairs as $name => $path ) {
			$expected = (string) ( $checksums[ $name ] ?? '' );
			if ( '' === $expected || ! is_string( $path ) || '' === $path || ! file_exists( $path ) ) {
				continue;
			}
			$expected = preg_replace( '/^sha256:/i', '', $expected );
			$actual   = hash_file( 'sha256', $path );
			if ( $actual !== $expected ) {
				$warnings[] = sprintf( '%s checksum mismatch (expected %s, got %s).', $name, $expected, $actual );
			}
		}
	}

	private function check_theme_requirement( array $parsed, array &$warnings ): void {
		$req = $parsed['requirements']['theme'] ?? null;
		if ( ! is_array( $req ) ) {
			return;
		}
		$wanted = (string) ( $req['stylesheet'] ?? '' );
		if ( '' === $wanted ) {
			return;
		}
		if ( get_stylesheet() !== $wanted ) {
			$warnings[] = sprintf(
				'Theme mismatch: source used "%s", active is "%s". Theme switch is out of scope; mods may not apply cleanly.',
				$wanted,
				get_stylesheet()
			);
		}
	}

	private function apply_core( array $parsed, array $ref_map, array &$warnings ): bool {
		$core = $parsed['core'] ?? [];
		if ( ! is_array( $core ) || empty( $core ) ) {
			return false;
		}
		if ( array_key_exists( 'show_on_front', $core ) ) {
			update_option( 'show_on_front', (string) $core['show_on_front'] );
		}
		if ( ! empty( $core['page_on_front_ref'] ) ) {
			$id = (int) ( $ref_map[ (string) $core['page_on_front_ref'] ] ?? 0 );
			if ( $id > 0 ) {
				update_option( 'page_on_front', $id );
			} else {
				$warnings[] = 'core.page_on_front_ref unresolved — left untouched.';
			}
		}
		if ( ! empty( $core['page_for_posts_ref'] ) ) {
			$id = (int) ( $ref_map[ (string) $core['page_for_posts_ref'] ] ?? 0 );
			if ( $id > 0 ) {
				update_option( 'page_for_posts', $id );
			} else {
				$warnings[] = 'core.page_for_posts_ref unresolved — left untouched.';
			}
		}
		return true;
	}

	private function apply_theme_mods( array $parsed, array $ref_map, array &$warnings ): bool {
		$mods = $parsed['theme']['mods'] ?? null;
		if ( ! is_array( $mods ) ) {
			return false;
		}
		foreach ( $mods as $key => $value ) {
			$resolved_key = (string) $key;
			$resolved_val = $this->resolve_refs( $value, $ref_map, $warnings );
			if ( str_ends_with( $resolved_key, '_ref' ) ) {
				$resolved_key = substr( $resolved_key, 0, -4 );
			}
			set_theme_mod( $resolved_key, $resolved_val );
		}
		return true;
	}

	private function apply_customizer( array $parsed, array $ref_map, array &$warnings ): bool {
		$cust = $parsed['customizer'] ?? null;
		if ( ! is_array( $cust ) ) {
			return false;
		}
		foreach ( $cust as $key => $value ) {
			set_theme_mod( (string) $key, $this->resolve_refs( $value, $ref_map, $warnings ) );
		}
		return true;
	}

	/**
	 * Restore `wp_options` rows the submitter exported under the prefix-
	 * based whitelist (e.g. `astra-settings`, `astra-color-palettes`,
	 * `sureforms_*`). Without this layer, the site falls back to default
	 * theme settings and looks visually nothing like the source — Astra
	 * stores layout config in `astra-settings`, not theme_mods.
	 *
	 * Defensive: anything prefixed `pmbd_` is rejected so the importer
	 * site's own state can't be corrupted by a malicious manifest.
	 */
	private function apply_plugin_options( array $parsed, array $ref_map, array &$warnings ): bool {
		$plugin_options = $parsed['plugin_options'] ?? null;
		if ( ! is_array( $plugin_options ) || empty( $plugin_options ) ) {
			return false;
		}
		foreach ( $plugin_options as $key => $value ) {
			$option_name = (string) $key;
			if ( '' === $option_name ) {
				continue;
			}
			if ( 0 === strpos( $option_name, 'pmbd_' ) || 0 === strpos( $option_name, 'ft_demo_importer_' ) ) {
				$warnings[] = "Refused to import importer-prefixed option: $option_name";
				continue;
			}
			$resolved = $this->resolve_refs( $value, $ref_map, $warnings );
			update_option( $option_name, $resolved, false );
		}
		return true;
	}

	private function apply_widgets( array $parsed, array $ref_map, array &$warnings ): bool {
		$widgets = $parsed['widgets'] ?? null;
		if ( ! is_array( $widgets ) ) {
			return false;
		}
		if ( isset( $widgets['sidebars_widgets'] ) ) {
			update_option( 'sidebars_widgets', $widgets['sidebars_widgets'] );
		}
		if ( isset( $widgets['options'] ) && is_array( $widgets['options'] ) ) {
			foreach ( $widgets['options'] as $option_name => $option_value ) {
				update_option( (string) $option_name, $option_value );
			}
		}
		return true;
	}

	/**
	 * Walk arbitrary values, replacing synthetic refs (`{{ref:post:N}}`
	 * markers + bare `post:N`/`term:N` strings) with resolved local IDs.
	 * Also strips `_ref` suffix from array keys so callers can use either
	 * `key` or `key_ref` form interchangeably.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private function resolve_refs( $value, array $ref_map, array &$warnings ) {
		if ( is_string( $value ) ) {
			if ( preg_match( '/^post:\d+$/', $value ) || preg_match( '/^term:\d+$/', $value ) ) {
				return (int) ( $ref_map[ $value ] ?? 0 );
			}
			return (string) preg_replace_callback(
				'/\{\{ref:(post|term):(\d+)(:missing)?\}\}/',
				static function ( array $m ) use ( $ref_map ): string {
					$ref = "{$m[1]}:{$m[2]}";
					return (string) (int) ( $ref_map[ $ref ] ?? 0 );
				},
				$value
			);
		}
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( $value as $k => $v ) {
				$resolved_key = (string) $k;
				if ( str_ends_with( $resolved_key, '_ref' ) && is_string( $v ) ) {
					$resolved_key = substr( $resolved_key, 0, -4 );
				}
				$out[ $resolved_key ] = $this->resolve_refs( $v, $ref_map, $warnings );
			}
			return $out;
		}
		return $value;
	}
}
