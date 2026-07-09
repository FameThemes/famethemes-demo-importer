<?php
/**
 * Step 2 — install + activate plugins declared by the template + adapter.
 *
 * Ported from `blocksify-design-importer/includes/Theme/PluginInstaller.php`
 * with the added `$adapter_extra` parameter so an adapter can layer
 * theme-specific recommendations on top of what options.json declares.
 *
 * Flow per plugin (matches `docs/submitter/site-submit.md` §Theme adapter steps):
 *   already active                                  → nothing
 *   present on disk, inactive                       → activate_plugin()
 *   missing, source = wordpress.org                 → plugins_api + Plugin_Upgrader::install → activate
 *   missing, source != wordpress.org, required=true → recorded in `required_missing[]` (no download URL — caller must surface)
 *   missing, source != wordpress.org, required=false → warning + skip
 *
 * `required: true` entries that end up not active for ANY reason (missing
 * + no wp.org source, install error, activation error) are pushed into
 * `required_missing[]`. The runner reads that array and aborts the job
 * before content/options apply — content for a missing plugin's CPT
 * would silently fail and options for it would never take effect, so
 * partial completion is worse than a clean abort.
 *
 * `required: false` entries follow the legacy soft-fail behaviour —
 * warnings only, the import proceeds.
 *
 * Blocksify is *always* injected into the merged plugin list with
 * `required: true` and a wordpress.org source, even when neither the
 * template manifest nor the adapter declare it. The importer needs the
 * Blocksify block library to render Studio templates, so the install
 * step verifies it's present + active on every run. Any wizard-provided
 * skip flag for `blocksify` is dropped before the install loop.
 */

namespace FT_Demo_Importer\Steps;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Plugin_Installer {

	/** Slug of the baseline plugin always force-installed before any template applies. */
	private const BLOCKSIFY_SLUG = 'blocksify';

	/**
	 * Ecosystem tooling that must never be installed on a demo site, even when a
	 * source site ran it and the manifest lists it. `pm-submitter` is the
	 * contributor-side submit tool and `famethemes-demo-importer` is this
	 * importer itself — neither belongs to a template's runtime. Extend via the
	 * `ft_demo_importer_excluded_plugin_slugs` filter.
	 */
	private const EXCLUDED_SLUGS = [ 'pm-submitter', 'famethemes-demo-importer' ];

