<?php
/**
 * Registry + resolver for {@see Theme_Adapter} instances.
 *
 * Adapters self-register via {@see Adapter_Registry::register()} (called
 * from each adapter's class file or from an integrator's plugin/theme).
 * The default catch-all adapter is registered last and is returned by
 * {@see resolve()} when no adapter claims the active theme.
 */

namespace FT_Demo_Importer;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use FT_Demo_Importer\Adapters\Theme_Adapter;
use FT_Demo_Importer\Adapters\Default_Adapter;

class Adapter_Registry {

	/** @var array<string, Theme_Adapter> slug → adapter */
	private static array $by_slug = [];

	/** @var Theme_Adapter|null */
	private static ?Theme_Adapter $default = null;

	/**
	 * Register an adapter for every slug it claims.
	 *
	 * Calling twice with the same slug overwrites — last-registered wins,
	 * which lets a child plugin replace a built-in adapter without code
	 * surgery.
	 */
	public static function register( Theme_Adapter $adapter ): void {
		foreach ( $adapter->supported_slugs() as $slug ) {
			self::$by_slug[ (string) $slug ] = $adapter;
		}
	}

	/**
	 * Resolve the adapter that should handle the given theme.
	 *
	 * @param string $template   Active parent theme stylesheet (`get_option('template')`).
	 * @param string $stylesheet Active child theme stylesheet (`get_option('stylesheet')`).
	 * @return Theme_Adapter
	 */
	public static function resolve( string $template, string $stylesheet = '' ): Theme_Adapter {
		// 1. Child theme exact match wins (so a Customify-child can ship
		//    its own adapter without inheriting Customify's plugin list).
		if ( '' !== $stylesheet && isset( self::$by_slug[ $stylesheet ] ) ) {
			return self::$by_slug[ $stylesheet ];
		}
		// 2. Parent theme exact match.
		if ( '' !== $template && isset( self::$by_slug[ $template ] ) ) {
			return self::$by_slug[ $template ];
		}
		// 3. Filter hook — last-resort opportunity to inject a custom adapter
		//    based on whatever signal the integrator chooses.
		$override = apply_filters( 'ft_demo_importer_resolve_adapter', null, $template, $stylesheet );
		if ( $override instanceof Theme_Adapter ) {
			return $override;
		}
		// 4. Default catch-all.
		return self::default_adapter();
	}

	/**
	 * Lazy-instantiate the Default_Adapter so importing this file doesn't
	 * force a no-op object into memory on requests that never need it.
	 */
	public static function default_adapter(): Theme_Adapter {
		if ( null === self::$default ) {
			require_once __DIR__ . '/Adapters/class-default-adapter.php';
			self::$default = new Default_Adapter();
		}
		return self::$default;
	}

	/**
	 * Wipe — used by tests + when the active theme switches and we want
	 * Bootstrap to re-register adapters under the new context.
	 */
	public static function reset(): void {
		self::$by_slug = [];
		self::$default = null;
	}
}
