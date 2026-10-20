=== Link Purpose Icons ===
Contributors: itmaybejj
Tags: accessibility, links, external links, icons, screen reader
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds icons and screen reader hints to links that open new windows, leave the site, download files, send email, make calls or open apps.

== Description ==

Link Purpose Icons marks links that introduce a change of context, so sighted users see an icon and screen reader users hear a short hint:

* External links
* Links to documents (PDF, Word, PowerPoint, Google Docs)
* Downloads
* Email and telephone links
* Links that open apps
* Links that open a new window

Each category can be turned off, restyled (inline SVG, icon-font classes, or your own CSS), repositioned, and given its own translatable hint text. Icons stay with the last word of the link so they never wrap onto a line of their own.

This is the WordPress companion to the Drupal module of the same name; both wrap the [Link Purpose JS library](https://itmaybejj.github.io/linkpurpose/).

== Installation ==

1. Upload the plugin and activate it.
2. Visit Settings → Link Purpose. Most sites only need to list any additional domains that should count as internal.

== Frequently Asked Questions ==

= Can I use my own icons? =

Yes. Switch a category's icon type to "Classes" and provide icon-font classes (FontAwesome by default), or leave the type as "Classes" and style the icon wrapper span with your own CSS background images. Themes can also hand the plugin a full options object via the `window.linkPurposeOptions` JavaScript hook or the `linkpurpose_js_settings` PHP filter.

= Does it work on multisite? =

Yes. Network Admin → Settings → Link Purpose lets super administrators set a default for any setting and choose how it propagates: to new sites only, to all sites that have not customized it, or as a lock that every site must use.

== Changelog ==

= 1.0.0 =
* Initial release.
