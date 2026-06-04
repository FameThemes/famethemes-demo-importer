<?php
/**
 * Catch-all theme adapter — returned by {@see Theme_Detector} when no
 * registered adapter matches the active theme.
 *
 * Intentionally bare: no required plugins, no customizer-key overrides,
 * no post-phase hooks. The generic import pipeline still runs end-to-end
 * for any theme whose Studio template ships a well-formed `content.json`
 * + `options.json`, because the customizer-keys map can be carried
 * inside `options.json` itself.
 */

namespace FT_Demo_Importer\Adapters;

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-theme-adapter.php';

class Default_Adapter extends Theme_Adapter {

	public function supported_slugs(): array {
		// Sentinel — Theme_Detector treats Default_Adapter as the fallback
		// and never asks for its slugs; returning an empty list makes
		// accidental registration into the lookup map a no-op.
		return [];
	}
}
