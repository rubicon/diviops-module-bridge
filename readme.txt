=== DiviOps Module Bridge ===
Contributors: rubicon
Tags: divi, diviops, divi5, blocks, rest-api
Requires at least: 6.5
Tested up to: 7.0
Stable tag: 0.1.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lets DiviOps Agent read and write third-party Divi 5 modules. Built to be made obsolete by an upstream fix.

== Description ==

DiviOps Agent reads third-party Divi 5 modules correctly but cannot target them for
editing. Ask it for a page layout and it reports identifiers like `difl/faq:1`. Hand
that same identifier back to `module_get` or `module_update` and it returns not found.

The practical result is that editing a third-party module's attributes requires
rebuilding raw block markup by hand, which is error-prone on pages that use Divi
variable tokens heavily. This plugin removes that step.

It covers 121 declared block types across three namespaces (DiviFlash, Divi Event
Calendar Module, and Divi Background Plus), discovered at runtime rather than
hardcoded, so additional module plugins are picked up automatically.

This plugin depends on DiviOps Agent and does not modify or bundle any of its code.
It hooks WordPress core filters only, and acts only after DiviOps has already failed
a lookup, so normal DiviOps behavior is unchanged.

Supported endpoints: schema_get_module, schema_list_modules, schema_get_module_dump_all,
module_get, and module_update. Third-party blocks also stop being reported as unknown
block types by validate_blocks.

module_move, module_clone, module_lock, and module_unlock share the same underlying
limitation and may begin working incidentally. They are untested and unsupported.

== Installation ==

1. Install and activate DiviOps Agent first. This plugin declares it as a dependency
   and will not activate without it.
2. Upload the plugin folder to /wp-content/plugins/ or install the zip through the
   Plugins screen.
3. Activate the plugin.

There are no settings. The bridge attaches itself to the DiviOps REST routes and
requires no configuration.

== Frequently Asked Questions ==

= Does this work with Divi 4? =

No. Divi 5 only.

= Does this require DiviOps Agent Pro? =

No. It bridges the free, GPL-licensed DiviOps Agent plugin and does not touch Pro.

= What happens when DiviOps fixes this upstream? =

This plugin becomes unnecessary and can be deactivated. That is the intended outcome.

== Changelog ==

= 0.1.0 =
* Initial release.
