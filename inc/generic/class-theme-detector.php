<?php
/**
 * Thin wrapper around {@see Adapter_Registry::resolve()} that reads the
 * active theme slugs from WP options. Single responsibility: turn "which
 * theme is the user running right now?" into a concrete adapter object.
 *
 * Kept separate from Adapter_Registry so registry tests don't have to
 * stub WP option calls.
 */

namespace FT_Demo_Importer;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use FT_Demo_Importer\Adapters\Theme_Adapter;

class Theme_Detector {

	/**
	 * Read the active theme's stylesheet + template slugs and ask the
	 * registry which adapter handles them.
	 */
	public static function active_adapter(): Theme_Adapter {
		$template   = (string) get_option( 'template' );
		$stylesheet = (string) get_option( 'stylesheet' );
		return Adapter_Registry::resolve( $template, $stylesheet );
	}
}
