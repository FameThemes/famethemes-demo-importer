<?php
/*
Plugin Name: FameTheme Demo Importer
Plugin URI: https://github.com/FameThemes/famethemes-demo-importer
Description: Demo data import tool for FameThemes's themes.
Author: FameThemes
Author URI:  http://www.famethemes.com/
Version: 1.2.0
Text Domain: famethemes-demo-importer
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

if (! defined('ABSPATH')) {
    exit;
}

define('DEMO_CONTENT_URL',  trailingslashit(plugins_url('', __FILE__)));
define('DEMO_CONTENT_PATH', trailingslashit(plugin_dir_path(__FILE__)));

/**
 * Boot the importer.
 *
 * The active theme picks exactly one of two tracks:
 *
 *   - **Legacy track** (`inc/legacy/`) — the frozen synchronous AJAX
 *     importer for the FameThemes legacy themes (OnePress / Screenr /
 *     Accelerate / Bizland / Oblique / Shapely / Crispmag / Sparkling /
 *     Cleanblock by default, plus `-pro` and child variants). The list
 *     is filterable via `demo_contents_onepress_themes`.
 *
 *   - **Generic track** (`inc/generic/`) — a background-job pipeline
 *     (cron-spawned runner + transient-backed job state + REST progress
 *     polling) that pulls templates from a Blocksify Design Studio
 *     server. Used by every theme that isn't on the legacy list.
 *
 * The `demo_contents_use_onepress_track` filter force-overrides the
 * decision (useful for staging / debugging).
 *
 * Bootstrap fires for four request types:
 *   - admin pageviews    (`is_admin() === true`)
 *   - AJAX               (`DOING_AJAX`) — Legacy's sync importer worker
 *   - REST API           (`REST_REQUEST`) — Generic's `/ft-demo-importer/v1/...` routes
 *   - WP-Cron            (`DOING_CRON`)   — Generic's `ft_demo_importer_run_job`
 *                                            event needs its action handler
 *                                            registered when wp-cron.php
 *                                            processes the queue
 *
 * Front-end (public site) requests are skipped — the importer has no
 * front-end surface. Without the REST branch the Generic track's REST
 * routes would never register (a REST request has `is_admin() === false`),
 * and the React UI in wp-admin would 404 on every call. Without the
 * cron branch the import job would forever sit at `queued` — wp-cron.php
 * fires the event but no callback exists.
 */
function demo_contents__init()
{
    // REST_REQUEST is defined inside parse_request (after plugins_loaded),
    // so it's not yet set when this hook fires. Sniff the URL the way WP
    // core itself does before the constant is available.
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $rest_prefix = trailingslashit(rest_get_url_prefix());
    $is_rest     = isset($_GET['rest_route'])
        || ('' !== $rest_prefix && false !== strpos($request_uri, '/' . trim($rest_prefix, '/') . '/'));

    $needs_boot = is_admin()
        || (defined('DOING_AJAX')    && DOING_AJAX)
        || (defined('DOING_CRON')    && DOING_CRON)
        || $is_rest;
    if (! $needs_boot) {
        return;
    }

    $template = (string) get_option('template');

    if (demo_contents_is_legacy_theme($template)) {
        require_once DEMO_CONTENT_PATH . 'inc/legacy/bootstrap.php';
    } else {
        require_once DEMO_CONTENT_PATH . 'inc/generic/bootstrap.php';
    }
}
add_action('plugins_loaded', 'demo_contents__init');

/**
 * Determine whether the given theme slug routes to the Legacy track.
 *
 * Resolution order:
 *   1. `demo_contents_onepress_themes` filter — returns a `{slugs[], prefixes[]}`
 *      tuple (or a flat string[] which is treated as the slug list).
 *   2. Exact slug match against `slugs[]`.
 *   3. Prefix match against `prefixes[]` (covers `*-pro` + child themes).
 *   4. `demo_contents_use_onepress_track` filter — last-word override.
 *
 * @param string $template Active parent theme stylesheet.
 * @return bool
 */
function demo_contents_is_legacy_theme($template)
{
    $themes = apply_filters(
        'demo_contents_onepress_themes',
        array(
            'slugs' => array(
                'onepress',
                'screenr',
            ),
            'prefixes' => array(
                'onepress-',
                'accelerate-',
            ),
        ),
        $template
    );

    // Back-compat shim: a filter that returns a flat string[] gets
    // treated as just the slug list.
    if (is_array($themes) && ! isset($themes['slugs']) && ! isset($themes['prefixes'])) {
        $themes = array('slugs' => array_values($themes), 'prefixes' => array());
    }

    $slugs    = isset($themes['slugs'])    && is_array($themes['slugs'])    ? $themes['slugs']    : array();
    $prefixes = isset($themes['prefixes']) && is_array($themes['prefixes']) ? $themes['prefixes'] : array();

    $is_legacy = in_array($template, $slugs, true);
    if (! $is_legacy) {
        foreach ($prefixes as $prefix) {
            if ('' !== (string) $prefix && 0 === strpos($template, (string) $prefix)) {
                $is_legacy = true;
                break;
            }
        }
    }

    /**
     * Filter — force-override the track decision.
     *
     * @param bool   $is_legacy  Whether the Legacy track will load.
     * @param string $template   Active parent theme stylesheet.
     */
    return (bool) apply_filters('demo_contents_use_onepress_track', $is_legacy, $template);
}

/**
 * Post-activation redirect — sends the admin to the right importer
 * surface for the active theme.
 *
 *   - Legacy theme → that theme's own `admin.php?page=ft_<slug>&tab=demo-data-importer`
 *     screen (where this plugin hooks its tab).
 *   - Anything else → the plugin's own dashboard (Generic track UI).
 *
 * Kept at the root file (not inside a track bootstrap) because the
 * `activated_plugin` hook fires immediately on activation — before the
 * `plugins_loaded` callback runs, so a hook registered inside a track
 * bootstrap wouldn't catch it.
 */
function demo_contents_importer_plugin_activate($plugin, $network_wide = false)
{
    if ($network_wide || $plugin !== plugin_basename(__FILE__)) {
        return;
    }
    $template = (string) get_option('template');
    if (demo_contents_is_legacy_theme($template)) {
        $url = admin_url('admin.php?page=ft_' . sanitize_key($template) . '&tab=demo-data-importer');
    } else {
        $url = admin_url('admin.php?page=famethemes-demo-importer');
    }
    wp_safe_redirect($url);
    exit;
}
add_action('activated_plugin', 'demo_contents_importer_plugin_activate', 90, 2);
