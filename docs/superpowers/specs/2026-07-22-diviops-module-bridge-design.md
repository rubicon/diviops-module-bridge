# DiviOps Module Bridge — Design

**Date:** 2026-07-22
**Status:** Draft, pending review
**Author:** Dax Davis

## Problem

DiviOps Agent cannot target third-party Divi 5 modules for reading or writing. It
reads them fine in a layout dump, then refuses every attempt to address them.

The practical cost is that editing a third-party module's attributes requires
hand-reconstructing raw block markup and pushing it through
`diviops_section_replace`. That is what corrupted page 900390: 414
`$variable({...})$` tokens lost the backslash on their inner quote escapes, which
made DiviOps' own validator reject all further section writes to the page.

Eliminating hand-reconstruction as a step is the point of this plugin.

## Affected modules

121 declared block types across three namespaces, discovered by scanning
`<plugin>/**/modules-json/*/module.json` and reading the JSON `name` field.

| Namespace | Plugin | Version | Plugin state | Declared | Registered |
| --------- | ------ | ------- | ------------ | -------- | ---------- |
| `difl/*` | diviflash | 5.3.1 | active | 112 | 108 |
| `decm/*` | divi-event-calendar-module | 3.0.9 | inactive | 8 | 0 |
| `d5bgo/*` | divi-background-plus | 3.0.5 | active | 1 | 1 |
| | | | **Total** | **121** | **109** |

The 12-module gap matters: 8 `decm/*` are invisible because their plugin is
inactive, and 4 `difl/*` are gated off by DiviFlash's own module manager. Any
schema answer must say which tier answered so a caller is not misled into placing
a block whose plugin is inactive.

Block names come from the JSON `name` field, never the directory name. DiviFlash's
directory naming is inconsistent (`FAQ`, `flip-box`, `BusinessHoursItem`).

The registry is discovered, never hardcoded, so future module plugins are picked
up without a code change.

## Root cause

DiviOps hardcodes the `divi/` namespace in 16 places across 6 files. The defect is
a read/write asymmetry: `page_get_layout` is namespace-agnostic and happily emits
`difl/faq:1`, but every targeting path rejects that same identifier.

Critically, DiviOps implements block targeting three separate times:

| Consumer | Mechanism | Location |
| -------- | --------- | -------- |
| `module_get`, `module_move`, `module_clone` | raw string scan on `BLOCK_PREFIX` | `find_block()`, `trait-page.php:2144` |
| `module_update` | its own separate inline raw string scan | `trait-page.php:1407` |
| `module_lock`, `module_unlock`, others | parsed tree walk | `walk_and_mutate()`, `trait-page.php:3049` |

`BLOCK_PREFIX` is `'<!-- wp:divi/'`. It cannot simply be widened to `'<!-- wp:'`
because that matches `core/paragraph`, `gravityforms/form`, and every other block
in the document.

Notably, `walk_and_mutate`'s label mode has no namespace gate and would already
work. `module_update` never reaches it.

No extension hook exists. The only filter in either DiviOps plugin is
`diviops_agent_handshake_extensions`, which advertises capability to the MCP
client and does nothing for schema, targeting, or `BLOCK_PREFIX`.

## Corrections to earlier analysis

Recorded because acting on the superseded versions would produce wrong code.

- DiviOps' write path does **not** eat backslashes. `update_post_content_with_integrity_guard()` (`trait-core.php:196`) calls `wp_slash()` and performs a byte-exact readback that auto-reverts on drift. The 414 corrupted tokens were already malformed in the payload handed to `section_replace`. This must not appear in the upstream issue; it is false and would discredit the report.
- `find_block()` is **not** the single choke point. There are three independent targeting implementations (above).
- The upstream fix is **not** five lines. It is 16 hardcodes across 6 files, one of which needs real design work.
- The schema route regex already permits slashes (`[a-zA-Z0-9_/-]+`), so `difl/faq` matches the route. `schema_get_module` fails inside the handler by blindly prepending `divi/`, returning its own 404 envelope.

