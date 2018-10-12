=== FameTheme Demo Importer ===
Contributors: famethemes, shrimp2t
Donate link: https://www.famethemes.com/
Tags: import, demo data, oneclick, famethemes
Requires at least: 4.5
Tested up to: 4.8.2
Stable tag: 1.1.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

FameThemes Demo importer

== Description ==

Import your demo content, widgets and theme settings with one click for [FameThemes](https://www.famethemes.com/) official themes.

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

