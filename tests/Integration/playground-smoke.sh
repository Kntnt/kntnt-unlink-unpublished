#!/usr/bin/env bash
#
# Boots the plugin in WordPress Playground (WASM PHP 8.4) and asserts it loads.
#
# Mounts the working copy as the plugin and runs blueprint.json, whose runPHP
# step throws unless the plugin is active and its classes load. The exit code
# is therefore the authoritative signal.
#
# Per docs/adr/0004 there is NO DDEV fallback here: a failure is a signal to
# raise the question with the maintainer, not to switch runtimes automatically.
#
# Usage:
#   bash playground-smoke.sh [--verbose]
#
# Exit codes:
#   0  the plugin booted
#   1  npx is missing or the Playground run failed

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
CLI_VERSION="3.1.36"
MOUNT_TARGET="/wordpress/wp-content/plugins/kntnt-unlink-unpublished"

VERBOSE=false
[[ "${1:-}" == "--verbose" ]] && VERBOSE=true

# Playground is driven through @wp-playground/cli, which needs Node's npx.
if ! command -v npx &>/dev/null; then
	echo "Error: npx (Node.js) is required to run the Playground e2e." >&2
	exit 1
fi

echo "Booting WordPress Playground (PHP 8.4) with the plugin mounted…"

# Run the blueprint headlessly; the CLI discards a passing step's output.
set +e
output="$(
	npx --yes "@wp-playground/cli@${CLI_VERSION}" run-blueprint \
		--php=8.4 \
		--wp=latest \
		--mount="${PLUGIN_ROOT}:${MOUNT_TARGET}" \
		--blueprint="${SCRIPT_DIR}/blueprint.json" 2>&1
)"
run_exit=$?
set -e

[[ "$VERBOSE" == true ]] && echo "$output"

if [[ $run_exit -ne 0 ]]; then
	echo "$output"
	echo "Playground run exited ${run_exit} – the plugin failed to boot." >&2
	exit 1
fi

echo "The plugin booted in WordPress Playground."