## Scope

**In scope for v1:** `schema_get_module`, `schema_list_modules`,
`schema_get_module_dump_all`, `module_get`, `module_update`, and suppression of
`unknown_block_type` in `diviops_validate_blocks`.

**Out of scope:** `module_move`, `module_clone`, `module_lock`, `module_unlock`.
These share the defect and may begin working incidentally. They are untested and
must not be advertised.

**Explicitly excluded:** Divi-core attribute validation rules (gated behind
`$is_divi_block` at `trait-validate.php:104`) stay off. They are tuned to Divi
core attribute shapes and would emit false warnings on third-party modules. A
feature that emits wrong warnings is worse than no feature.

**Explicitly excluded:** `divi/global-layout`. It fails validation as unknown, but
it is in the `divi/` namespace and is a different root cause. Hardcoding a
Divi-core block name we do not understand is a guess. Raised as an open question
in the upstream issue instead.

**Not touched:** `diviops-agent-pro`. It is commercially licensed and shares the
`diviops/v1` REST namespace, so the route guard enumerates paths rather than
matching the namespace prefix.

## Architecture

### Interception: `rest_post_dispatch`

The bridge acts only after DiviOps has already failed. Verified in WordPress core:
a `rest_no_route` becomes a `WP_Error` from `match_request_to_handler()`
(`class-wp-rest-server.php:1220`), is converted by `error_to_response()` inside
`dispatch()`, and reaches the filter at `class-wp-rest-server.php:464`. DiviOps'
own envelope errors arrive the same way.

Two guards, cheapest first:

1. Responses under HTTP 400 return immediately, except for the two list endpoints the bridge augments.
2. The route must be one of five paths, all of which live in the GPL-licensed free plugin.

`rest_pre_dispatch` was rejected. It cannot handle `label` or `match_text`
targeting, because nothing in those requests reveals the module's namespace.
Pre-dispatch would have to intercept every label lookup, changing native
behavior, or leave label mode permanently broken.

**Known limitation, to be stated in the plugin header:** `rest_post_dispatch`
fires only for real HTTP requests. In-process `rest_do_request()` callers go
straight through `dispatch()` and bypass the bridge entirely. This is acceptable
because the MCP server makes real HTTP calls, but a PHP-side caller would silently
skip us and that must not cost someone a debugging session.

**Property, stated precisely:** interception is narrow, not inert. The bridge
augments two successful responses, and any genuine `divi/*` 404 enters the
resolver. A false positive there would convert a correct error into a wrong
success. The resolver must return "not mine" for anything it cannot positively
identify as a discovered third-party block.

### Mutation: byte-splice, not parse-and-serialize

The bridge mirrors DiviOps' own mechanism rather than improving on it.

DiviOps' `module_update` splices bytes and touches only the target block. A
parse into a block tree followed by `serialize_blocks()` round-trips the entire
document, renormalizing whitespace and attribute ordering across blocks that were
never edited. On a page carrying hundreds of `$variable({...})$` tokens, that
round-trip is precisely the operation that reintroduces the escaping corruption
class this plugin exists to prevent.

Splicing also removes the need to reimplement `restore_blocks_empty_objects()`
across the document. The `{}` to `[]` hazard shrinks to a single block's attribute
JSON, where it is exhaustively testable.

Matching the incumbent beats a cleaner mechanism when both write the same
document.

### Components

| File | Pure | Responsibility |
| ---- | ---- | -------------- |
| `class-block-scanner.php` | yes | content plus target to byte offsets, block name, auto_index |
| `class-attr-merge.php` | yes | one block's attribute JSON plus patch to new JSON, empty objects preserved |
| `class-module-index.php` | no | glob discovery, transient-cached, invalidated on plugin activation |
| `class-schema-reader.php` | no | tier 1 live registry, tier 2 `module.json`; labels `registered` and `source` |
| `class-safe-writer.php` | no | `wp_slash`, write, byte-exact readback, auto-revert |
| `class-rest-shim.php` | no | guards, route match, delegation |

