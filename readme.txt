=== Starter Templates ===
Contributors: famethemes, shrimp2t
Donate link: https://www.famethemes.com/
Tags: starter templates, demo import, one-click import, customify, blocksify
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: trunk
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

From blank install to designer-built WordPress site in one click. Browse starter templates, preview them live, and import the one you love — pages, plugins, palette and typography all in place. Stop fighting setup; start editing copy.

== Description ==

Most WordPress projects spend the first week fighting the blank canvas. Installing plugins. Wrestling theme settings. Hunting for a color palette that doesn't clash with itself. Pasting filler text you'll only rewrite anyway. By the time the site looks like a site, the deadline is breathing down your neck.

**Starter Templates collapses that week into a single click.**

Open the wizard. Browse a gallery of finished site designs. Click the one that fits. A live preview opens — actual interactive pages, not screenshots. Hit Import. A minute later your site looks exactly like the demo, top to bottom: homepage, about, services, contact, blog, the navigation that ties them together, the typography that pulls them into a brand, the plugins each page quietly needs running in the background.

**You ship today, not next Friday.**

This isn't a content-restore tool. There's nothing to migrate, nothing to recover. Every template is a fresh, opinionated **starting point** — pages, menus, widgets and theme settings designed together so the whole site feels coordinated from minute one. The template is scaffolding, not destiny: throw out what you don't need, rewrite every word, swap the palette mid-build. It's your site the moment the import finishes.

= Built for builders, friendly to first-timers =

Whether you're an agency turning out the third site this month or someone launching their first WordPress project ever, the experience is identical: **pick → preview → import → edit → done**. No prerequisites. No setup-before-the-setup. The wizard handles the boring parts so you spend your hours on the parts a client actually pays for — copy, photos, the story you're trying to tell.

= What's in a starter template =

A starter template isn't wallpaper for an empty site — it's a complete, opinionated design you adopt and make your own:

* **Every page** the design needs — homepage, about, services, contact, blog — and the menus connecting them.
* **A look that holds together** — color palette and Google Font pair chosen by the designer so the whole site feels coordinated. Swap either in the wizard if you'd rather start with your own.
* **The plugins it depends on** — installed from wordpress.org automatically. WooCommerce for a shop, a forms plugin for the contact page, a block library for the homepage hero. You see what's coming before it lands; you can opt out of anything optional.
* **Sample media** sized and placed for each block so the layout reads like a real layout, not skeleton boxes. Replace at your leisure.
* **Theme settings** — colors, fonts, customizer options, widget areas, layout choices — applied to your active theme so the result feels like a finished design from the inside out, not skin glued on top.

= Why builders pick it =

