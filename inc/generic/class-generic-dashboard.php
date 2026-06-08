<?php
/**
 * "Tools → Demo Contents" admin page for the generic background-job track.
 *
 * Layout intentionally mirrors {@see Demo_Content_Dashboard} (OnePress
 * track) so users see the same chrome regardless of theme — the
 * `.wrap.demo-contents`, `.wp-heading-inline`, `.wp-filter` + tab strip,
 * and Underscore preview template all reuse class names from the
 * OnePress dashboard's stylesheet (`style.css` at plugin root) so we
 * inherit its CSS without forking.
 *
 * What's different is everything BEHIND the UI:
 *   - Theme list comes from the configured Studio (`GET /templates`)
 *     instead of `famethemes.com/wp-json/wp/v2/download/`.
 *   - Import runs as a background WP-Cron job (B4), not a synchronous
 *     `wp_ajax_demo_contents__import` call.
 *   - The Underscore template `#tmpl-ft-demo-importer-preview` posts to
 *     the REST `/theme/jobs` endpoint and polls the job for status.
 *
 * Page rendering: template list only — the plugin no longer ships a
 * settings UI. Studio credentials are sourced from the active adapter
 * (`studio_server_url()` / `studio_api_key()`) with wp-config constants
 * still taking precedence via the bootstrap filter chain.
 */

namespace FT_Demo_Importer;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use FT_Demo_Importer\Settings\Options_Store;

class Generic_Dashboard {

	public const PAGE_SLUG = 'famethemes-demo-importer';

	private Options_Store $options;

	public function __construct( Options_Store $options ) {
		$this->options = $options;
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_init', [ $this, 'maybe_clear_cache' ] );
		// `admin_footer` Underscore template removed when the dashboard
		// moved from jQuery + Underscore → React/wp-components. The
		// wizard modal is now <Modal/> from @wordpress/components,
		// rendered inline by ImportWizard.jsx.
	}

