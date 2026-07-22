# Contributing

Thanks for looking at this. The most valuable contribution to this project is one that makes it unnecessary, so if you are here from the DiviOps side, please read the section at the bottom first.

## Ground rules

Every change starts with an issue. Open one describing the problem before writing code, so the approach can be agreed before anyone spends time on it. The exception is a typo or comment fix with no behavior change.

Work happens on a branch named `dev/<issue-number>-<short-description>`, and lands through a pull request whose body links the issue with `Closes #123`.

Commits follow [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/). The PR title is linted the same way. Commits must be signed.

## Tests come first

This project uses test-driven development, and that is not decorative here. The plugin writes to post content, and a bug in the write path corrupts pages in ways that are tedious to repair.

Write the failing test, confirm it fails for the reason you expect, then make it pass.

```bash
php tests/run.php
```

The suite runs without WordPress. Block-scanning and attribute-merging logic lives in dependency-free pure functions specifically so this works, and new logic should go there rather than into a class that needs a WordPress runtime.

### The differential test

The acceptance gate compares this plugin's computed block indices against real captured DiviOps output. It exists because DiviOps implements block targeting three separate times internally, and the only meaningful correctness question is whether we agree with it.

Do not weaken or skip that test to make a change pass. If it fails after a DiviOps update, that is the test doing its job, and the fix is to understand what changed upstream.

## Style

WordPress Coding Standards for PHP. Match the surrounding code; consistency within a file wins over any external guide.

Machine-facing identifiers take the `rtv_diviops` prefix (options, filters, nonce actions, transient keys). The plugin slug and text domain deliberately do not. See CLAUDE.md.

Comments explain what and why, never what changed or how something used to work.

## Scope

This bridges one specific gap in DiviOps. It is not a general Divi automation tool.

Changes that grow it beyond that will be declined, not because they are bad ideas, but because the goal is for this codebase to shrink and eventually disappear.

## If you maintain DiviOps

You are welcome to take any of this code under the same license, with or without attribution, in whole or in part.

The underlying issue is that the `divi/` namespace is assumed in 16 places across 6 files, and block targeting is implemented three times with two different mechanisms. A single filterable helper that those sites route through would let this plugin drop to roughly fifty lines, and a proper fix would let it be archived entirely.

That is the outcome this project is aiming for. If a pull request would be more useful to you than an issue, say so and one will follow.
