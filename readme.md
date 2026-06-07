# Starter Templates

> **From blank install to designer-built WordPress site in one click.** Browse starter templates, preview them live, and import the one you love — pages, plugins, palette and typography all in place. Stop fighting setup; start editing copy. Works with any Blocksify-compatible design library. Backward-compatible with the legacy FameThemes demo importer for OnePress family themes.

[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2+-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

---

## What it does

This is a **starter-template** plugin — every template is a fresh, opinionated starting point for a new WordPress site, not a backup or restore tool. Click a card → live preview the demo → pick a palette + font → click Import → ship.

Under the hood, a 5-phase background job sets up your site:

1. **Fetch** — download `content.xml` + `options.json` + `uploads.zip` from the configured design library.
2. **Plugins** — install + activate the required plugins from wordpress.org.
3. **Media** — place starter images into `wp-content/uploads/`.
4. **Content** — set up posts / pages / menus / terms, rewriting attachment refs + `{{SITE_URL}}` + multisite prefixes so nothing breaks on your domain.
5. **Look** — apply theme mods, customizer, widgets, plugin options, fonts (WP 6.5+ Font Library).

All running under WP-Cron — the React UI polls `/jobs/{id}` for progress and the browser tab can be closed mid-import.

## Two tracks, one plugin

| Track | When it runs | Where the code lives |
|---|---|---|
| **Generic** | Default — any theme not on the legacy list | `inc/generic/` (active development) |
| **Legacy** | OnePress, Screenr, Accelerate, Bizland, Oblique, Shapely, Crispmag, Sparkling, Cleanblock (plus `-pro` / child variants — filterable via `demo_contents_onepress_themes`) | `inc/legacy/` (frozen — no new features) |

Track selection happens at `plugins_loaded` based on the active theme stylesheet. Existing OnePress / Screenr installs get the exact same UX they had before — the rebrand only affects the Generic track.

## Features

- **Single-fetch catalog** — fetches every template for the active theme in one request, runs search + category filtering client-side. No per-keystroke network calls.
- **Snapshot-aware reads** — the studio ships an aggregated snapshot per template (`requirements`, `preview_url`, `theme_options` …) right in the list response, so the modal opens instantly with no detail / options round-trip.
- **Local proxy cache** — 10-minute transient cache on `/studio/templates` with `?no_cache=1` bypass. See [`docs/SPEC-proxy-cache.md`](docs/SPEC-proxy-cache.md).
- **Per-layer toggles** — independent "Widgets" and "Customizer settings" checkboxes instead of an all-or-nothing master switch.
- **Theme adapters** — `Theme_Adapter` contract lets a theme (e.g. [Customify](https://github.com/PressMaximum/customify)) declare required plugins, customize the Style step, and embed the dashboard under its own admin menu.
- **Font Library install** (WP 6.5+) — creates `wp_font_family` + `wp_font_face` CPTs and activates them in `wp_global_styles` so the editor's font picker surfaces them immediately.
- **Plugin descriptions** — surfaced in the PluginsStep cards with `title=` tooltip when truncated.

## Quick start

```bash
# Install via Plugins → Add New, or unzip into wp-content/plugins/
# Activate, then open Tools → Starter Templates (or the host theme's embedded entry).
```

Configuration:

```php
// wp-config.php — point at a custom Blocksify Design Studio
define( 'FT_DEMO_IMPORTER_STUDIO_URL', 'https://your-studio.example.com/' );
```

## Documentation

Detailed specs in [`docs/`](docs/):

| Doc | What's in it |
|---|---|
| [SPEC-pipeline.md](docs/SPEC-pipeline.md) | 5-phase background-job pipeline |
| [SPEC-adapter.md](docs/SPEC-adapter.md) | `Theme_Adapter` abstract contract |
| [SPEC-style-step.md](docs/SPEC-style-step.md) | Palette + typography step, Font Library install |
| [SPEC-embed-contract.md](docs/SPEC-embed-contract.md) | Embedding the React UI in a host theme's dashboard |
| [SPEC-proxy-cache.md](docs/SPEC-proxy-cache.md) | Local transient cache layer + `?no_cache=1` bypass |
| [api-reference.md](docs/api-reference.md) | Public filters, actions, REST routes |
| [README.md](docs/README.md) | Doc index + repo layout |

Rules for AI agents / contributors live in [`AGENTS.md`](AGENTS.md).

## Renaming note

The plugin's user-facing name is **Starter Templates**. Folder name, text domain (`famethemes-demo-importer`), PHP namespaces (`FT_Demo_Importer\…`), constants (`DEMO_CONTENT_*`, `FT_DEMO_IMPORTER_STUDIO_URL`), REST namespace (`ft-demo-importer/v1`), and option keys are kept verbatim for backward compatibility — every existing install upgrades cleanly without touching the database or losing translations.

## Build

```bash
pnpm install
pnpm build              # production build → inc/generic/build/
pnpm start              # watch mode
pnpm format             # wp-scripts formatter
pnpm lint:js            # ESLint via wp-scripts
```

Build artifacts (`inc/generic/build/`) are committed for deploy convenience but regenerate from `src/generic/` sources.

## License

GPL v2 or later. See [LICENSE](LICENSE).

## Contributing

Bug reports + PRs welcome on [GitHub](https://github.com/FameThemes/famethemes-demo-importer/). For new theme support, ship a `Theme_Adapter` rather than extending the core — see [SPEC-adapter.md](docs/SPEC-adapter.md).
