# Architecture

## Why this shape

DiviOps hardcodes the `divi/` namespace in 16 places across 6 files, and implements block targeting three separate times with two different mechanisms. There is no filter, action, or setting to extend, so a companion plugin has to intercept from outside.

Two consequences drive every decision below.

**Interception happens after failure, not before.** The bridge attaches to `rest_post_dispatch`. WordPress fires that filter in `WP_REST_Server::serve_request()` after `dispatch()` has already returned, including for `rest_no_route`, so a failed DiviOps lookup reaches us as a normalized response we can inspect with an integer comparison. `rest_pre_dispatch` was rejected because it runs before route matching and cannot see a module's namespace when targeting is by `label` or `match_text`, which would force it to intercept every lookup and change native behavior.

**Mutation splices bytes rather than parsing.** DiviOps' own `module_update` finds byte offsets and replaces in place. Parsing the document into a block tree and calling `serialize_blocks()` would renormalize whitespace and attribute ordering across blocks that were never edited, which is the same class of change that corrupts pages carrying many `$variable({...})$` tokens. Matching the incumbent mechanism is safer here than improving on it, and it removes the need to reimplement empty-object restoration across a whole document.

## Layout

```
diviops-module-bridge.php     Plugin header, constants, bootstrap
includes/
  class-block-scanner.php     PURE. Content + target to byte offsets and names
  class-attr-merge.php        PURE. One block's attribute JSON + patch
  class-module-index.php      Glob discovery of module.json, cached
  class-schema-reader.php     Live registry first, module.json fallback
  class-safe-writer.php       Slash, write, byte-exact readback, auto-revert
  class-rest-shim.php         Guards, route matching, delegation
tests/
  run.php                     Plain-PHP assertion runner, no WordPress
  fixtures/                   Captured DiviOps output for differential tests
scripts/
  repoint-plugin-symlink.sh   Points the local WordPress install at a worktree
```

## The pure core

`class-block-scanner.php` and `class-attr-merge.php` have no WordPress dependencies. They are plain functions over strings and arrays, which is what makes `php tests/run.php` possible without bootstrapping WordPress.

This split is deliberate and load-bearing. The hard part of testing a WordPress plugin is untangling logic from the runtime, and doing that up front keeps a later move to PHPUnit mechanical.

## The compatibility tax

The scanner matches DiviOps' `module_update` implementation. Two other targeting implementations exist inside DiviOps (`find_block()` and `walk_and_mutate()`). If an upstream release changes any of them, the differential test in `tests/` reports the divergence. It does not prevent it.

This tax is the reason the upstream issue asks for a single extension point rather than reporting individual bugs. If that lands, most of this code goes away.

## Vendor prefix

Machine-facing identifiers use `rtv_` (constants, options, filters, nonce actions, transient keys). The plugin slug and text domain are `diviops-module-bridge` with no prefix, because those are public identity and the slug is permanent. See CLAUDE.md.

## Namespace boundary

DiviOps Agent (free, GPL-2.0-or-later) and DiviOps Agent Pro (commercial) share the `diviops/v1` REST namespace. The route guard enumerates the five specific paths this plugin handles rather than matching the namespace prefix, so the bridge never sits in the response path of the commercial plugin.
