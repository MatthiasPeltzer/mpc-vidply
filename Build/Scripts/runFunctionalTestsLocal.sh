#!/usr/bin/env bash
#
# Runs the functional tests with the test instances on container-local storage.
#
# Inside DDEV on Windows, .Build is shared from the host over 9p, where every
# test instance (symlinks, SQLite database, caches) is slow to set up. This
# script points TYPO3_PATH_ROOT at web roots in /tmp that link back to
# .Build/Public, so only the instances move. Test classes run in parallel,
# each with a web root of its own so they cannot share files or databases.
#
#   ddev exec packages/mpc-vidply/Build/Scripts/runFunctionalTestsLocal.sh [jobs] [phpunit filter]
#
set -euo pipefail

cd "$(dirname "$0")/../.."
JOBS="${1:-8}"
FILTER="${2:-}"
PUBLIC="$(pwd)/.Build/Public"
ROOTS="${TMPDIR:-/tmp}/mpc-vidply-functional-roots"
LOGS="$(mktemp -d)"

export typo3DatabaseDriver="${typo3DatabaseDriver:-pdo_sqlite}"
export PUBLIC ROOTS LOGS FILTER

run_class() {
    local class="$1" name root
    name="$(echo "$class" | tr '/' '_')"
    root="$ROOTS/$name"

    mkdir -p "$root/typo3temp/var/tests" "$root/typo3temp/var/transient"
    for entry in index.php _assets _assets_install typo3conf; do
        if [ -e "$PUBLIC/$entry" ]; then
            ln -sfn "$PUBLIC/$entry" "$root/$entry"
        fi
    done

    local args=(-c Build/FunctionalTests.xml --colors=never)
    if [ -n "$FILTER" ]; then
        args+=(--filter "$FILTER")
    fi
    if TYPO3_PATH_ROOT="$root" TYPO3_PATH_WEB="$root" .Build/bin/phpunit "${args[@]}" "$class" > "$LOGS/$name.log" 2>&1; then
        echo "ok    $class  $(grep -E '^(OK|No tests)' "$LOGS/$name.log" | tail -n 1)"
    else
        echo "FAIL  $class  $(grep -E '^Tests:' "$LOGS/$name.log" | tail -n 1)"
    fi
}
export -f run_class

find Tests/Functional -name '*Test.php' | sort \
    | xargs -P "$JOBS" -I{} bash -c 'run_class "$@"' _ {} \
    | tee "$LOGS/summary"

if grep -q '^FAIL' "$LOGS/summary"; then
    for log in "$LOGS"/*.log; do
        if ! grep -qE '^OK|No tests executed' "$log"; then
            echo
            echo "=== $log"
            cat "$log"
        fi
    done
    exit 1
fi
