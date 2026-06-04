<?php
/**
 * Step 2 — install + activate plugins declared by the template + adapter.
 *
 * Ported from `blocksify-design-importer/includes/Theme/PluginInstaller.php`
 * with the added `$adapter_extra` parameter so an adapter can layer
 * theme-specific recommendations on top of what options.json declares.
 *
 * Flow per plugin:
 *   already active                  → nothing
 *   present on disk, inactive       → activate_plugin()
 *   missing, source = wordpress.org → plugins_api + Plugin_Upgrader::install → activate
 *   missing, other source           → warning + skip (no download URL)
 *
 * Errors never throw — every failure becomes a warning so content import
 * can still proceed. Posts whose CPT was provided by a missing plugin
 * get skipped downstream by Content_Importer's post_type_exists guard.
 */

namespace FT_Demo_Importer\Steps;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Plugin_Installer {

	/**
	 * @param string                                            $options_json_path
	 * @param string[]                                          $plugins_skip   Slugs the wizard step asked to skip.
	 * @param array<int, array{slug:string, name?:string, file?:string, source?:string}> $adapter_extra
	 *
	 * @return array{installed:string[], activated:string[], warnings:string[]}
	 */
	public function install_and_activate( string $options_json_path, array $plugins_skip = [], array $adapter_extra = [] ): array {
		$result = [
			'installed' => [],
			'activated' => [],
			'warnings'  => [],
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

		if ( empty( $plugins ) ) {
			return $result;
		}

		$this->ensure_admin_loaded();
		$skip_set = array_flip( $plugins_skip );

		foreach ( $plugins as $plugin ) {
			$slug   = (string) ( $plugin['slug']   ?? '' );
			$file   = (string) ( $plugin['file']   ?? '' );
			$source = (string) ( $plugin['source'] ?? 'wordpress.org' );
			if ( '' === $slug ) {
				continue;
			}
			if ( isset( $skip_set[ $slug ] ) ) {
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
				$this->activate( $slug, $file, $result );
				continue;
			}

			if ( 'wordpress.org' === $source ) {
				if ( $this->install_from_wporg( $slug, $result ) ) {
					$this->activate( $slug, $file, $result );
				}
			} else {
				$result['warnings'][] = sprintf(
					'Plugin "%s" not installed and source "%s" is not on wordpress.org — skipped.',
					$slug,
					$source ?: 'unknown'
				);
			}
		}

		return $result;
	}

	// ----------------------------------------------------------------------

	private function activate( string $slug, string $file, array &$result ): void {
		$res = activate_plugin( $file );
		if ( is_wp_error( $res ) ) {
			$result['warnings'][] = sprintf(
				'Plugin "%s" activation failed: %s',
				$slug,
				$res->get_error_message()
			);
			return;
		}
		$result['activated'][] = $slug;
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
