# SPEC: Import Pipeline

Canonical reference for the 5-phase background job that pulls a template from the Studio and applies it to the live site.

Owner: [`inc/generic/Jobs/class-importer-runner.php`](../inc/generic/Jobs/class-importer-runner.php)

---

## State machine

```
queued ──► fetching ──► installing_plugins ──► extracting ──► importing_content ──► applying_options ──► completed
                                │                                                       │
                                ▼ (required plugin missing/failed)                      ▼ (any layer aborts)
                              failed                                                  failed
```

Status constants live in [`Job_Store`](../inc/generic/Jobs/class-job-store.php):

| Constant | Wire value |
|---|---|
| `STATUS_QUEUED` | `'queued'` |
| `STATUS_FETCHING` | `'fetching'` |
| `STATUS_INSTALLING_PLUGINS` | `'installing_plugins'` |
| `STATUS_EXTRACTING` | `'extracting'` |
| `STATUS_IMPORTING_CONTENT` | `'importing_content'` |
| `STATUS_APPLYING_OPTIONS` | `'applying_options'` |
| `STATUS_COMPLETED` | `'completed'` |
| `STATUS_FAILED` | `'failed'` |
| `STATUS_CANCELLED` | `'cancelled'` |

---

## Phase 1 — fetching

Class: [`Asset_Fetcher`](../inc/generic/Steps/class-asset-fetcher.php)

Downloads template manifest + content + uploads from the Studio server. Three asset URLs come from `GET /studio/templates/{id}`:

| File | Required? |
|---|---|
| `content.json` | YES |
| `options.json` | YES |
| `uploads.zip` | YES |

Network failure → abort (`failed`).

---

## Phase 2 — installing_plugins

Class: [`Plugin_Installer`](../inc/generic/Steps/class-plugin-installer.php)

Walks `manifest.requirements.plugins` + adapter's `required_plugins()` merged. For each:

| Source | Install mechanism |
|---|---|
| `'wordpress.org'` | `plugins_api()` + `Plugin_Upgrader::install()` |
| `'bundled'` | Skipped (theme/host provides) |
| `'third-party'` | Logged as recommended, never auto-installed |

Activation runs after install. **Required plugin failure → abort.** Recommended plugin failure → continue.

Filter `ft_demo_importer_blocking_plugin_slugs` lets the adapter mark a plugin as "abort if this fails to install":

```php
add_filter( 'ft_demo_importer_blocking_plugin_slugs', function ( $slugs, $job, $manifest ) {
    $slugs[] = 'my-required-plugin';
    return $slugs;
}, 10, 3 );
```

---

## Phase 3 — extracting

Class: [`Uploads_Extractor`](../inc/generic/Steps/class-uploads-extractor.php)

Unzips `uploads.zip` into `wp-content/uploads/`. Pre-existing files at the same path are overwritten when `config.overwrite_existing === true` (default).

Disk-full / permission failure → abort.

---

## Phase 4 — importing_content

Class: [`Content_Importer`](../inc/generic/Steps/class-content-importer.php)

Walks `content.json` in this order:

1. **Terms** — taxonomies first so post→term references can resolve.
2. **Posts** — including any CPT declared in the manifest.
3. **Attachments** — ID remapping so post_content references stay valid.
4. **Menus** — `nav_menu` term + `nav_menu_item` posts with ref resolution.

Builds `ref_map` (source ID → local ID) shared with the next phase via `$job['state']['ref_map']`.

---

## Phase 5 — applying_options

Class: [`Options_Importer`](../inc/generic/Steps/class-options-importer.php)

Layered, each `try/catch` — one layer failing doesn't kill the rest:

1. Checksums (always) — verify `content.json` + `uploads.zip` sha256.
2. Theme requirement (always) — log mismatch, never auto-switch.
3. Core options (gated) — `show_on_front` / `page_on_front` / `page_for_posts`.
4. Theme mods (gated) — `set_theme_mod` per key, `_ref` resolved via `ref_map`.
5. Customizer (gated) — same shape as theme mods.
6. Widgets (gated) — `sidebars_widgets` + per-widget option keys.
7. Plugin options (gated) — whitelisted `wp_options` rows.
8. Fonts (gated) — `theme.fonts[]` → WP Font Library CPTs (`wp_font_family` + `wp_font_face`).

"Gated" layers run only when `opts.replace_settings === true` (default).

### Denylist

Some keys are flat-out refused even if the manifest carries them — they don't round-trip cleanly:

| Key | Why |
|---|---|
| `site_icon` | Studio's attachment ref doesn't reconstruct the favicon upload; writing a stale ID paints a broken `<link rel="icon">`. |

Extend via the `ft_demo_importer_denied_option_keys` filter (see [api-reference.md](api-reference.md)).

### Adapter hook

After phase 5 (and every other phase) the runner fires:

```php
$adapter->after_phase( $phase, $job, $runner );
```

Customify_Adapter consumes `after_phase('applying_options')` to:

- Apply the user's chosen color palette (6 theme_mod color slots).
- Install the user's chosen font pair into the WP Font Library + write 11 typography theme_mods.

See [SPEC-style-step.md](SPEC-style-step.md) for the full mapping.

---

## Job state

Persisted by [`Job_Store`](../inc/generic/Jobs/class-job-store.php) as a single transient per job_id:

```
$job = [
    'id'        => 'j_abc123',
    'status'    => 'applying_options',
    'config'    => [
        'template_id'        => 107,
        'import_content'     => true,
        'import_uploads'     => true,
        'overwrite_existing' => true,
        'replace_settings'   => true,
        'plugins_skip'       => [ 'optional-plugin-slug' ],
        'custom_logo_id'     => 0,
        'custom_logo_url'    => '',
        'style'              => [ 'palette' => 'sunrise', 'font' => 'playfair-lora' ],
    ],
    'progress'  => [ 'phase' => 'applying_options', 'percent' => 90, 'message' => 'Applying settings…' ],
    'log'       => [ '2026-06-06 12:34:56  Downloading template assets…', … ],
    'created'   => 1717684000,
    'updated'   => 1717684120,
];
```

`config.style` is opaque to the generic layer — adapters consume it.

---

## Failure semantics summary

| Failure | Action |
|---|---|
| Network down for asset fetch | Abort (`failed`). |
| Required plugin fails install / activate | Abort. |
| Recommended plugin fails | Log, continue. |
| Content import throws | Abort. |
| Options import layer throws | Logged, layer skipped, **other layers continue**. |
| Font Library install fails (WP < 6.5, network) | Logged, theme_mods still written (font resolver falls back to CDN). |
| User cancels mid-run | Next safe boundary writes `cancelled`. |

---

## Files to read first

| File | Why |
|---|---|
| [`inc/generic/Jobs/class-importer-runner.php`](../inc/generic/Jobs/class-importer-runner.php) | Orchestrator with all 5 phases inline |
| [`inc/generic/Jobs/class-job-store.php`](../inc/generic/Jobs/class-job-store.php) | Transient persistence + status constants |
| [`inc/generic/Steps/class-options-importer.php`](../inc/generic/Steps/class-options-importer.php) | Most-touched phase — eight sub-layers |
| [`inc/generic/Adapters/class-customify-adapter.php`](../inc/generic/Adapters/class-customify-adapter.php) | Reference `after_phase()` implementation |
