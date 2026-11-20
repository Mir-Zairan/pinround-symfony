#!/usr/bin/env bash
# Sets up a local pinround for a person: the dev stack, the demo people, Node's trust in the local certificate,
# the browser extension's origin, and a check of Claude Code's pinround connection. Used by the pinround-setup
# skill (SKILL.md beside it). Never prints a token or the demo password.
#
# Usage: pinround-setup.sh <command> [argument]
#   check                 report what is ready and what is missing
#   stack                 start the stack if it is not running, make sure DEMO_PASSWORD is set, load the demo people
#   trust-ca              copy the local certificate authority to ~/.config/pinround/caddy-root.crt and test it
#   extension <id>        allow a Chrome extension id in the app and in storage, restart, and check both
#   mcp-command <folder>  print the lines that add pinround to Claude Code in that folder (the token is typed, never shown)
#   mcp-status <folder>   for every Claude Code profile here, say whether it has pinround for that folder and if its token works
set -euo pipefail

repo=$(cd "$(dirname "$0")/../../.." && pwd)
cd "$repo"

ca_file="$HOME/.config/pinround/caddy-root.crt"
container_ca=/data/caddy/pki/authorities/local/root.crt
extension_id_pattern='^[a-p]{32}$'

# The same env files, in the same order, as the Makefile's COMPOSE. Plain `docker compose` skips .env.local,
# so it would start services on the default ports and without the local CORS setting.
compose_files=(--env-file .env --env-file .env.dev)
[[ -f .env.local ]] && compose_files+=(--env-file .env.local)
compose() { docker compose "${compose_files[@]}" "$@"; }

ok() { printf '  ok       %s\n' "$1"; }
missing() { printf '  MISSING  %s\n           fix: %s\n' "$1" "$2"; }
fail() { printf 'pinround-setup: %s\n' "$1" >&2; exit 1; }

