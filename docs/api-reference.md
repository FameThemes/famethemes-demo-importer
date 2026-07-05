# API Reference

Public filters, actions, and REST routes the plugin exposes. Treat every entry below as part of the public surface — semver discipline applies.

---

## Filters

### `ft_demo_importer_active_adapter`

Returns the active [`Theme_Adapter`](../inc/generic/Adapters/class-theme-adapter.php) instance for the current request. Cached after the first call.

```php
$adapter = apply_filters( 'ft_demo_importer_active_adapter', null );
```

Source: [`inc/generic/bootstrap.php`](../inc/generic/bootstrap.php).

### `ft_demo_importer_resolve_adapter`

Early-out override for adapter resolution. Return any `Theme_Adapter` to skip the registry walk.

```php
add_filter( 'ft_demo_importer_resolve_adapter', function ( $existing, $template, $stylesheet ) {
    if ( 'my-staging-theme' === $template ) {
        return new MyTheme_Adapter();
    }
    return $existing;
}, 10, 3 );
```

Source: [`inc/generic/class-adapter-registry.php`](../inc/generic/class-adapter-registry.php).

### `ft_demo_importer_default_studio_url`

Last-pass override of the resolved Studio base URL. Bootstrap registers a callback that consults the adapter's `studio_server_url()`. The wp-config constant + saved option still take precedence — the filter only runs when those are unset.

```php
add_filter( 'ft_demo_importer_default_studio_url', function ( $url ) {
    return $url ?: 'https://my-studio.example.com/';
} );
```

Source: [`inc/generic/Settings/class-options-store.php`](../inc/generic/Settings/class-options-store.php) + bridge in [`bootstrap.php`](../inc/generic/bootstrap.php).

### `ft_demo_importer_studio_key`

Same shape as the URL filter. Second arg is the source label (`'constant'` / `'saved'` / `'empty'`) so callbacks can decide whether to override.

```php
add_filter( 'ft_demo_importer_studio_key', function ( $key, $source ) {
    return ( 'empty' === $source ) ? 'pmbd_live_xxx' : $key;
}, 10, 2 );
```

### `ft_demo_importer_show_generic_menu`

Boolean — controls whether the standalone admin menu entry renders.

```php
add_filter( 'ft_demo_importer_show_generic_menu', '__return_false' );
```

Customify's adapter uses this to hide the standalone entry while embedded mode is active.

Source: [`inc/generic/class-generic-dashboard.php`](../inc/generic/class-generic-dashboard.php).

### `ft_demo_importer_dashboard_parent_slug`

Adapter-resolved parent slug for the standalone submenu (`themes.php` by default, `customify` for Customify). Last-pass override.

```php
add_filter( 'ft_demo_importer_dashboard_parent_slug', function ( $slug, $adapter ) {
    return $slug;
}, 10, 2 );
```

### `ft_demo_importer_job_config`

Sanitized job config before it's persisted + enqueued. Use it to inject default flags or strip fields per-environment.

```php
add_filter( 'ft_demo_importer_job_config', function ( $config, $body ) {
    $config['overwrite_existing'] = false;
    return $config;
}, 10, 2 );
```

Source: [`inc/generic/REST/class-job-controller.php`](../inc/generic/REST/class-job-controller.php).

### `ft_demo_importer_blocking_plugin_slugs`

Plugin slugs whose failed install / activate aborts the job (instead of "log and continue"). The default list comes from `manifest.requirements.plugins` entries marked `required:true` + the adapter's `required_plugins()` rows.

```php
add_filter( 'ft_demo_importer_blocking_plugin_slugs', function ( $slugs, $job, $manifest ) {
    $slugs[] = 'blocksify';
    return $slugs;
}, 10, 3 );
```

Source: [`inc/generic/Jobs/class-importer-runner.php`](../inc/generic/Jobs/class-importer-runner.php).

### `ft_demo_importer_denied_option_keys`

Option / theme_mod keys the `Options_Importer` refuses to write — anywhere they appear in the manifest (theme_mods, customizer, plugin_options).

Default: `['site_icon']`.

```php
add_filter( 'ft_demo_importer_denied_option_keys', function ( $keys ) {
    $keys[] = 'admin_email';
    $keys[] = 'blogname';
    return $keys;
} );
```

Source: [`inc/generic/Steps/class-options-importer.php`](../inc/generic/Steps/class-options-importer.php).

### `ft_demo_importer_curated_font_pairs`

Fallback typography pair list for the wizard's Style step. The wizard prefers the template's own `theme_options.typography`; this set surfaces only when a template predates that field, and also backs `find_font_pair()` for legacy string-id job payloads. **Empty by default.**

Both `heading` and `body` must match a family name in `themes/customify/build/fonts/google-fonts.json` exactly.

