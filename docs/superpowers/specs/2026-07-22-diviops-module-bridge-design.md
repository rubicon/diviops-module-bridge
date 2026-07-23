# DiviOps Module Bridge — Design

**Date:** 2026-07-22 (revised 2026-07-23)
**Status:** Approved
**Author:** Dax Davis

The 2026-07-23 revision corrects the targeting table (`module_clone` uses the tree
walker, not `find_block`), replaces the offline differential test as the drift
guarantee with a lazy runtime check, corrects the `rest_post_dispatch` filter
surface, tightens index invalidation, and recounts the namespace-gate figure. All
corrections are verified against DiviOps 1.5.10 and WordPress 7.0.2.

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

DiviOps hardcodes the `divi/` namespace across the module-targeting and schema
paths (see the recounted figure under Corrections). The defect is a read/write
asymmetry: `page_get_layout` is namespace-agnostic and happily emits `difl/faq:1`,
but every targeting path rejects that same identifier.

Critically, DiviOps implements block targeting three separate times. The consumer
assignments below come from the call sites, not from `find_block`'s docblock, which
lists endpoints it no longer serves:

| Consumer | Mechanism | Location |
| -------- | --------- | -------- |
| `module_get`, `module_move` | raw string scan on `BLOCK_PREFIX` | `find_block()`, `trait-page.php:2144` (called only from `:1258`, `:2502`, `:2516`) |
| `module_update` | its own separate inline raw string scan | `trait-page.php:1407` |
| `module_lock`, `module_unlock`, `module_clone` | parsed tree walk | `walk_and_mutate()`, `trait-page.php:3049` (called from `:3358`, `:3458`, `:3568`) |

`BLOCK_PREFIX` is `'<!-- wp:divi/'`. It cannot simply be widened to `'<!-- wp:'`
because that matches `core/paragraph`, `gravityforms/form`, and every other block
in the document.

Notably, `walk_and_mutate` gates only its `auto_index` mode (the short-name
ternary at `trait-page.php:3105`). Its `label` and `match_text` modes have no
namespace gate, so `module_clone`, `module_lock`, and `module_unlock` already
resolve third-party blocks in those two modes today. `module_update` uses the
string scan and never reaches the walker at all. See the per-mode support matrix
under Scope.

No extension hook exists. The only filter in either DiviOps plugin is
`diviops_agent_handshake_extensions`, which advertises capability to the MCP
client and does nothing for schema, targeting, or `BLOCK_PREFIX`.

## Corrections to earlier analysis

Recorded because acting on the superseded versions would produce wrong code.