# The value Compose sees for a setting: the last of .env, .env.dev, .env.local that sets it.
compose_setting() {
    local key=$1 default=$2 value='' file line
    for file in .env .env.dev .env.local; do
        [[ -f $file ]] || continue
        line=$(grep -E "^${key}=" "$file" | tail -n 1 || true)
        [[ -n $line ]] && value=${line#*=}
    done
    printf '%s' "${value:-$default}"
}

base_url() {
    local port
    port=$(compose_setting HTTPS_PORT 443)
    if [[ $port == 443 ]]; then printf 'https://localhost'; else printf 'https://localhost:%s' "$port"; fi
}

minio_url() { printf 'http://localhost:%s' "$(compose_setting MINIO_API_PORT 9000)"; }

# Replaces every KEY= line of a git-ignored env file with one new line at the end.
set_env_line() {
    local file=$1 key=$2 value=$3
    git check-ignore -q "$file" || fail "$file is tracked by git; refusing to write a local setting into it."
    touch "$file"
    sed -i "/^${key}=/d" "$file"
    [[ -s $file && $(tail -c 1 "$file") != '' ]] && printf '\n' >> "$file"
    printf '%s=%s\n' "$key" "$value" >> "$file"
}

app_is_ready() { curl -sk -o /dev/null -w '%{http_code}' --max-time 5 "$(base_url)/health/ready" 2>/dev/null | grep -q '^200$'; }

wait_for_app() {
    local attempt
    for attempt in $(seq 1 60); do
        app_is_ready && return 0
        sleep 2
    done
    fail "the app did not answer $(base_url)/health/ready within 2 minutes; run: make logs"
}

demo_password_is_set() {
    local line
    line=$(grep -E '^DEMO_PASSWORD=' .env.dev.local 2>/dev/null | tail -n 1 || true)
    (( ${#line} - 14 >= 12 ))
}

# The extension origin Symfony really uses in dev. .env.dev is tracked and wins over .env.local, so the local
# value must live in .env.dev.local.
app_extension_origins() {
    compose exec -T app bin/console debug:dotenv EXTENSION_ORIGINS --no-debug 2>/dev/null \
        | awk '$1 == "EXTENSION_ORIGINS" { print $2 }'
}

preflight_allows() {
    local url=$1 method=$2 origin=$3
    curl -sk -o /dev/null -D - --max-time 5 -X OPTIONS "$url" -H "Origin: $origin" \
        -H "Access-Control-Request-Method: $method" -H 'Access-Control-Request-Headers: authorization' 2>/dev/null \
        | tr -d '\r' | grep -qix "access-control-allow-origin: $origin"
}

# Every Claude Code settings file a person may use here: the default ~/.claude.json, and the one of
# CLAUDE_CONFIG_DIR when this shell sets another. The script never guesses which one the person starts.
claude_config_files() {
    printf '%s\n' "$HOME/.claude.json"
    if [[ -n ${CLAUDE_CONFIG_DIR:-} && $CLAUDE_CONFIG_DIR/.claude.json != "$HOME/.claude.json" ]]; then
        printf '%s\n' "$CLAUDE_CONFIG_DIR/.claude.json"
    fi
}

cmd_check() {
    echo "pinround at $(base_url)"
    command -v docker > /dev/null && docker compose version > /dev/null 2>&1 \
        && ok 'Docker with Compose v2' || missing 'Docker with Compose v2' 'install Docker Engine and the compose plugin'
    command -v make > /dev/null && ok 'make' || missing 'make' 'install make'
    app_is_ready && ok 'the stack runs and the app is ready' || missing 'the app is not ready' "$0 stack"
    demo_password_is_set && ok 'DEMO_PASSWORD is set in .env.dev.local' || missing 'DEMO_PASSWORD (12+ characters) in .env.dev.local' "$0 stack"

    if [[ -f $ca_file ]] && app_is_ready && compose exec -T app cat "$container_ca" 2>/dev/null | cmp -s - "$ca_file"; then
        ok "the local certificate authority is copied to $ca_file"
    else
        missing 'a current copy of the local certificate authority' "$0 trust-ca"
    fi
    local startup_file
    startup_file=$(grep -lsE 'NODE_EXTRA_CA_CERTS=.*pinround/caddy-root\.crt' ~/.bashrc ~/.zshrc ~/.profile ~/.bash_profile | head -n 1 || true)
    if [[ ${NODE_EXTRA_CA_CERTS:-} == "$ca_file" ]]; then
        ok 'NODE_EXTRA_CA_CERTS points to it in this shell'
    elif [[ -n $startup_file ]]; then
        ok "NODE_EXTRA_CA_CERTS is set in $startup_file (Claude Code started before that change needs a new terminal)"
    else
        missing 'NODE_EXTRA_CA_CERTS in the shell that starts Claude Code' \
            "add export NODE_EXTRA_CA_CERTS=\"$ca_file\" to your shell startup file (~/.bashrc or ~/.zshrc), then start Claude Code from a new terminal"
    fi

    if app_is_ready; then
        local origins
        origins=$(app_extension_origins)
        [[ $origins == *chrome-extension://* ]] && ok "the app allows $origins" || missing 'an extension origin in the app' "$0 extension <id>"
    fi
    local cors
    cors=$(compose_setting MINIO_CORS_ALLOW_ORIGIN '')
    [[ $cors == *chrome-extension://* ]] && ok 'storage allows the extension origin' || missing 'the extension origin in MINIO_CORS_ALLOW_ORIGIN' "$0 extension <id>"
}

cmd_stack() {
    if ! demo_password_is_set; then
        set_env_line .env.dev.local DEMO_PASSWORD "$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)"
        echo 'Wrote a new DEMO_PASSWORD to .env.dev.local (see it with: grep DEMO_PASSWORD .env.dev.local).'
    fi
    if ! app_is_ready; then
        echo 'Starting the stack with make up (the first run builds images and takes about 5-6 minutes).'
        make up
    fi
    make seed
    echo "Ready: open $(base_url). Every demo person uses DEMO_PASSWORD."
}

cmd_trust_ca() {
    app_is_ready || fail "the app is not ready; run: $0 stack"
    mkdir -p "$(dirname "$ca_file")"
    compose exec -T app cat "$container_ca" > "$ca_file.new"
    openssl x509 -in "$ca_file.new" -noout > /dev/null 2>&1 || { rm -f "$ca_file.new"; fail 'the copied file is not a certificate'; }
    mv "$ca_file.new" "$ca_file"
    chmod 644 "$ca_file"
    echo "Copied: $ca_file ($(openssl x509 -in "$ca_file" -noout -subject | sed 's/^subject=//'))"
    command -v node > /dev/null || { echo 'Node is not installed here, so the Node check was skipped.'; return; }
    local probe="fetch(process.argv[1]).then(r => console.log('HTTP ' + r.status)).catch(e => console.log(e.cause?.code ?? e.message))"
    echo "Node without it: $(NODE_EXTRA_CA_CERTS='' node -e "$probe" "$(base_url)/health/live")"
    echo "Node with it:    $(NODE_EXTRA_CA_CERTS="$ca_file" node -e "$probe" "$(base_url)/health/live")"
}

cmd_extension() {
    local id=${1:-}
    [[ $id =~ $extension_id_pattern ]] || fail 'an extension id is 32 letters from a to p, as chrome://extensions shows it.'
    local origin="chrome-extension://$id"
    set_env_line .env.dev.local EXTENSION_ORIGINS "$origin"
    set_env_line .env.local MINIO_CORS_ALLOW_ORIGIN "$(base_url),$origin"
    compose_files=(--env-file .env --env-file .env.dev --env-file .env.local)
    echo 'Recreating storage with the new CORS rule, then restarting the app and the workers.'
    compose up -d --force-recreate --wait minio > /dev/null 2>&1 || fail 'storage did not start; run: make logs'
    compose up -d --force-recreate minio-init > /dev/null 2>&1 || fail 'minio-init did not start; run: make logs'
    compose restart app worker worker-heavy > /dev/null 2>&1 || fail 'the app did not restart; run: make logs'
    wait_for_app
    local attempt
    for attempt in $(seq 1 15); do
        preflight_allows "$(minio_url)/pinround/setup-check.png" PUT "$origin" && break
        sleep 2
    done
    preflight_allows "$(base_url)/api/v1/me/tickets" GET "$origin" && ok "the app accepts $origin" \
        || missing "the app still refuses $origin" 'run: docker compose exec app bin/console debug:dotenv EXTENSION_ORIGINS'
    preflight_allows "$(minio_url)/pinround/setup-check.png" PUT "$origin" && ok "storage accepts uploads from $origin" \
        || missing "storage still refuses $origin" 'run: docker compose logs minio-init'
}

cmd_mcp_command() {
    local folder=${1:-}
    [[ -d $folder ]] || fail "no such folder: ${folder:-<empty>}"
    # `read -rs` keeps the token off the screen, out of the shell history and out of the command line.
    printf '# Run in the terminal where you start Claude Code. If you start it with CLAUDE_CONFIG_DIR set, set it here the same way first.\n'
    printf 'cd %q\n' "$(cd "$folder" && pwd)"
    printf 'claude mcp remove pinround -s local 2>/dev/null\n'
    printf "printf 'pinround token: '; read -rs PINROUND_TOKEN; echo\n"
    printf 'claude mcp add --transport http pinround %s/mcp --header "Authorization: Bearer $PINROUND_TOKEN"\n' "$(base_url)"
    printf 'unset PINROUND_TOKEN\n'
}

# Says whether one settings file has pinround for the folder and whether its token works. Prints the scope and
# the URL only: the header goes to a private temp file that curl reads (-H @file), so the token is never in the
# output or on curl's command line. Returns 1 when the file has no pinround for the folder.
mcp_status_of() {
    local config=$1 folder=$2 token_file found status
    token_file=$(mktemp)
    # A RETURN trap outlives the function; clearing it inside keeps it from firing on a later return.
    trap 'rm -f "$token_file"; trap - RETURN' RETURN
    found=$(python3 -I - "$config" "$folder" "$token_file" <<'PY'
import json, os, sys
config, folder, token_file = sys.argv[1:4]
places = []
def look(scope, servers):
    entry = (servers or {}).get('pinround')
    if entry:
        places.append((scope, entry))
try:
    data = json.load(open(config))
    look('local (this folder)', (data.get('projects', {}).get(folder) or {}).get('mcpServers'))
    look('user (every folder)', data.get('mcpServers'))
except FileNotFoundError:
    pass
try:
    look('project (.mcp.json)', json.load(open(os.path.join(folder, '.mcp.json'))).get('mcpServers'))
except FileNotFoundError:
    pass
if not places:
    print('none')
    sys.exit(0)
scope, entry = places[0]
header = (entry.get('headers') or {}).get('Authorization', '')
with open(token_file, 'w') as f:
    f.write('Authorization: Bearer ' + header.removeprefix('Bearer ').strip() + '\n' if header else '')
print(f"{scope} {entry.get('url', '?')}")
PY
)
    echo "$config:"
    if [[ $found == none ]]; then
        echo '  -        no pinround here'
        return 1
    fi
    ok "pinround is set at scope ${found% *}, url ${found##* }"
    [[ -s $token_file ]] || { missing 'the entry has no Authorization header' "add it again: $0 mcp-command $folder"; return 0; }
    status=$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 --cacert "$ca_file" \
        -H "@$token_file" "$(base_url)/api/v1/me/tickets" 2>/dev/null || true)
    case $status in
        200) ok 'its token works (GET /api/v1/me/tickets answered 200)' ;;
        401) missing 'its token is wrong, revoked or expired (401)' "make a new token, then run the lines from: $0 mcp-command $folder" ;;
        403) missing 'its token lacks the read scope (403)' 'make a token with read ticked' ;;
        000) missing 'pinround did not answer over TLS' "$0 trust-ca" ;;
        *) missing "pinround answered $status" 'run: make logs' ;;
    esac
}

cmd_mcp_status() {
    local folder=${1:-} config found_any=no
    [[ -d $folder ]] || fail "no such folder: ${folder:-<empty>}"
    folder=$(cd "$folder" && pwd)
    while read -r config; do
        mcp_status_of "$config" "$folder" && found_any=yes
    done < <(claude_config_files)
    [[ $found_any == yes ]] || missing "no Claude Code profile has pinround for $folder" "run the lines from: $0 mcp-command $folder"
}

case ${1:-} in
    check) cmd_check ;;
    stack) cmd_stack ;;
    trust-ca) cmd_trust_ca ;;
    extension) cmd_extension "${2:-}" ;;
    mcp-command) cmd_mcp_command "${2:-}" ;;
    mcp-status) cmd_mcp_status "${2:-}" ;;
    *) awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print } /^set -euo/ { exit }' "$0"; exit 2 ;;
esac