* **Live preview, not a screenshot.** An iframe of the actual demo site. Scroll, click, resize. What you see is what you import.
* **Style step in the wizard.** Decide color palette and typography before import. Bring your own brand color, choose a different Google Font, mix template defaults with theme defaults. No re-importing.
* **Smart plugin handling.** The template declares its dependencies up front. The wizard installs required plugins silently and lets you tick the recommended-but-optional ones. No surprise downloads, no half-broken pages.
* **Per-layer control.** Want the new palette but keep your existing widgets? Toggle widgets off. Want fresh widgets but keep the customizer the way you set it up? Toggle the other off. Refresh exactly the layer you intended.
* **Backward-compatible.** Sites running classic FameThemes themes (OnePress, Screenr, Accelerate, Bizland, Oblique, Shapely, Crispmag, Sparkling, Cleanblock, plus `-pro` and child variants) get the original one-click demo importer they've always had. Same UX, zero regression after upgrade.
* **Bring your own library.** Starter Templates speaks the [Blocksify Design Studio](https://github.com/PressMaximum/blocksify-design-studio) REST contract. Point it at any compatible source — your agency's private catalog, a self-hosted instance, a community fork — by setting one constant in `wp-config.php`. Build once, ship everywhere.

= How it works under the hood =

Every import is a five-step background job — no babysitting required:

1. **Fetch** the template bundle from your configured design library.
2. **Install + activate** the plugins the template needs (from wordpress.org).
3. **Place** the starter media into `wp-content/uploads/`.
4. **Set up** posts, pages, terms, menus — with attachment references and site-URL placeholders rewritten so nothing breaks on your domain.
5. **Apply** theme mods, customizer settings, widgets, plugin options, and the Font Library entries the template ships with.

The whole thing runs under WP-Cron. Close the tab if you want — it keeps going. Progress streams back to the dashboard if you stick around to watch.

= Bring your own catalog =

Starter Templates speaks the [Blocksify Design Studio](https://github.com/PressMaximum/blocksify-design-studio) REST contract. Out of the box it points at the default community library, but the source URL is configurable via the `FT_DEMO_IMPORTER_STUDIO_URL` constant in `wp-config.php` — point it at any compatible studio (your agency's private catalog, a self-hosted instance, a fork) and the plugin behaves identically.

= Theme adapters =

Themes can ship a `Theme_Adapter` to declare their own required plugins, customize the wizard's Style step, or embed the dashboard directly under their own admin menu. Customify ships an adapter out of the box; everything else uses a sensible default with no per-theme code required.

= Configuration =

* **Source library URL** — configurable via `FT_DEMO_IMPORTER_STUDIO_URL` in `wp-config.php`, or the Settings page.
* **Activation redirect** — sends you straight to the importer for the active theme on first activation.
* **Cache refresh** — append `?no_cache=1` to the templates REST URL to skip the local 10-minute cache after a library update.

= Privacy =

The plugin only contacts the design library you configure, and only sends the data needed to render the catalog: the active theme stylesheet (so the list can be filtered to compatible templates) and standard HTTP request headers. No analytics, no tracking, no telemetry.

== Installation ==

1. Upload the `famethemes-demo-importer` folder to the `/wp-content/plugins/` directory, or install via Plugins → Add New.
2. Activate the plugin through the Plugins menu in WordPress.
3. Open the Starter Templates dashboard:
   * **Generic theme**: Tools → Starter Templates, or the entry your theme exposes.
   * **Customify**: Customify → Starter Templates (embedded in the host theme's dashboard).
   * **OnePress family**: the original Theme Options → One Click Demo Import tab.

== Frequently Asked Questions ==

= What is the plugin license? =

This plugin is released under a GPL v2 (or later) license.

= Does it work with any theme? =

Yes — anything not on the FameThemes legacy list routes to the Generic track, which is fully theme-agnostic. A theme can ship a `Theme_Adapter` to customize the wizard, but it isn't required.

= Where do the templates come from? =

By default, from a community design library curated by the plugin authors. You can point the plugin at any compatible [Blocksify Design Studio](https://github.com/PressMaximum/blocksify-design-studio) instance — a self-hosted server, an agency's private catalog, or a fork — by setting the `FT_DEMO_IMPORTER_STUDIO_URL` constant in `wp-config.php`.

= My import looks slow on the first page load. =

The first request fetches the full catalog from the remote Studio (1-3 s depending on your connection). After that, the catalog is cached locally for 10 minutes — repeat loads are near-instant. Pass `?no_cache=1` on the REST URL or call `studio wp transient delete --all` to force-refresh.

= Can I contribute? =

Yes! Issues + pull requests welcome on the [GitHub repository](https://github.com/FameThemes/famethemes-demo-importer/).

== Changelog ==

= 1.3.0 =
* **Rebrand** to *Starter Templates* — display name only; existing installs upgrade cleanly.
* **New import track** for modern themes with a React wizard, live preview and per-theme adapters.
* **Faster wizard** — fewer round-trips, instant modal open, smarter local caching.
* **Smarter import** — independent toggles for widgets and Customizer settings, Font Library support, plugin info in the wizard.
* **More reliable** background pipeline with better cron + asset handling.
* **UI polish** — refreshed cards, better admin redirects, per-theme action links.
* **Compatibility** — tested up to WordPress 7.0; requires WordPress 5.0+ and PHP 7.4+.

= 1.2.0 =
* Internal: split importer into Legacy + Generic tracks (router based on active theme); no end-user behaviour change for OnePress / Screenr.

= 1.1.9 =
* Fix plugin review issues for WordPress 6.8

= 1.1.0 =
* Bugs fixed.

= 1.0.9 =
* Bugs fixed.

= 1.0.8 =
* Bugs fixed.

= 1.0.7 =
* Improve import username.

= 1.0.6 =
* Improve core and UX.

= 1.0.2 =
* Add recommended plugins notices.

= 1.0.1 =
* Improve and fix bug.

= 1.0.0 =
* Release
