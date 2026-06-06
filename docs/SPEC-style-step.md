# SPEC: Style Step

How the wizard's "Choose a style" panel (palette + typography) becomes theme_mods + WP Font Library entries at the end of the import.

Owners:
- React: [`src/generic/components/PreviewPanel.jsx`](../src/generic/components/PreviewPanel.jsx) (StyleStep section)
- PHP wire: [`inc/generic/REST/class-job-controller.php`](../inc/generic/REST/class-job-controller.php) → [`inc/generic/Jobs/class-importer-runner.php`](../inc/generic/Jobs/class-importer-runner.php)
- Customify apply: [`inc/generic/Adapters/class-customify-adapter.php`](../inc/generic/Adapters/class-customify-adapter.php) `apply_palette()` + `apply_typography()`
- Font install: [`inc/generic/Adapters/customify/class-font-installer.php`](../inc/generic/Adapters/customify/class-font-installer.php)

---

## End-to-end flow

```
Wizard StyleStep (React)
    ├── palettes ← window.ftDemoImporter.palettes (adapter boot_payload)
    └── fonts    ← window.ftDemoImporter.fonts    (adapter boot_payload)
    │
    │ user picks
    ▼
POST /ft-demo-importer/v1/theme/jobs
    body.style = { palette: 'sunrise' | null, font: 'playfair-lora' | null }
    │
    ▼
Job_Controller::create_job
    sanitize_key( style.palette ) + sanitize_key( style.font )
    config.style stored on job record
    │
    ▼
Importer_Runner pipeline (5 phases) — see SPEC-pipeline.md
    │
    ▼ after_phase('applying_options')
Customify_Adapter::after_phase
    ├── apply_palette('sunrise')
    └── apply_typography('playfair-lora')
```

---

## Palette apply

[`Customify_Adapter::apply_palette()`](../inc/generic/Adapters/class-customify-adapter.php).

### Lookup

`find_palette($id)`:

1. Walk `customify_color_preset_palettes()` for matching id.
2. Walk JSON-decoded `theme_mod('customify_color_palettes', '[]')` for user palettes.
3. Return `null` if not found → log + skip.

### Write

Reuse the theme's slot map `customify_color_palette_slot_map()` — never invent a new mod key:

| Slot | theme_mod key |
|---|---|
| `primary` | `global_styling_color_primary` |
| `secondary` | `global_styling_color_secondary` |
| `accent` | `customify_palette_accent` |
| `text` | `customify_palette_text` |
| `surface` | `customify_palette_surface` |
| `base` | `customify_palette_base` |

Plus a marker: `set_theme_mod( 'customify_active_palette', $palette_id )` so the Customizer's palette switcher UI highlights the active row.

---

## Typography apply

[`Customify_Adapter::apply_typography()`](../inc/generic/Adapters/class-customify-adapter.php).

### Lookup

`find_font_pair($id)` walks `curated_font_pairs()`:

| id | Title font (heading) | Text font (body) | weight |
|---|---|---|---|
| `inter-inter` | Inter | Inter | 600 |
| `playfair-lora` | Playfair Display | Lora | 700 |
| `poppins-opensans` | Poppins | Open Sans | 700 |
| `raleway-nunito` | Raleway | Nunito | 600 |
| `montserrat-lato` | Montserrat | Lato | 600 |
| `dmserif-dmsans` | DM Serif Display | DM Sans | 400 |

### Install fonts

Each family → `Font_Installer::install($family)`:

1. Wipe any existing CPTs + global-styles entry for the slug (clean re-install).
2. `resolve_variants()` → variants from `themes/customify/build/fonts/google-fonts.json` catalogue → Google Fonts CSS API → deduped `(weight, style)` URL list (modern UA → woff2).
3. `download_font_to_temp()` per variant — `wp_safe_remote_get` stream, magic-byte validation, Content-Length check, canonical-extension rename.
4. `sideload_font_to_uploads()` — `wp_handle_sideload` with `upload_mimes` + `_wp_filter_font_directory` filters → file lands in `wp-content/fonts/` (or `wp-content/uploads/fonts/` on WP 6.5/6.6).
5. `rest_create_family()` + REST face create per variant via `rest_do_request` — keeps CPT shape identical to UI-driven installs (auto-seeds `_wp_font_face_file` meta).
6. `activate_in_global_styles()` — append to `wp_global_styles.settings.typography.fontFamilies.custom[]` and flush theme.json caches (`wp_clean_theme_json_cache` + `WP_Theme_JSON_Resolver::clean_cached_data`).

REST controllers gate on `edit_theme_options` + `unfiltered_upload`. The runner has no session, so install runs inside a `user_has_cap` filter that grants both caps for the duration of the install only.

### Write theme_mods — 11 / 11 mapping

Each call goes through `merge_typo_mod()` which patches only `font` + `font_weight` so the template's existing size / line_height / letter_spacing values survive.

**Title font (9 settings)** — `heading` family + pair weight:

| theme_mod key | Renders |
|---|---|
| `global_typography_base_heading` | H1–H6 generic (`h1, h2, ..., h6, .h1, ..., .h6`) |
| `global_typography_site_tt_title` | Site Title (header logo) |
| `global_typography_base_widget_title` | Widget titles (sidebar / footer) |
| `global_typography_heading_h1` | H1 in content (`.entry-content h1`) |
| `global_typography_heading_h2` | H2 in content |
| `global_typography_heading_h3` | H3 in content |
| `global_typography_heading_h4` | H4 in content |
| `global_typography_heading_h5` | H5 in content |
| `global_typography_heading_h6` | H6 in content |

**Text font (2 settings)** — `body` family + weight `400`:

| theme_mod key | Renders |
|---|---|
| `global_typography_base_p` | Body & paragraph |
| `global_typography_site_tt_desc` | Tagline (under Site Title) |

Source of truth for the setting list: [`themes/customify/inc/customizer/configs/typography.php`](../../../themes/customify/inc/customizer/configs/typography.php).

---

## "Keep current" semantics

When the user keeps the existing palette / typography on the wizard, the React tile holds the value at `null`:

```js
{ palette: null, font: 'playfair-lora' }   // typography only
{ palette: 'sunrise', font: null }         // palette only
{ palette: null, font: null }              // skip Style step entirely
```

`Customify_Adapter::after_phase()` early-outs each axis independently so the user can mix-and-match. Sanitization in `Job_Controller` normalizes empty strings to `null`.

---

## Adding a new pair

1. Edit `curated_font_pairs()` in [`class-customify-adapter.php`](../inc/generic/Adapters/class-customify-adapter.php).
2. Confirm both `heading` and `body` family names exist in `themes/customify/build/fonts/google-fonts.json` — `Font_Installer::catalogue_variants()` looks up by exact family name.
3. No JS rebuild needed — the wizard reads the list from `boot_payload()` (PHP).

---

## Adding a new palette preset

User palettes (`theme_mod('customify_color_palettes')`) flow through automatically. To ship a new built-in preset, hook the theme's `customify/color/preset_palettes` filter — adapter side reads whatever the theme publishes.
