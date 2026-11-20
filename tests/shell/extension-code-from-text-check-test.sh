#!/bin/sh
# Proves tests/shell/extension-code-from-text-check.sh in a throwaway git repo: it fails on a file with `eval (`,
# `new Function` and `Function(` (CRLF line ends, no final newline) and names each line, passes on names that only
# look alike (`evaluate(`, `medieval(`, `retrieval(`, `isFunction(`, `'function'`), and fails closed on a folder
# with no tracked file, a missing folder and a tracked file git grep cannot read.
set -eu

check="$(cd "$(dirname "$0")" && pwd)/extension-code-from-text-check.sh"

repo=$(mktemp -d)
trap 'chmod -R u+rwx "$repo"; rm -rf "$repo"' EXIT

git -C "$repo" init -q
mkdir "$repo/forbidden" "$repo/allowed" "$repo/untracked"
mkdir "$repo/unreadable"
printf "eval (x)\r\nnew Function('a')\r\nconst build = new Function\r\nFunction('return 1')()" > "$repo/forbidden/player.js"
printf "%s\n" "evaluate(x)" "medieval(x)" "retrieval(x)" "isFunction(x)" "if (typeof f === 'function') {}" > "$repo/allowed/player.js"
printf '%s\n' "eval(x)" > "$repo/untracked/player.js"
printf '%s\n' "eval(x)" > "$repo/unreadable/player.js"
git -C "$repo" add forbidden allowed unreadable
chmod 000 "$repo/unreadable/player.js"

fail() {
    echo "extension-code-from-text-check-test: $1" >&2
    exit 1
}

# Runs the check on a folder and sets $output and $status.
run_check() {
    set +e
    output=$(sh "$check" "$1" 2>&1)
    status=$?
    set -e
}

run_check "$repo/forbidden"
[ "$status" -eq 1 ] || fail "expected exit 1 on the forbidden file, got $status: $output"
message="The browser extension must not build code from text (eval(, new Function or Function(): the scenario player interprets data only."
expected_errors="::error file=forbidden/player.js,line=1::$message
::error file=forbidden/player.js,line=2::$message
::error file=forbidden/player.js,line=3::$message
::error file=forbidden/player.js,line=4::$message"
actual_errors=$(printf '%s\n' "$output" | grep '^::error ' || true)
[ "$actual_errors" = "$expected_errors" ] || fail "expected one error per forbidden line, got:
$output"
printf '%s\n' "$output" | grep -qx "forbidden/player.js:1:eval (x)?" || fail "the hit list does not show line 1 with its CR as ?: $output"

run_check "$repo/allowed"
[ "$status" -eq 0 ] || fail "expected exit 0 on the allowed file, got $status: $output"
case $output in
    *"::error"*) fail "the allowed file raised an error: $output" ;;
esac

run_check "$repo/untracked"
[ "$status" -eq 2 ] || fail "expected exit 2 on a folder with no tracked file, got $status: $output"
case $output in
    *"no tracked file"*) ;;
    *) fail "a folder with no tracked file was not named as such: $output" ;;
esac

run_check "$repo/missing"
[ "$status" -gt 1 ] || fail "expected an exit above 1 on a missing folder, got $status: $output"

# root reads a file whatever its mode, so this case can only be shown as another user.
if [ "$(id -u)" -ne 0 ]; then
    run_check "$repo/unreadable"
    [ "$status" -gt 1 ] || fail "expected an exit above 1 on an unreadable tracked file, got $status: $output"
else
    echo "extension-code-from-text-check-test: skipped the unreadable-file case, running as root"
fi

echo "extension-code-from-text-check-test: ok"
