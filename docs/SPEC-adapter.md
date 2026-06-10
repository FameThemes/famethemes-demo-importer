# SPEC: Theme_Adapter contract

Per-theme adapters are how the importer learns theme-specific behaviour without forking the pipeline. The Generic track delegates every theme-specific decision to the active adapter; the runner / steps / REST controllers know nothing about Customify, Astra, or any other theme.

---

## Selection

`Theme_Detector::active_adapter()` runs once per request and caches:

```
Adapter_Registry::resolve( template, stylesheet )
    ├── apply_filters( 'ft_demo_importer_resolve_adapter', null, template, stylesheet )
    │       └── early-out override
    ├── exact-match( stylesheet ) → registered adapter
    ├── exact-match( template )   → registered adapter
    └── fall back                 → Default_Adapter (no-op)
```

Adapters self-register in [`inc/generic/bootstrap.php`](../inc/generic/bootstrap.php):

```php
require_once __DIR__ . '/Adapters/class-customify-adapter.php';
$customify_adapter = new \FT_Demo_Importer\Adapters\Customify_Adapter();
\FT_Demo_Importer\Adapter_Registry::register( $customify_adapter );
$customify_adapter->register_integration();  // optional — wire Customify-specific filters
```

---

## Contract — [`Theme_Adapter`](../inc/generic/Adapters/class-theme-adapter.php)

All methods are virtual; the default implementation is a no-op. Override what your theme needs.

### Identity

```php
abstract public function supported_slugs(): array;
```

Return an array of stylesheet / template slugs this adapter claims (e.g. `['customify', 'customify-child']`).

### Required plugins

```php
public function required_plugins(): array;
```

TGM-style:

```php
[
    [ 'slug' => 'blocksify', 'name' => 'Blocksify', 'required' => true ],
    [ 'slug' => 'some-recommended', 'name' => '…', 'required' => false ],
]
```

Required plugins that fail to install / activate abort the job.

### Customizer key map

```php
public function customizer_keys(): array;
```

Overlay on top of the manifest's own `customizer_keys` block — used by `Options_Importer` to know which theme_mods carry post / term / media IDs that need ref-resolving:

```php
[
    'my_logo_id'   => 'media',
    'my_page_ref'  => [ [ 'type' => 'post', 'key' => 'page_id' ] ],
]
```

### Lifecycle hook

```php
public function after_phase( string $phase, array $job, \FT_Demo_Importer\Jobs\Importer_Runner $runner ): void;
```

Called by the runner at the END of each phase. `$phase` is one of the `Job_Store::STATUS_*` strings. The Customify_Adapter uses `after_phase('applying_options')` to apply the user's Style step picks.

The default is a no-op. Use the runner reference if you need to `log()` something:

```php
public function after_phase( string $phase, array $job, $runner ): void {
    if ( 'applying_options' === $phase ) {
        if ( method_exists( $runner, 'job_store' ) ) {
            $runner->job_store()->log( (string) $job['id'], 'My adapter ran.' );
        }
    }
}
```

### Studio credentials

```php
public function studio_server_url(): ?string;
public function studio_api_key(): ?string;
```

Return non-empty to ship default credentials for the theme (e.g. PressMaximum Design Library for Customify). The wp-config constant + saved option still take precedence — adapters are defaults, not authorities. The bridge in `bootstrap.php` enforces the order.

### Admin surface

```php
public function admin_label(): string;             // 'Starter Templates'
public function dashboard_parent_slug(): ?string;  // 'customify' to nest under a theme top-level menu
public function plugin_row_import_url(): ?string;  // Plugins.php → row action link
public function plugin_row_import_label(): string;
```

### Embed contract

```php
public function embed_host_hook(): ?string;     // 'toplevel_page_customify'
public function embed_boot_filter(): ?string;   // 'customify_dashboard_localize'
public function embed_boot_key(): string;       // 'importer' — default
public function boot_payload(): array;          // [ 'palettes' => [...], 'fonts' => [...] ]
```

See [SPEC-embed-contract.md](SPEC-embed-contract.md) for end-to-end mechanics.

---

## Customify_Adapter — reference implementation

File: [`inc/generic/Adapters/class-customify-adapter.php`](../inc/generic/Adapters/class-customify-adapter.php)

What it does:

1. **Claims slugs** `[ 'customify' ]`.
2. **Studio credentials** — defaults the URL to the PressMaximum Design Library. Constant still wins.
3. **Dashboard nesting** — `dashboard_parent_slug()` returns `'customify'` so the importer surface nests under the theme's top-level menu.
4. **Embed contract** — declares the Customify dashboard hook + boot filter. Sets `boot_payload()` to publish:
   - `palettes` — 2 presets from `customify_color_preset_palettes()` + every user palette saved in the `customify_color_palettes` theme_mod.
   - `fonts` — 6 curated Google Fonts pairs (`curated_font_pairs()`). **Fallback only**: the wizard prefers the template's per-item `theme_options.typography` list shipped by the studio and falls back to this set when a template predates that field.
5. **Style step apply** — `after_phase('applying_options')` reads `$job['config']['style']` and:
   - `apply_palette()` — writes the 6 color slots via `customify_color_palette_slot_map()` + tags `customify_active_palette`.
   - `apply_typography()` — uses [`Font_Installer`](../inc/generic/Adapters/customify/class-font-installer.php) to install the pair into WP Font Library, then writes 11 typography theme_mods (9 title slots + 2 body slots).
6. **register_integration()** — called once at bootstrap. Hooks:
   - `customify_dashboard_localize` → injects `boot.importer` metadata
   - `ft_demo_importer_show_generic_menu` → returns false so the standalone submenu hides
   - `admin_init` → redirect direct `?page=famethemes-demo-importer` hits to the embedded tab

See [SPEC-style-step.md](SPEC-style-step.md) for the full palette + typography mapping.

---

## Adding a new theme adapter — checklist

1. Create `inc/generic/Adapters/class-<theme>-adapter.php`.
2. Extend `Theme_Adapter`, override `supported_slugs()`.
3. Override the methods you need.
4. Self-register in `bootstrap.php`:

   ```php
   require_once __DIR__ . '/Adapters/class-mytheme-adapter.php';
   \FT_Demo_Importer\Adapter_Registry::register( new \FT_Demo_Importer\Adapters\MyTheme_Adapter() );
   ```

5. Confirm `Theme_Detector::active_adapter()` picks it up on the test install.
6. If your adapter writes theme_mods that need fresh data each install (e.g. a per-import options sync), gate the writes on `$job['config']['replace_settings'] === true` — the wizard's "Settings already applied" confirm step toggles this.

Never put theme-specific code outside `Adapters/` (and the optional `Adapters/<theme>/` helper directory).
