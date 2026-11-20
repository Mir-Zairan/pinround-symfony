#!/bin/sh
# Fails when a tracked file under the browser extension builds code from text: `eval(`, `new Function` or a plain
# `Function(` call. The scenario player interprets data only (P13 design notes, ADR-0046), so a scenario or an AI
# answer can never become script on the page under test.
# The folder to search is the first argument (default: the repo's extension/); it must sit inside a git work tree,
# since git grep searches only tracked files. Each hit is a GitHub `::error file=…,line=…::` line, paths relative to
# that work tree's top. Exit 0: no hit. Exit 1: hits. Above 1: the folder could not be searched.
# tests/shell/extension-code-from-text-check-test.sh proves what it refuses and what it leaves alone.
# Only POSIX tools and git are used, so CI needs nothing beyond a checkout.
set -eu

folder=${1:-"$(dirname "$0")/../../extension"}

if ! tracked=$(git -C "$folder" ls-files -- .); then
    echo "::error::extension-code-from-text-check: could not list the tracked files under $folder."
    exit 2
fi

if [ -z "$tracked" ]; then
    echo "::error::extension-code-from-text-check: no tracked file under $folder, so nothing was checked."
    exit 2
fi

# The `\(` after eval is what leaves `evaluate(` alone; the word boundary before it skips names that end in eval,
# such as `retrieval(` or `medieval(`. `\bFunction` skips `isFunction(` and lower-case `function`.
grep_errors=$(mktemp)
trap 'rm -f "$grep_errors"' EXIT
set +e
hits=$(git -C "$folder" grep --full-name -nE \
    -e '\beval[[:space:]]*\(' \
    -e '\bnew[[:space:]]+Function\b' \
    -e '\bFunction[[:space:]]*\(' \
    -- . 2>"$grep_errors")
status=$?
set -e

# git grep still exits 1 ("no match") when it cannot read a tracked file, so any message on stderr fails the check.
if [ "$status" -gt 1 ] || [ -s "$grep_errors" ]; then
    echo "::error::extension-code-from-text-check: git grep could not search $folder (exit $status)."
    LC_ALL=C tr '\001-\010\013-\037\177' '?' < "$grep_errors"
    if [ "$status" -gt 1 ]; then
        exit "$status"
    fi
    exit 2
fi

if [ "$status" -eq 1 ]; then
    echo "extension-code-from-text-check: no eval(, new Function or Function( under $folder."
    exit 0
fi

# Control characters in a hit (a CR from a CRLF file, an ESC) are shown as `?`, so a hit cannot rewrite
# the terminal or hide part of the log.
printf '%s\n' "$hits" | LC_ALL=C tr '\001-\010\013-\037\177' '?' | while IFS=: read -r file line _; do
    echo "::error file=$file,line=$line::The browser extension must not build code from text (eval(, new Function or Function(): the scenario player interprets data only."
done
printf '%s\n' "$hits" | LC_ALL=C tr '\001-\010\013-\037\177' '?'
exit 1
