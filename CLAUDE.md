# Agent Instructions: diviops-module-bridge

This file carries only what is unique to this repository. It does not restate the
maintainer's general policies; read those first.

- `~/.claude/CLAUDE.md`
- `~/.claude/policies/general-repository-process-policy.md`
- `~/.claude/policies/software-engineering-practices-policy.md`
- `~/.claude/policies/agent-orchestration-and-verification-policy.md`

## Repo-specific overrides

**Canonical host is GitHub, not Forgejo.** This is an originating open-source repo,
which the repository policy's definition of originating repo explicitly permits
("canonical host is Forgejo, or GitHub for open source"). `origin` points to
`github.com/rubicon/diviops-module-bridge`. This is deliberate: the plugin exists
to be found by the DiviOps author, and the upstream project lives on GitHub.

**Vendor prefix applies to internals only.** The repository policy defaults to
`rtv_` for PHP identifiers and `rtv-` for slugs and text domains, "unless the repo
specifies another." This repo specifies:

- Plugin slug and text domain: `diviops-module-bridge`, no prefix. The slug is the
  install directory and the update key and is permanent, and this plugin's purpose
  is to be recognizable to an upstream author who is not us.
- Everything machine-facing: `rtv_` prefix. Constants, option names, filter and
  action names, nonce actions, transient and cache keys.

Both halves of the WordPress overlay still hold: the text domain equals the slug,
and the main plugin file is `diviops-module-bridge.php`.

## Divi generation support

Divi 5 only. Divi 4 is not supported and the plugin does not attempt to detect it.
Do not add a Divi 4 compatibility layer without an issue and explicit approval.

## Development environment

The plugin must live inside `wp-content/plugins/` to run, but the repository is a
separate folder and every issue gets its own worktree. `scripts/repoint-plugin-symlink.sh`
points the WordPress install at the worktree you are currently working in.

Run it after creating or switching a worktree. It prints its target and fails loudly
on a dangling or mismatched link. A stale symlink means you are testing the wrong
branch with no signal, which is worse than having no integration test at all.

Local site: `/Users/daxdavis/Local Sites/colleyvillelions/app/public`

WP-CLI on that site needs the Local MySQL socket. The system `wp` fails with a
database connection error without it:

```bash
SOCK="/Users/daxdavis/Library/Application Support/Local/run/6NaIbVmzy/mysql/mysqld.sock"
php -d mysqli.default_socket="$SOCK" /opt/homebrew/bin/wp \
  --path="/Users/daxdavis/Local Sites/colleyvillelions/app/public" <command>
```

DiviOps' `diviops_meta_wp_cli` passthrough rejects `wp eval`, so runtime block
registry introspection has to go through the invocation above.

## Site constraints

- Do not modify or publish page 900390. It is actively being edited elsewhere and
  must stay draft. Reading it is fine.
- Verify with Dax before writing any change to the local site, including plugin
  activation and scratch page creation.
- Do not reactivate the legacy ANTHEM child theme or activate the Divi parent theme
  directly.

## Testing

```bash
php tests/run.php
```

No Composer, no PHPUnit, no build step. Block-scanning and merging logic is written
as dependency-free pure functions so the suite runs without WordPress.

The acceptance gate is the differential test against captured DiviOps output. If you
change the scanner, that test is the one that matters. Do not weaken it to make a
change pass.
