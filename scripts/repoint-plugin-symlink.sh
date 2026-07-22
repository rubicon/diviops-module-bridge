#!/usr/bin/env bash
#
# repoint-plugin-symlink.sh
#
# Points the local WordPress install at the git worktree you are currently in, so
# integration checks exercise the branch you are actually on.
#
# WHEN TO USE THIS
#   After creating a worktree, after switching between worktrees, and any time you
#   are about to run a live check against the local site. Cheap to run redundantly.
#
# WHY IT EXISTS
#   The repository policy requires a worktree per issue branch, but a WordPress
#   plugin has to live under wp-content/plugins/ to run. Without repointing, you
#   test whatever branch the symlink happens to reference. A stale link gives you a
#   green result for code you are not editing, which is worse than no check at all.
#
# USAGE
#   scripts/repoint-plugin-symlink.sh           Repoint at the current worktree
#   scripts/repoint-plugin-symlink.sh --check   Verify only; nonzero exit on drift
#   scripts/repoint-plugin-symlink.sh --help
#
# SAFETY
#   Refuses to touch the target if it is a real directory rather than a symlink,
#   so a normally-installed copy of the plugin is never deleted.

set -euo pipefail

readonly PLUGIN_SLUG="diviops-module-bridge"
readonly WP_PLUGINS_DIR="/Users/daxdavis/Local Sites/colleyvillelions/app/public/wp-content/plugins"
readonly LINK_PATH="${WP_PLUGINS_DIR}/${PLUGIN_SLUG}"

MODE="repoint"
case "${1:-}" in
	--check) MODE="check" ;;
	--help|-h) sed -n '3,28p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
	"") ;;
	*) echo "error: unknown argument '$1' (try --help)" >&2; exit 2 ;;
esac

if ! command -v git >/dev/null 2>&1; then
	echo "error: git not found on PATH" >&2
	exit 1
fi

if ! WORKTREE_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)"; then
	echo "error: not inside a git worktree. cd into the repo or a worktree first." >&2
	exit 1
fi

BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo '<no commits yet>')"

if [ ! -d "$WP_PLUGINS_DIR" ]; then
	echo "error: WordPress plugins directory not found:" >&2
	echo "       $WP_PLUGINS_DIR" >&2
	echo "       Is the Local site present? Update WP_PLUGINS_DIR in this script if it moved." >&2
	exit 1
fi

# Report the existing state before changing anything. A dangling link is the
# failure mode after `git worktree remove`, and WordPress will either fatal or
# quietly drop the plugin from the admin list when it happens.
if [ -L "$LINK_PATH" ]; then
	CURRENT_TARGET="$(readlink "$LINK_PATH")"
	if [ ! -e "$LINK_PATH" ]; then
		echo "WARNING: existing symlink is DANGLING (worktree was removed)"
		echo "         $LINK_PATH -> $CURRENT_TARGET"
	fi
elif [ -e "$LINK_PATH" ]; then
	echo "error: $LINK_PATH exists and is NOT a symlink." >&2
	echo "       Refusing to delete it. If this is a real installed copy of the" >&2
	echo "       plugin, move or remove it yourself, then rerun." >&2
	exit 1
else
	CURRENT_TARGET=""
fi

if [ "$MODE" = "check" ]; then
	if [ ! -L "$LINK_PATH" ]; then
		echo "FAIL: no symlink at $LINK_PATH" >&2
		echo "      Run scripts/repoint-plugin-symlink.sh" >&2
		exit 1
	fi
	if [ "$(readlink "$LINK_PATH")" != "$WORKTREE_ROOT" ]; then
		echo "FAIL: symlink does not point at this worktree." >&2
		echo "      linked:  $(readlink "$LINK_PATH")" >&2
		echo "      current: $WORKTREE_ROOT (branch $BRANCH)" >&2
		echo "      You would be testing the wrong branch. Run without --check to fix." >&2
		exit 1
	fi
	echo "OK: $LINK_PATH -> $WORKTREE_ROOT (branch $BRANCH)"
	exit 0
fi

rm -f "$LINK_PATH"
ln -s "$WORKTREE_ROOT" "$LINK_PATH"

echo "Plugin symlink repointed."
echo "  link:   $LINK_PATH"
echo "  target: $WORKTREE_ROOT"
echo "  branch: $BRANCH"
