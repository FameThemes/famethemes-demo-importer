=== FameTheme Demo Importer ===
Contributors: famethemes, shrimp2t
Donate link: https://www.famethemes.com/
Tags: import, demo data, oneclick, famethemes
Requires at least: 4.5
Tested up to: 4.6
Stable tag: 1.0.6
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

FameThemes Demo importer

== Description ==

Import demo content for FameThemes’s themes with just one click.


###Support demo for a theme.
1. Create new theme demo dir in GitHub repo `username/repo-name/theme-name`.

###Support multiple demos for a theme.
1. Create new theme demo dir in GitHub repo `username/repo-name/theme-name`.
2. Create new json file and name it  `demos.json`, add list demos here.
3. Crate new demo dir and name it `demos`.
4. Add your new demo in new dir `child-demo`, so we have full path like this: `username/repo-name/theme-name/demos/child-demo` and put file `dummy-data.xml` and `config.json`


###Export config.json

In Admin if user has cap `export` add ?demo_contents_export in current url.
Example: https://example.com/wp-admin/?demo_contents_export

###Working with themes:

- [Screenr](https://wordpress.org/themes/screenr/)
- [Boston](https://wordpress.org/themes/boston/).
- [OnePress](https://wordpress.org/themes/onepress/)

== Installation ==

1. Upload `famethemes-demo-importer` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Appereance -> (Theme Name) -> Select tab One Click Demo Import or Tools -> Demo Contents to select demo to import.



== Changelog ==
= 1.0.6
* Improve core and UX.

= 1.0.2
* Add recommend plugins notices.

= 1.0.1
* Improve and fix bug.

= 1.0.0 =
* Release