	/**
	 * Page-level cache wipe — visiting the importer page (standalone
	 * OR the host theme's dashboard where the wizard is embedded)
	 * with `?no_cache=1` deletes every importer transient before
	 * anything renders. Wizard then fetches fresh on first paint.
	 *
	 * Gated to `manage_options` since the per-REST endpoint bypass
	 * already gates on the same cap — keeping the URL-level wipe
	 * behind the same surface so admins (and only admins) can use
	 * either approach.
	 *
	 * Scope is the dashboard surfaces only — a random
	 * `?no_cache=1` on, say, `posts.php` doesn't trigger this. The
	 * standalone slug is `Generic_Dashboard::PAGE_SLUG`; the embed
	 * slug comes from the active adapter's `dashboard_parent_slug()`
	 * (Customify_Adapter returns `'customify'`, so the URL
	 * `?page=customify&no_cache=1` works too).
	 */
	public function maybe_clear_cache(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only check on $_GET; the cache wipe itself is gated on manage_options below.
		if ( empty( $_GET['no_cache'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( '' === $page ) {
			return;
		}

		$adapter = apply_filters( 'ft_demo_importer_active_adapter', null );
		$embed_parent = ( $adapter && method_exists( $adapter, 'dashboard_parent_slug' ) )
			? (string) $adapter->dashboard_parent_slug()
			: '';

		$allowed = array_values( array_filter( [ self::PAGE_SLUG, $embed_parent ] ) );
		if ( ! in_array( $page, $allowed, true ) ) {
			return;
		}

		self::wipe_all_transients();
	}

	/**
	 * Delete every transient the importer plugin owns. Matches both
	 * the value row (`_transient_<key>`) and the expiry sibling
	 * (`_transient_timeout_<key>`) so WP's cron-based expiry sweep
	 * has nothing left to garbage-collect.
	 */
	public static function wipe_all_transients(): void {
		global $wpdb;
		$prefix         = $wpdb->esc_like( '_transient_ft_demo_importer_' ) . '%';
		$timeout_prefix = $wpdb->esc_like( '_transient_timeout_ft_demo_importer_' ) . '%';
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$prefix,
			$timeout_prefix
		) );
	}

	public function register_menu(): void {
		// Default: visible submenu under Appearance — `themes.php → Demo Contents`.
		// URL: `themes.php?page=famethemes-demo-importer` (and the catch-all
		// `admin.php?page=famethemes-demo-importer` also resolves).
		//
		// Adapter overrides:
		//   - `dashboard_parent_slug()` lets a theme attach the entry under
		//     its own top-level menu (e.g. Customify → `'customify'`), so
		//     the sidebar listing matches the theme's own admin UX.
		//   - `ft_demo_importer_show_generic_menu` filter still wins when
		//     returned false — the page is registered hidden (parent=null)
		//     and only accessible via direct URL.
		$adapter    = apply_filters( 'ft_demo_importer_active_adapter', null );
		$template   = (string) get_option( 'template' );
		$stylesheet = (string) get_option( 'stylesheet' );

		/**
		 * Show the Generic dashboard's sidebar entry?
		 *
		 * @param bool   $show        Default true.
		 * @param object $adapter     Active Theme_Adapter (or null).
		 * @param string $template    Active parent theme slug.
		 * @param string $stylesheet  Active child theme slug.
		 */
		$show_menu = (bool) apply_filters( 'ft_demo_importer_show_generic_menu', true, $adapter, $template, $stylesheet );

		if ( ! $show_menu ) {
			$parent = null; // hidden submenu — page still routable by URL
		} else {
			$parent = $adapter ? $adapter->dashboard_parent_slug() : null;
			if ( null === $parent ) {
				$parent = 'themes.php';
			}
			/**
			 * Final filter on the parent slug — runs after the adapter
			 * resolves its preference, so integrators can re-route the
			 * entry without writing a full adapter.
			 *
			 * @param string $parent   Parent slug (e.g. `themes.php`, `customify`).
			 * @param object $adapter  Active Theme_Adapter (or null).
			 */
			$parent = (string) apply_filters( 'ft_demo_importer_dashboard_parent_slug', $parent, $adapter );
		}

		add_submenu_page(
			$parent,
			__( 'Starter Templates', 'famethemes-demo-importer' ),
			__( 'Starter Templates', 'famethemes-demo-importer' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'dashboard' ]
		);
	}

	/**
	 * Enqueue the React-based dashboard bundle when on our page.
	 *
	 * Source lives in `src/generic/`; wp-scripts builds it to
	 * `inc/generic/build/admin.{js,css,asset.php}`. The .asset.php file
	 * tells WP which scripts/styles the bundle depends on (wp-element,
	 * wp-i18n, etc.) so we don't have to hand-maintain the dep list.
	 *
	 * Falls back to a graceful "build missing" notice when the build
	 * output isn't on disk yet (developer hasn't run `pnpm build`).
	 */
	public function enqueue_assets( $hook ): void {
		$own_page  = false !== strpos( (string) $hook, self::PAGE_SLUG );
		$adapter   = apply_filters( 'ft_demo_importer_active_adapter', null );
		$host_hook = ( $adapter && method_exists( $adapter, 'embed_host_hook' ) )
			? $adapter->embed_host_hook()
			: null;
		$is_host   = $host_hook && (string) $hook === (string) $host_hook;

		if ( ! $own_page && ! $is_host ) {
			return;
		}

		$build_dir = DEMO_CONTENT_PATH . 'inc/generic/build/';
		$build_url = DEMO_CONTENT_URL . 'inc/generic/build/';

		$asset_file = $build_dir . 'admin.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			// Build artifact missing — set a flag the dashboard render
			// method checks so the page can show a "run pnpm build" notice
			// instead of a silent empty mount point.
			$this->build_missing = true;
			return;
		}
		$asset = include $asset_file;

		// Media library — used by future logo picker, harmless to load eagerly.
		wp_enqueue_media();

		wp_enqueue_script(
			'ft-demo-importer-admin',
			$build_url . 'admin.js',
			$asset['dependencies'] ?? [ 'wp-element', 'wp-i18n', 'wp-components', 'wp-api-fetch', 'wp-dom-ready' ],
			$asset['version'] ?? '1.2.0',
			true
		);
		// Shared base stylesheet at plugin root — same file the OnePress
		// dashboard enqueues. We mirror its `.demo-contents-themes-listing
		// > .theme > .theme-screenshot/.theme-name/.theme-actions` markup
		// inside the React tree so the grid inherits OnePress visuals
		// without a parallel stylesheet.
		wp_enqueue_style(
			'famethemes-demo-importer',
			DEMO_CONTENT_URL . 'style.css',
			[],
			'1.2.0'
		);
		wp_enqueue_style(
			'ft-demo-importer-admin',
			$build_url . 'admin.css',
			[ 'wp-components', 'famethemes-demo-importer' ],
			$asset['version'] ?? '1.2.0'
		);

		$base = [
			'restRoot'          => esc_url_raw( rest_url( 'ft-demo-importer/v1' ) ),
			'restNonce'         => wp_create_nonce( 'wp_rest' ),
			'pollIntervalMs'    => 2000,
			'currentTheme'      => get_option( 'template' ),
			'currentStylesheet' => get_option( 'stylesheet' ),
			'adapterLabel'      => $adapter ? $adapter->admin_label() : __( 'Starter Templates', 'famethemes-demo-importer' ),
			'studioConfigured'  => $this->options->has_credentials(),
			'home'              => home_url( '/' ),
		];

		// Embedded mode: host page (theme dashboard) hosts the React tree.
		// Flag tells admin.js to skip auto-mount — host calls
		// `window.ftDemoImporter.mount(el)` on its own lifecycle.
		if ( $is_host && ! $own_page ) {
			$base['embedded'] = true;
		}

		// Adapter-supplied payload comes first so the base keys win on
		// any accidental name collision — adapters publish their own
		// surface (palettes, font pairs, etc.) and must never overwrite
		// REST/auth wiring.
		$payload  = ( $adapter && method_exists( $adapter, 'boot_payload' ) )
			? $adapter->boot_payload()
			: [];
		$localize = array_merge( is_array( $payload ) ? $payload : [], $base );

		wp_localize_script( 'ft-demo-importer-admin', 'ftDemoImporter', $localize );
	}

