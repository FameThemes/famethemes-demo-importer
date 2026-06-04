=== FameTheme Demo Importer ===
Contributors: famethemes, shrimp2t
Donate link: https://www.famethemes.com/
Tags: import, demo data, oneclick, famethemes
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: trunk
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One-click demo importer — sync AJAX flow for FameThemes legacy themes, plus a React + background-job track for every other theme pulling templates from a Blocksify Design Studio.

== Description ==

Import demo content, widgets and theme settings with one click. The plugin auto-picks one of two import tracks based on the active theme:

* **Legacy track** — the original synchronous AJAX importer for the FameThemes themes (OnePress, Screenr, plus their `-pro` / child variants by default; the slug list is filterable via `demo_contents_onepress_themes`). Demos are fetched from a GitHub repo (`FameThemes/famethemes-xml-demos` by default).

* **Generic track** — a React-driven dashboard backed by a WP-Cron pipeline that pulls templates from a [Blocksify Design Studio](https://github.com/PressMaximum/blocksify-design-studio) server over the REST API. Five phases: download assets → install required plugins from wp.org → unzip uploads.zip → import terms/posts/menus with ref-mapped placeholders → apply core options, theme mods, customizer, widgets, plugin options. Theme adapters let a specific theme (e.g. Customify) nest the dashboard under its own admin menu and declare per-theme required plugins.

Studio URL defaults to `https://design-library.pressmaximum.com/` and is overridable via the `FT_DEMO_IMPORTER_STUDIO_URL` `wp-config.php` constant or the Settings page.

Original feature set still works:

* One-click demo import for FameThemes official themes
* Recommended plugins installation
* Demo content (posts, pages, menus, customizer, widgets, theme mods)

Get free support at [https://www.famethemes.com/]((https://www.famethemes.com/))

https://www.youtube.com/watch?v=w0OKnqnHYo4

##Add Support for your themes.

### Change Default Demo GitHub Repository.

`apply_filters( 'demo_contents_github_repo', self::$git_repo );`

### Add theme to listing preview

`apply_filters( 'demo_contents_allowed_authors', array('famethemes' => 'FameThemes','daisy themes' => 'Daisy Themes'};`

###Support demo for a theme.
1. Create new theme demo dir in GitHub repo  `username/repo-name/theme-name`.

###Support multiple demos for a theme.
1. Create new theme demo dir in GitHub repo `username/repo-name/theme-name`.
2. Create new json file and name it  `demos.json`, add list demos here.
3. Crate new demo dir and name it `demos`.
4. Add your new demo in new dir `child-demo`, so we have full path like this: `username/repo-name/theme-name/demos/child-demo` and put file `dummy-data.xml` and `config.json`

###Export Demo XML
In Admin screen go to Tools -> Export

###Export config.json

In Admin if user has cap `export`, add ?demo_contents_export in current url.
Example: https://example.com/wp-admin/?demo_contents_export


== Installation ==

1. Upload `famethemes-demo-importer` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Appereance -> (Theme Name) -> Select tab One Click Demo Import or Tools -> Demo Contents to select demo to import.


== Frequently Asked Questions ==

= What is the plugin license? =

* This plugin is released under a GPL license.

= What themes this plugin supports? =

* The plugin currently only supports FameThemes's themes.

= Where can I report bugs or contribute to the project? =

Bugs can be reported either in our support forum or preferably on the [GitHub repository](https://github.com/FameThemes/famethemes-demo-importer/issues).

= FameThemes Demo Importer is awesome! Can I contribute? =

Yes you can! Join in on our [GitHub repository](https://github.com/FameThemes/famethemes-demo-importer/) :)


== Changelog ==
= 1.3.0 =
* New: Generic background-job import track for non-FameThemes themes — React dashboard + WP-Cron pipeline pulling from a Blocksify Design Studio over REST.
* New: Per-theme adapters (`Theme_Adapter`) with built-in Customify adapter that nests the dashboard under Customify's own admin menu.
* New: Studio URL configurable via `FT_DEMO_IMPORTER_STUDIO_URL` `wp-config.php` constant; defaults to `https://design-library.pressmaximum.com/`.
* New: Plugins-row "Import demo" action link — destination depends on active theme (legacy themes → `ft_<slug>` page; others → Generic dashboard).
* New: Activation redirect — sends admin to the right importer surface for the active theme.
* Improved: Legacy code path relocated under `inc/legacy/` (frozen, unchanged behaviour); root plugin file slimmed down to ~165 LOC of routing + activation.
* Improved: WP-Cron loopback gate now also catches `DOING_CRON` requests so background jobs actually fire.
* Improved: `Asset_Fetcher` treats `uploads.zip` as optional — pattern-only templates no longer fatal during fetch.
* Improved: Content importer rewrites `{{ref:post:N}}`, `{{SITE_URL}}`, multisite `/sites/N/` uploads prefix, `wp-image-N` classes, AND raw `"id":N` / `"ids":[…]` block attributes (attachment refs only).
* Improved: Options importer now correctly walks `widgets.widget_*` keys (previously expected `widgets.options.widget_*` — every widget instance was silently dropped), substitutes `{{SITE_URL}}` in theme_mods + widgets, resolves `widget_media_*.attachment_id` (+ `attachment_url_to_postid()` fallback), `widget_media_gallery.ids[]`, `widget_nav_menu.nav_menu`, and runs block-markup rewriting inside `widget_block.content`.
* Bumped: tested up to WordPress 7.0; requires WordPress 5.0+; requires PHP 7.4+.

= 1.2.0 =
* Internal: split importer into Legacy + Generic tracks (router based on active theme); no end-user behaviour change for OnePress / Screenr.

= 1.1.9 =
* Fix plugin review issues for WordPress 6.8

= 1.1.0
* Bugs fixed.

= 1.0.9
* Bugs fixed.

= 1.0.8
* Bugs fixed.

= 1.0.7
* Improve import username.

= 1.0.6
* Improve core and UX.

= 1.0.2
* Add recommend plugins notices.

= 1.0.1
* Improve and fix bug.

= 1.0.0 =
* Release