```php
add_filter( 'ft_demo_importer_curated_font_pairs', function ( $pairs ) {
    $pairs[] = [ 'id' => 'playfair-lora', 'heading' => 'Playfair Display', 'body' => 'Lora', 'weight' => 700 ];
    return $pairs;
} );
```

Source: [`inc/generic/Adapters/class-customify-adapter.php`](../inc/generic/Adapters/class-customify-adapter.php).

### `ft_demo_importer_font_subsets`

Unicode subsets downloaded per font variant during Font Library install — **css2 fallback path only**. The primary source is core's "google-fonts" collection (full-coverage files, no subsets); this filter matters only when that collection can't load and the installer falls back to slicing Google's css2 response, where each subset of each variant is a separate `.woff2` carrying `unicode-range`.

Default: `['latin', 'latin-ext', 'vietnamese']`.

```php
add_filter( 'ft_demo_importer_font_subsets', function ( $subsets ) {
    $subsets[] = 'cyrillic';
    return $subsets;
} );
```

Source: [`inc/generic/Adapters/customify/class-font-installer.php`](../inc/generic/Adapters/customify/class-font-installer.php).

---

## REST routes

Namespace: `ft-demo-importer/v1`. All routes gate on `current_user_can('manage_options')` + `X-WP-Nonce` (REST nonce).

### Studio proxy

| Method | Path | Handler | Purpose |
|---|---|---|---|
| `GET` | `/studio/me` | `Studio_Proxy_Controller::get_me` | Test Studio connectivity |
| `GET` | `/studio/templates` | `Studio_Proxy_Controller::list_templates` | Template grid feed |
| `GET` | `/studio/templates/{id}` | `Studio_Proxy_Controller::get_template` | Template detail |
| `GET` | `/studio/categories` | `Studio_Proxy_Controller::list_categories` | Category pills |

Upstream is the **PM Templates public catalog** (`pm-templates/v1/public`, no auth). [`Remote_Client`](../inc/generic/Studio/class-remote-client.php) normalizes catalog responses back into the legacy Studio shape these proxy routes expose, so the React UI and import Steps are unchanged. Network failures surface as `502` to the JS so the UI can show an "unreachable" message instead of crashing on a malformed body.

Source: [`inc/generic/REST/class-studio-proxy-controller.php`](../inc/generic/REST/class-studio-proxy-controller.php).

### Job lifecycle

| Method | Path | Handler | Purpose |
|---|---|---|---|
| `POST` | `/theme/jobs` | `Job_Controller::create_job` | Create + enqueue |
| `GET` | `/theme/jobs/latest` | `Job_Controller::get_latest` | Latest job for the current user |
| `GET` | `/theme/jobs/{id}` | `Job_Controller::get_job` | Poll progress |
| `POST` | `/theme/jobs/{id}/cancel` | `Job_Controller::cancel_job` | Flag for cancellation |

Job payload shape:

```json
{
    "template_id":        107,
    "import_content":     true,
    "import_uploads":     true,
    "overwrite_existing": true,
    "replace_settings":   true,
    "plugins_skip":       [ "optional-plugin" ],
    "custom_logo_id":     0,
    "custom_logo_url":    "",
    "style":              { "palette": "sunrise", "font": "playfair-lora" }
}
```

`style` is opaque to the generic layer — adapters consume it from `after_phase('applying_options')`.

Source: [`inc/generic/REST/class-job-controller.php`](../inc/generic/REST/class-job-controller.php).

---

## JavaScript API surface

Globals exposed on `window.ftDemoImporter`:

| Key | Type | Set by |
|---|---|---|
| `restRoot` | string | PHP `wp_localize_script` |
| `restNonce` | string | PHP |
| `currentTheme` | string | PHP |
| `currentStylesheet` | string | PHP |
| `adapterLabel` | string | PHP (adapter's `admin_label()`) |
| `embedded` | bool | PHP (true only on host hook) |
| `palettes` | array | Adapter `boot_payload()` |
| `fonts` | array | Adapter `boot_payload()` |
| `mount(el)` | function | `admin.js` |
| `unmount(el)` | function | `admin.js` |

The host theme drives mount/unmount when `embedded: true`. See [SPEC-embed-contract.md](SPEC-embed-contract.md).

---

## Constants

| Constant | Source | Purpose |
|---|---|---|
| `FT_DEMO_IMPORTER_STUDIO_URL` | wp-config (user) | Lock Studio URL across environments |
| `FT_DEMO_IMPORTER_STUDIO_KEY` | wp-config (user) | Lock Studio API key |
| `DEMO_CONTENT_PATH` | `famethemes-demo-importer.php` | Plugin dir absolute path |
| `DEMO_CONTENT_URL` | `famethemes-demo-importer.php` | Plugin dir public URL |

Constants always win over saved options and adapter defaults — the order is enforced in [`Options_Store`](../inc/generic/Settings/class-options-store.php) + bridges in [`bootstrap.php`](../inc/generic/bootstrap.php).