	/**
	 * @param string                                            $options_json_path
	 * @param string[]                                          $plugins_skip   Slugs the wizard step asked to skip.
	 * @param array<int, array{slug:string, name?:string, file?:string, source?:string, required?:bool}> $adapter_extra
	 *
	 * @return array{installed:string[], activated:string[], warnings:string[], required_missing:array<int, array{slug:string, name:string, source:string, reason:string}>}
	 */
	public function install_and_activate( string $options_json_path, array $plugins_skip = [], array $adapter_extra = [] ): array {
		$result = [
			'installed'        => [],
			'activated'        => [],
			'warnings'         => [],
			'required_missing' => [],
		];

		// Merge options.json requirements with adapter extras. Options
		// win on conflicts because they're explicit per template (the
		// adapter is theme-scoped, options.json is template-scoped).
		$plugins = [];
		foreach ( $adapter_extra as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['slug'] ) ) {
				$plugins[ (string) $entry['slug'] ] = $entry;
			}
		}

		if ( '' !== $options_json_path && is_readable( $options_json_path ) ) {
			$raw = @file_get_contents( $options_json_path );
			if ( false !== $raw ) {
				$parsed = json_decode( $raw, true );
				if ( is_array( $parsed ) ) {
					$declared = $parsed['requirements']['plugins'] ?? [];
					if ( is_array( $declared ) ) {
						foreach ( $declared as $entry ) {
							if ( is_array( $entry ) && ! empty( $entry['slug'] ) ) {
								$plugins[ (string) $entry['slug'] ] = $entry;
							}
						}
					}
				}
			}
		}

		// Drop ecosystem tooling (the submitter, the importer itself) that a
		// source site happened to run — a demo site must never install it, even
		// when the manifest lists it. Applied BEFORE the Blocksify baseline so
		// the exclusion can never strip Blocksify.
		foreach ( $this->excluded_slugs() as $excluded ) {
			unset( $plugins[ $excluded ] );
		}

		// Baseline importer dependency — Blocksify must be present + active
		// on every run, no matter what the manifest declares. Merge AFTER
		// options/adapter so their declared `file`/`source`/`name` win,
		// then force `required: true`.
		$blocksify_existing = isset( $plugins[ self::BLOCKSIFY_SLUG ] ) && is_array( $plugins[ self::BLOCKSIFY_SLUG ] )
			? $plugins[ self::BLOCKSIFY_SLUG ]
			: [];
		$plugins[ self::BLOCKSIFY_SLUG ] = array_merge(
			[
				'slug'   => self::BLOCKSIFY_SLUG,
				'name'   => 'Blocksify',
				'source' => 'wordpress.org',
			],
			$blocksify_existing,
			[ 'required' => true ]
		);

		if ( empty( $plugins ) ) {
			return $result;
		}

		$this->ensure_admin_loaded();
		$skip_set = array_flip( $plugins_skip );
		// Defensive — never honour a wizard skip flag for Blocksify.
		unset( $skip_set[ self::BLOCKSIFY_SLUG ] );

		// Keep the whole phase headless. Silent activation (below) already
		// skips each plugin's activation hook, but a few plugins hook their
		// "go to the setup wizard" redirect on `plugins_loaded` / `admin_init`
		// first-run detection instead. Inside this import loopback request a
		// `wp_safe_redirect(); exit;` would send a Location header and kill the
		// worker mid-run — so neutralise any redirect attempt for the duration.
		$suppress_redirect = static function () {
			return false;
		};
		add_filter( 'wp_redirect', $suppress_redirect, PHP_INT_MAX );

		try {
			foreach ( $plugins as $plugin ) {
			$slug     = (string) ( $plugin['slug']   ?? '' );
			$name     = (string) ( $plugin['name']   ?? $slug );
			$file     = (string) ( $plugin['file']   ?? '' );
			$source   = (string) ( $plugin['source'] ?? 'wordpress.org' );
			$required = ! empty( $plugin['required'] );
			if ( '' === $slug ) {
				continue;
			}
			if ( isset( $skip_set[ $slug ] ) ) {
				// User explicitly opted out — never blocks even when
				// the manifest marks it required.
				continue;
			}

			// If `file` wasn't declared (common for adapter entries that
			// just know a slug), guess the canonical `slug/slug.php` —
			// works for ~95% of wp.org plugins. The two-step lookup
			// `is_plugin_active` + `file_exists` catches the rest.
			if ( '' === $file ) {
				$file = "{$slug}/{$slug}.php";
			}

			if ( is_plugin_active( $file ) ) {
				continue;
			}

			if ( file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
				if ( ! $this->activate( $slug, $file, $result ) && $required ) {
					$this->mark_required_missing( $result, $slug, $name, $source, 'activation_failed' );
				}
				continue;
			}

			if ( 'wordpress.org' === $source ) {
				if ( $this->install_from_wporg( $slug, $result ) ) {
					if ( ! $this->activate( $slug, $file, $result ) && $required ) {
						$this->mark_required_missing( $result, $slug, $name, $source, 'activation_failed_after_install' );
					}
				} elseif ( $required ) {
					$this->mark_required_missing( $result, $slug, $name, $source, 'install_failed' );
				}
			} else {
				// Non-wp.org source with no download URL in the manifest.
				// For required-true plugins this is fatal — the user needs
				// to install it manually before retrying. The warning still
				// fires for required-false so the surface is uniform.
				$result['warnings'][] = sprintf(
					'Plugin "%s" not installed and source "%s" is not on wordpress.org — skipped.',
					$slug,
					$source ?: 'unknown'
				);
				if ( $required ) {
					$this->mark_required_missing( $result, $slug, $name, $source, 'missing_no_source' );
				}
			}
			}
		} finally {
			remove_filter( 'wp_redirect', $suppress_redirect, PHP_INT_MAX );
		}

		return $result;
	}

	/**
	 * Push a required-plugin failure into the result.
	 *
	 * Idempotent on slug — repeated calls (e.g. activation_failed after
	 * install_failed in a weird retry path) keep only the first reason.
	 */
	private function mark_required_missing( array &$result, string $slug, string $name, string $source, string $reason ): void {
		foreach ( $result['required_missing'] as $existing ) {
			if ( $existing['slug'] === $slug ) {
				return;
			}
		}
		$result['required_missing'][] = [
			'slug'   => $slug,
			'name'   => $name,
			'source' => $source ?: 'unknown',
			'reason' => $reason,
		];
	}

	// ----------------------------------------------------------------------

	private function activate( string $slug, string $file, array &$result ): bool {
		// Silent activation ($silent = true): activate the plugin WITHOUT
		// firing its `register_activation_hook` callback or the
		// activate_plugin / activated_plugin actions. Those are where plugins
		// set a "redirect to the welcome/setup wizard" transient and where
		// some `wp_safe_redirect(); exit;` outright — behaviour that in this
		// headless import loopback request would kill the worker and wedge the
		// job. Silent keeps the import quiet: no redirect, no admin notices, no
		// setup hijack. Matches TGMPA / One-Click-Demo-Import.
		$res = activate_plugin( $file, '', false, true );
		if ( is_wp_error( $res ) ) {
			$result['warnings'][] = sprintf(
				'Plugin "%s" activation failed: %s',
				$slug,
				$res->get_error_message()
			);
			return false;
		}
		$result['activated'][] = $slug;
		return true;
	}

	private function install_from_wporg( string $slug, array &$result ): bool {
		$api = plugins_api( 'plugin_information', [
			'slug'   => $slug,
			'fields' => [
				'sections'          => false,
				'short_description' => false,
				'banners'           => false,
				'screenshots'       => false,
			],
		] );
		if ( is_wp_error( $api ) ) {
			$result['warnings'][] = sprintf(
				'Plugin "%s" lookup on wordpress.org failed: %s',
				$slug,
				$api->get_error_message()
			);
			return false;
		}

		$download_url = (string) ( $api->download_link ?? '' );
		if ( '' === $download_url ) {
			$result['warnings'][] = sprintf( 'Plugin "%s" has no download URL from wordpress.org.', $slug );
			return false;
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$installed = $upgrader->install( $download_url );

		if ( is_wp_error( $installed ) ) {
			$result['warnings'][] = sprintf( 'Plugin "%s" install failed: %s', $slug, $installed->get_error_message() );
			return false;
		}
		if ( ! $installed ) {
			$skin_errors = $skin->get_errors();
			$msg = ( $skin_errors instanceof \WP_Error && $skin_errors->has_errors() )
				? $skin_errors->get_error_message()
				: 'unknown error';
			$result['warnings'][] = sprintf( 'Plugin "%s" install failed: %s', $slug, $msg );
			return false;
		}

		$result['installed'][] = $slug;
		return true;
	}

	/**
	 * Plugin slugs the importer must never install, regardless of the manifest.
	 * Blocksify is force-removed from this list defensively so the baseline
	 * dependency can never be excluded by a filter.
	 *
	 * @return string[]
	 */
	private function excluded_slugs(): array {
		/**
		 * Filter the plugin slugs the importer refuses to install (ecosystem
		 * tooling such as the submitter / the importer itself).
		 *
		 * @param string[] $slugs Default excluded slugs.
		 */
		$slugs = (array) apply_filters( 'ft_demo_importer_excluded_plugin_slugs', self::EXCLUDED_SLUGS );
		$slugs = array_values( array_unique( array_filter( array_map( 'strval', $slugs ), 'strlen' ) ) );
		return array_values( array_diff( $slugs, [ self::BLOCKSIFY_SLUG ] ) );
	}

	private function ensure_admin_loaded(): void {
		if ( ! function_exists( 'activate_plugin' ) || ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
		if ( ! class_exists( '\\Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
		}
		if ( ! class_exists( '\\WP_Ajax_Upgrader_Skin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
		}
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		\WP_Filesystem();
	}
}