`short_name()` mirrors DiviOps exactly: `divi/text` becomes `text`, `difl/faq`
stays `difl/faq`. That is already what `page_get_layout` emits, so the indices
shown in a layout dump are the indices that work. No new targeting syntax.

### Schema reading

Two tiers, because the live registry alone is insufficient for the 12 modules it
cannot see.

1. `WP_Block_Type_Registry` (authoritative). Read `->attributes`, falling back to `->attrs`.
2. `module.json` on disk.

The `->attrs` fallback is required because Divi's `ModuleRegistration` merges
unmapped top-level `module.json` keys into the block type as dynamic properties.
`difl/*` uses `attributes` and matches Divi core; `d5bgo/bg-overlay` uses
top-level `attrs`, leaving `->attributes` holding only four WordPress defaults.

Every schema response carries `registered` and `source` so a caller knows which
tier answered.

For `dump_all`, DiviOps' `schema_version` hashes Divi core preset maps. That field
is left untouched; a separate `bridge_schema_version` hashes the third-party
`module.json` files, so neither cache key lies about the other.

## Testing

### The acceptance gate, written first

> Given a fixture page containing both `divi/*` and `difl/*` blocks, every
> `auto_index` the scanner computes must equal the one DiviOps' own
> `page_get_layout` emits for that same block.

DiviOps' real output is captured once as a committed fixture and asserted against
offline with no WordPress loaded. This is the only test that answers the question
that matters, and it catches divergence on every future DiviOps release. Our own
unit tests structurally cannot.

### Everything else

Conventional TDD beneath it: attribute merge, empty-object preservation, splice
boundaries, short-name derivation, auto_index counting over a mixed fixture tree.

All block-scanning and merging logic lives in dependency-free pure functions
tested by a committed runner invoked as `php tests/run.php` with no WordPress
loaded. A handful of live integration checks then run against a scratch page.

Separating pure logic from WordPress is the hard part of testability. Doing it now
makes a later move to PHPUnit mechanical.

## Risks

**Permanent compatibility tax.** The bridge matches DiviOps' `module_update`
scanner. Two other targeting implementations exist. If an upstream release changes
any of them, the differential test reports it; it does not prevent it. This tax
persists until upstream lands an extension point, which is what the upstream issue
asks for.

**Escalation ladder.** If upstream goes unresponsive, or a DiviOps release breaks
the differential test, the next rung is forking the free plugin, not rebuilding.
`diviops-agent` is GPL-2.0-or-later, so a fork inherits 24,184 working lines and
changes 16 of them. That option costs nothing to hold and does not expire.

## Licensing

Verified from three independent sources: the upstream repo `LICENSE` (dual, MIT
for the MCP server and skills, GPL-2.0-or-later explicitly for the WordPress
plugins), the `diviops-agent.php` plugin header, and `diviops-agent/readme.txt:8`.

`diviops-agent-pro` is commercial and is not in the public repo. The bridge does
not touch it.

GPL-2.0-or-later for this plugin is compatible and correct. No DiviOps code is
bundled or redistributed; the bridge hooks WordPress core filters.

## Upstream

An issue is filed asking for a single filterable extension point, such as
`is_divi_managed_block( $name )` or a block-prefix filter that the 16 sites route
through. That is a smaller and more acceptable ask than a full namespace refactor,
it fixes the class rather than the instance, and it would collapse this plugin to
roughly fifty lines.

The issue is drafted for review before it is sent. It follows the Outbound PR
Authorship Standard.

## Supported environments

Divi 5 only. Divi 4 is not supported and the plugin does not attempt to detect it.
The bridge depends on `diviops-agent` and declares it via the `Requires Plugins`
header.