- DiviOps' write path does **not** eat backslashes. `update_post_content_with_integrity_guard()` (declared at `trait-core.php:196`) calls `wp_slash()` at `:204` and performs a byte-exact readback that auto-reverts on drift (revert at `:228`). The 414 corrupted tokens were already malformed in the payload handed to `section_replace`. This must not appear in the upstream issue; it is false and would discredit the report.
- `find_block()` is **not** the single choke point. There are three independent targeting implementations (above), and `module_clone` uses the tree walker, not `find_block`. The earlier assignment came from reading `find_block`'s docblock instead of its call sites. When a docblock and a call graph disagree, the call graph wins: the docblock records intent, the call graph records behavior.
- The upstream fix is **not** five lines, and it is **not** the "16 hardcodes across 6 files" the earlier draft claimed. Against the definition "a conditional on the `divi/` prefix (or the `BLOCK_PREFIX` string-scan it drives) whose result routes, counts, resolves, or filters a block," verified against DiviOps 1.5.10, there are **13 gates in the module-targeting and schema paths** across three files: nine in `trait-page.php` (`module_update`'s inline scan `:1407`, `find_block`'s scan `:2144`, the two read-enumeration counters `:1806` and `:1845`, the `walk_and_mutate` `auto_index` counter `:3069` and its short-name ternary `:3105`, the registered-type count `:3014`, the content-detection check `:2859`, and the namespace-prefixed `block_name` in `module_get`'s output `:1289`), three in `trait-module-schema.php` (the `schema_list` and `dump_all` filters `:30` and `:67`, and the blind `divi/` prepend `:210`), and one in `trait-core.php` (the block-processing gate `:66`). Theme-builder endpoints carry three more gates of the same class (`trait-theme-builder.php:533`, `:707`, `:1386`); they are outside the bridge's v1 scope but would be part of an upstream fix. The earlier six-file figure wrongly counted `trait-validate.php`'s Divi-core-specific validation rules (`divi/button`, `divi/heading`, and the like), which are legitimately namespace-specific and must not be generalized. Recount against this same definition before the number reaches the upstream issue.
- The schema route regex already permits slashes (`[a-zA-Z0-9_/-]+`), so `difl/faq` matches the route. `schema_get_module` fails inside the handler by blindly prepending `divi/` (`trait-module-schema.php:210`), returning its own 404 envelope.

## Scope

**In scope for v1:** `schema_get_module`, `schema_list_modules`,
`schema_get_module_dump_all`, `module_get`, `module_update`, and suppression of
`unknown_block_type` in `diviops_validate_blocks`.

**Out of scope, but not blocked:** `module_move`, `module_clone`, `module_lock`,
`module_unlock`. The bridge's five-path route guard does not cover them, so the
bridge changes nothing about them either way. Do not block them; blocking would
remove capability that already exists. Their real behavior today, per targeting
mode:

| Endpoint | `label` | `match_text` | `auto_index` (`difl/faq:1`) |
| -------- | ------- | ------------ | --------------------------- |
| `module_clone`, `module_lock`, `module_unlock` | works today | works today | unsupported, fails closed |
| `module_move` | via `find_block`, dead for third-party | dead | dead |

`auto_index` is unsupported rather than dangerous because it fails closed: for a
non-`divi/` block the short-name ternary at `trait-page.php:3105` yields `''`,
which can never equal a real type, so the index matches nothing rather than
resolving to some other block. A clean miss, never a wrong write. This is what
makes shipping "index unsupported" safe. Document the matrix; do not advertise
these endpoints as bridge features, and do not test them as such in v1.

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

**Filter surface, stated precisely (verified against WP 7.0.2):**
`rest_post_dispatch` is applied in three places — `serve_request()`
(`class-wp-rest-server.php:464`, real HTTP requests), `embed_links()` (`:824`,
in-process `?_embed` sub-requests), and `serve_batch_request_v1()` (`:1868`,
in-process `/batch/v1` sub-requests). It is **not** applied inside `dispatch()`
(`:1063`). So a plain `rest_do_request()` bypasses the bridge (it calls
`dispatch()` directly, `rest-api.php:605-608`), but `_embed` and batch
sub-requests do not — they reach the filter carrying a real DiviOps route.

**Known limitation, to be stated in the plugin header:** a direct in-process
`rest_do_request()` caller skips the bridge entirely. This is acceptable because
the MCP server makes real HTTP calls, but a PHP-side caller would silently skip us
and that must not cost someone a debugging session.

**Live interception surface to test:** `/batch/v1` is a supported WP endpoint. If
the MCP server ever wraps DiviOps calls in a batch, those sub-requests hit the
filter at `:1868` with a real DiviOps route and the guard sees them. The guard
must behave correctly there, so batch is an explicit test target, not a
hypothetical. `_embed` is the same shape at lower risk, since embedding a DiviOps
route is unlikely.

That the bridge does not intercept a direct `dispatch()` call is also the property
the drift check depends on: it lets the check obtain DiviOps' own unaugmented
`page_get_layout` via `rest_do_request()` with no recursion. See Drift detection.

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
| `class-module-index.php` | no | glob discovery, transient-cached, invalidated on plugin activation, deactivation, and upgrade, with a TTL or fingerprint backstop |
| `class-drift-check.php` | no | lazy version-triggered behavioral verification; three-state verdict cached by DiviOps version |
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

### Index invalidation

The index is invalidated on plugin activation, deactivation, and upgrade, with a
TTL or content fingerprint as a backstop. Activation alone is insufficient and the
spec supplies its own counter-example: four `difl/*` modules are gated off by
DiviFlash's own module manager. Toggling one there fires no plugin activation hook,
and neither does deactivation or a plugin upgrade. Without those hooks the
`registered` and `source` fields, added specifically so a caller is not misled, go
stale and start lying. The fingerprint backstop covers the case an event is missed
entirely.

### Drift detection

The offline fixture test (see Testing) cannot detect DiviOps upstream drift. It
asserts our scanner against a frozen snapshot, so a DiviOps release that changes
`auto_index` semantics leaves the snapshot stale, our scanner still matches the
stale snapshot, the test stays green, and production is silently wrong. It is the
same class of gate the phpcs and test-runner false greens were: it passes without
checking the thing it claims to check.

The runtime guarantee is version-as-trigger, behavior-as-check, run lazily:

- Record the DiviOps version the fixture was captured from.
- On the first third-party targeting request after the installed version differs,
  run the real comparison **once**: call DiviOps' own `page_get_layout` for the
  page named in that request via `rest_do_request()`, and diff the indices it
  emits against what our scanner computes for the same page.
- Cache the verdict keyed on the DiviOps version. Re-check only when the version
  changes again, not per request.

This needs no scratch page and no synthetic content. A request to target a
`difl/*` block, by definition, names a page that contains `difl/*` blocks, so the
check runs against the caller's own real page at the only moment it matters. The
reference call is clean because `dispatch()` applies no `rest_post_dispatch` filter
(see Interception), so the bridge does not intercept its own reference and there is
no recursion. The cost is one extra internal layout call, once per DiviOps version,
on the first bridge write; installs that never use the bridge pay nothing. The
documented `rest_do_request()` limitation is turned into the mechanism: the reason
a PHP-side caller skips the bridge is the reason the bridge can use a PHP-side call
to get an unaugmented reference.

**Three-state verdict.** The check can pass, fail, or fail to run (the layout call
errors, the named page turns out to hold no third-party blocks, and so on). The
third state is neither pass nor fail. Collapsing it into either is the
absence-of-evidence bug: "could not check" is not "checked and matched." Cache all
three states.

**Failure behavior is asymmetric by operation.** A wrong index on a read is visible
to the caller and costs a retry; a wrong index on a write corrupts a page, which is
the exact failure this plugin exists to prevent.

- On `fail`: reads (`schema_get_module`, `schema_list_modules`, `dump_all`,
  `module_get`) continue and carry a drift warning in the response. Writes
  (`module_update`) refuse.
- On `uncertain`: treated as `fail` for writes, since an unverified scanner writing
  to a page is the unrecoverable direction. Logged and reported distinctly from a
  real mismatch, so the two are never confused.

The message names the actual condition — verified against DiviOps X, running Y,
index check failed on page N — never a generic "compatibility issue."

## Testing

### The acceptance gate, written first

> Given a fixture page containing both `divi/*` and `difl/*` blocks, every
> `auto_index` the scanner computes must equal the one DiviOps' own
> `page_get_layout` emits for that same block.

DiviOps' real output is captured once as a committed fixture and asserted against
offline with no WordPress loaded. This is the strongest test the offline suite can
run, and it is what proves the scanner correct against real DiviOps output at
capture time.

It is **not** the drift guarantee. A committed fixture is a frozen snapshot; it
cannot notice that a later DiviOps release changed the semantics it was captured
under. That job belongs to the runtime Drift detection check, which is the primary
guarantee against upstream drift. The offline fixture test remains valuable as a
capture-time correctness check and as the source of the fixture the drift check
compares against, and a dev-time re-capture command is worth having as a workflow
convenience. Neither replaces the runtime check.

### Acceptance criteria carried from review

- The `schema_list_modules` / `dump_all` augmentation is the one place the
  "only act after DiviOps has already failed" property is deliberately broken:
  those two succeed today and must be augmented. That carve-out gets its own test,
  because it is where a false positive would corrupt a currently-correct response.
- "Return not mine unless positively identified" is a red test, not a stated
  principle: a genuine `divi/*` 404 handed to the resolver must come back "not
  mine" rather than resolving to a third-party block, so a correct error is never
  converted into a wrong success.

### Everything else

Conventional TDD beneath it: attribute merge, empty-object preservation, splice
boundaries, short-name derivation, auto_index counting over a mixed fixture tree,
and the three-state drift verdict including the uncertain path.

All block-scanning and merging logic lives in dependency-free pure functions
tested by a committed runner invoked as `php tests/run.php` with no WordPress
loaded. A handful of live integration checks then run against a scratch page.

Separating pure logic from WordPress is the hard part of testability. Doing it now
makes a later move to PHPUnit mechanical.

## Risks

**Permanent compatibility tax.** The bridge matches DiviOps' `module_update`
scanner. Two other targeting implementations exist. If an upstream release changes
any of them, the runtime drift check reports it on the first write after the
version change; it does not prevent it. This tax persists until upstream lands an
extension point, which is what the upstream issue asks for.

**Escalation ladder.** If upstream goes unresponsive, or the drift check starts
failing after a DiviOps release, the next rung is forking the free plugin, not
rebuilding. `diviops-agent` is GPL-2.0-or-later, so a fork inherits its working
lines and changes only the namespace gates counted under Corrections. That option
costs nothing to hold and does not expire.

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
`is_divi_managed_block( $name )` or a block-prefix filter that the namespace gates
route through. That is a smaller and more acceptable ask than a full namespace
refactor, it fixes the class rather than the instance, and it would collapse this
plugin to roughly fifty lines. The exact gate count that sizes the ask must be
recounted against the definition under Corrections before the issue is sent; the
"16 sites" figure was superseded.

The issue is drafted for review before it is sent. It follows the Outbound PR
Authorship Standard.

## Supported environments

Divi 5 only. Divi 4 is not supported and the plugin does not attempt to detect it.
The bridge depends on `diviops-agent` and declares it via the `Requires Plugins`
header.
