# DiviOps Module Bridge

A small WordPress plugin that lets [DiviOps Agent](https://github.com/oaris-dev/diviops) read and write third-party Divi 5 modules.

**This plugin is built to be made obsolete.** Everything it does would be better done inside DiviOps itself, and the upstream issue asks for exactly that. If the DiviOps author adds a single extension point, this repository collapses to roughly fifty lines. If they fix the namespace handling directly, it can be archived. Either outcome is a success.

## The problem

DiviOps reads third-party modules perfectly well. Ask it for a page layout and it happily reports `difl/faq:1` or `d5bgo/bg-overlay:2`. Hand that identifier straight back to `module_get` or `module_update` and it returns `not_found`.

That asymmetry means the only way to edit a third-party module's attributes is to rebuild the raw block markup by hand and push it through `section_replace`. That is error-prone in a specific and expensive way: a page carrying hundreds of `$variable({...})$` tokens can lose quote escapes during reconstruction, and DiviOps' own validator will then reject every later write to that page.

Removing hand-reconstruction as a step is the entire point of this plugin.

## What it covers

121 declared block types across three namespaces, discovered at runtime rather than hardcoded, so additional module plugins are picked up without a code change.

| Namespace | Plugin | Declared | Registered |
| --------- | ------ | -------- | ---------- |
| `difl/*` | DiviFlash | 112 | 108 |
| `decm/*` | Divi Event Calendar Module | 8 | 0 (plugin inactive) |
| `d5bgo/*` | Divi Background Plus | 1 | 1 |

Schema answers say which source resolved them, so a caller is never misled into placing a block whose plugin is inactive.

## What it does

Bridges five DiviOps endpoints: `schema_get_module`, `schema_list_modules`, `schema_get_module_dump_all`, `module_get`, and `module_update`. It also stops `validate_blocks` from reporting third-party modules as unknown block types.

It attaches to `rest_post_dispatch` and acts only after DiviOps has already failed a lookup, so normal DiviOps behavior is left alone.

`module_move`, `module_clone`, `module_lock`, and `module_unlock` share the same underlying limitation and may start working incidentally. They are untested here and are not supported.

## Requirements

- WordPress 6.5 or later
- PHP 7.4 or later
- DiviOps Agent (the free, GPL-licensed plugin)
- Divi 5. Divi 4 is not supported.

## Relationship to DiviOps

This plugin depends on DiviOps Agent and does not modify, bundle, or redistribute any of its code. It hooks WordPress core filters only.

DiviOps Agent is GPL-2.0-or-later, and this plugin is licensed the same way for compatibility. DiviOps Agent Pro is a separate commercial product. This plugin does not touch it, and the route guard enumerates specific paths rather than matching the shared `diviops/v1` namespace so that stays true.

Nothing here is a criticism of DiviOps, which does a great deal of difficult work well. This is one gap in an otherwise capable tool, and the maintainers are welcome to take any of this code under the same license.

## Development

```bash
php tests/run.php
```

Tests run without WordPress loaded. The block-scanning and attribute-merging logic is written as dependency-free pure functions specifically so this is possible.

The acceptance gate is a differential test: for a fixture page containing both `divi/*` and third-party blocks, every index this plugin computes must match the one DiviOps itself reports. That test is what catches divergence when DiviOps updates.

See [ARCHITECTURE.md](ARCHITECTURE.md) for layout and [CONTRIBUTING.md](CONTRIBUTING.md) to contribute.

## Contributors

![Contributors](https://contrib.rocks/image?repo=rubicon/diviops-module-bridge)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