	/** Set by enqueue_assets() when the React build output is missing. */
	private bool $build_missing = false;

	public function dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'famethemes-demo-importer' ) );
		}

		?>
		<div class="wrap demo-contents">
			<?php if ( $this->build_missing ) : ?>
				<div class="notice notice-error">
					<p>
						<?php
						printf(
							/* translators: 1: code path */
							esc_html__( 'React build output missing — run %s in the plugin directory.', 'famethemes-demo-importer' ),
							'<code>pnpm install &amp;&amp; pnpm build</code>'
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<?php // React app mounts here. Renders the grid using OnePress
				    // markup classes (`.demo-contents-themes-listing > .themes
				    // > .theme > .theme-screenshot/.theme-name/.theme-actions`)
				    // so the plugin's `style.css` styles it without a parallel
				    // stylesheet. ?>
				<div id="ft-demo-importer-app"></div>
			<?php endif; ?>
		</div><!-- /.wrap -->
		<?php
	}

	// Legacy render helpers (render_needs_setup / render_template_list)
	// and the Underscore preview_template were removed when the
	// dashboard moved to React. The empty/connection-prompt state +
	// the import wizard now live in the React tree under
	// `src/generic/components/` — server-side PHP only renders the
	// page <h1>, the tab strip, and the `<div id="ft-demo-importer-app">`
	// mount point.
}
