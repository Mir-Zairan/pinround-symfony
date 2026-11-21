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
#   mcp-command <folder> [sign-in]
#                         print the lines that add pinround to Claude Code in that folder: with a token (typed, never
#                         shown), or with sign-in, no token (Claude Code signs in on pinround's consent page, ADR-0050)
#   mcp-status <folder>   for every Claude Code profile here, say whether it has pinround for that folder and if its token
#                         works; an entry with no token signs in, which only /mcp in Claude Code can check
#   ai-key <provider>     ask for a huggingface or anthropic key (typed, never shown), test it, save it and restart
#                         (run it in your own terminal: it reads the key from the keyboard)
#   ai-status             say which cloud AI keys the app has and test them, and whether Ollama here is ready for pinround
#   demo-mode <on|off>    turn the dev-only switch that skips two-step login on or off (PINROUND_DEMO_SKIP_2FA), restart
#   demo                  a first look in about 5 minutes: ready-made images where they fit this machine, demo mode on,
#                         the stack, the demo people and their tickets, then the address and who to log in as
set -euo pipefail

repo=$(cd "$(dirname "$0")/../../.." && pwd)
cd "$repo"

ca_file="$HOME/.config/pinround/caddy-root.crt"
container_ca=/data/caddy/pki/authorities/local/root.crt
extension_id_pattern='^[a-p]{32}$'

# Ready-made dev images, pinned by digest, for `demo` (amd64 and arm64). Building them takes 7 to 8 minutes, mostly
# MinIO's Go build and the PHP extensions. They are pulled by digest from our own namespace and tagged with the local
# names compose.yaml uses, so compose itself still never pulls (its pull_policy: never stays). The app image runs as
# uid 1000, so a Linux user with another uid builds instead. Rebuild and re-pin both after a change to the Dockerfile,
# docker/frankenphp/ or docker/minio/ (docs/guides/demo.md says how).
ready_app_image=ghcr.io/mir-zairan/pinround-app@sha256:a313c7c0cac28c9e0dfe4db8fb1fb0b98620f1101c20302dad5e90eabfa92bed
ready_minio_image=ghcr.io/mir-zairan/pinround-minio@sha256:8bd9df1d51b51f9041449ed062bd53a951b4aa42694d9d708bd2dac25f0f608c

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

    if demo_mode_is_on; then
        printf '  -        demo mode is on: two-step login is skipped (%s demo-mode off to turn it off)\n' "$0"
    else
        printf '  -        optional: demo mode is off, so admin pages and tokens need two-step login (%s demo-mode on)\n' "$0"
    fi

    if [[ -n $(app_setting AI_HUGGINGFACE_TOKEN) || -n $(app_setting AI_ANTHROPIC_KEY) ]]; then
        ok 'a cloud AI key is set (test it with: ai-status)'
    else
        printf '  -        optional: no cloud AI key, so the AI buttons do nothing in "Company cloud AI" mode (%s ai-key huggingface, or ai-status for laptop AI)\n' "$0"
    fi
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
    # .env.dev.local replaces .env.dev's value, so keep its test extension origin or the extension e2e tests get 403.
    local tracked_origins
    tracked_origins=$(sed -n 's/^EXTENSION_ORIGINS=//p' .env.dev | tail -n 1)
    set_env_line .env.dev.local EXTENSION_ORIGINS "$origin${tracked_origins:+,$tracked_origins}"
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
    local folder=${1:-} mode=${2:-token}
    [[ -d $folder ]] || fail "no such folder: ${folder:-<empty>}"
    [[ $mode == token || $mode == sign-in ]] || fail "unknown way to connect: $mode (leave it out for a token, or say sign-in)"
    if [[ $mode == sign-in ]]; then
        printf '# Run in the terminal where you start Claude Code. If you start it with CLAUDE_CONFIG_DIR set, set it here the same way first.\n'
        printf 'cd %q\n' "$(cd "$folder" && pwd)"
        printf 'claude mcp remove pinround -s local 2>/dev/null\n'
        printf 'claude mcp add --transport http pinround %s/mcp\n' "$(base_url)"
        printf '# Then start Claude Code here, type /mcp, pick pinround and authenticate.\n'
        return 0
    fi
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
    [[ -s $token_file ]] || { ok 'no token: it signs in with OAuth; check with /mcp in Claude Code that pinround is connected'; return 0; }
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

# The value Symfony uses in dev for a setting: the last of .env, .env.local, .env.dev, .env.dev.local that sets it.
# Callers only test it for emptiness; never print it.
app_setting() {
    local key=$1 value='' file line
    for file in .env .env.local .env.dev .env.dev.local; do
        [[ -f $file ]] || continue
        line=$(grep -E "^${key}=" "$file" | tail -n 1 || true)
        [[ -n $line ]] && value=${line#*=}
    done
    printf '%s' "$value"
}

app_setting_file() {
    local key=$1 found='' file
    for file in .env .env.local .env.dev .env.dev.local; do
        [[ -f $file ]] && grep -qE "^${key}=.+" "$file" && found=$file
    done
    printf '%s' "$found"
}

ai_env_key() {
    case $1 in
        huggingface) printf 'AI_HUGGINGFACE_TOKEN' ;;
        anthropic) printf 'AI_ANTHROPIC_KEY' ;;
        *) fail 'the provider is huggingface or anthropic.' ;;
    esac
}

# Tests a key against its provider. The key goes to curl in a private header file (-H @file), never on a command line.
# Hugging Face: a 1-token chat completion with the model the app uses (meta-llama/Llama-3.1-8B-Instruct, the default
# in AiProviderName), so it also proves the token may call Inference Providers. Anthropic: the free model list.
# Prints the HTTP status.
ai_key_status() {
    local provider=$1 key=$2 header_file status
    header_file=$(mktemp)
    chmod 600 "$header_file"
    if [[ $provider == huggingface ]]; then
        printf 'Authorization: Bearer %s\n' "$key" > "$header_file"
        status=$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 -H "@$header_file" -H 'Content-Type: application/json' \
            -d '{"model":"meta-llama/Llama-3.1-8B-Instruct","messages":[{"role":"user","content":"Say OK."}],"max_tokens":1}' \
            https://router.huggingface.co/v1/chat/completions || true)
    else
        printf 'x-api-key: %s\nanthropic-version: 2023-06-01\n' "$key" > "$header_file"
        status=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 -H "@$header_file" https://api.anthropic.com/v1/models || true)
    fi
    rm -f "$header_file"
    printf '%s' "$status"
}

explain_key_status() {
    local provider=$1 status=$2
    case $status in
        200) ok "the $provider key works" ;;
        401) missing "the $provider key is wrong or revoked (401)" 'make a new key and run ai-key again' ;;
        402) missing "the $provider account has no credit left (402)" 'add credit, or use laptop AI (ai-status)' ;;
        403) missing "the $provider key may not call the model (403)" 'for Hugging Face, make a token with "Make calls to Inference Providers" ticked' ;;
        000) missing "$provider did not answer" 'check the internet connection' ;;
        *) missing "$provider answered $status" 'try again later' ;;
    esac
}

cmd_ai_key() {
    local provider=${1:-} env_key key status
    env_key=$(ai_env_key "$provider")
    [[ -t 0 ]] || fail "run this in your own terminal: it asks for the key and never shows it. $0 ai-key $provider"
    if [[ $provider == huggingface ]]; then
        echo 'Make a token at https://huggingface.co/settings/tokens (type "Fine-grained", tick "Make calls to Inference Providers").'
    else
        echo 'Make a key at https://console.anthropic.com/settings/keys.'
    fi
    read -rs -p "Paste the $provider key (it is not shown) and press Enter: " key
    echo
    [[ -n $key ]] || fail 'no key typed; nothing changed.'
    if [[ $provider == huggingface && $key != hf_* ]] || [[ $provider == anthropic && $key != sk-ant-* ]]; then
        fail "that does not look like a $provider key; nothing changed."
    fi
    echo "Testing the key with $provider (one tiny request, no ticket text)."
    status=$(ai_key_status "$provider" "$key")
    explain_key_status "$provider" "$status"
    [[ $status == 200 ]] || fail 'the key was not saved.'
    set_env_line .env.dev.local "$env_key" "$key"
    key=''
    chmod 600 .env.dev.local
    echo "Saved as $env_key in .env.dev.local. Restarting the app and the workers."
    compose restart app worker worker-heavy > /dev/null 2>&1 || fail 'the app did not restart; run: make logs'
    wait_for_app
    ok 'the app runs with the key'
    if [[ $provider == anthropic ]]; then
        echo 'Next: log in as Sara, open Admin > AI and choose Anthropic as the provider. Hugging Face is the default.'
    fi
}

cmd_ai_status() {
    local provider env_key file key status
    echo 'Cloud AI (Admin > AI: "Company cloud AI"):'
    for provider in huggingface anthropic; do
        env_key=$(ai_env_key "$provider")
        file=$(app_setting_file "$env_key")
        if [[ -z $file ]]; then
            printf '  -        no %s key (%s ai-key %s)\n' "$provider" "$0" "$provider"
            continue
        fi
        ok "$env_key is set in $file"
        key=$(app_setting "$env_key")
        status=$(ai_key_status "$provider" "$key")
        key=''
        explain_key_status "$provider" "$status"
    done

    echo 'Laptop AI (Admin > AI: "Each person'"'"'s laptop (Ollama)" or "Laptop first"):'
    local origin headers tags model
    origin=$(base_url)
    headers=$(mktemp)
    tags=$(curl -s --max-time 3 -D "$headers" -H "Origin: $origin" http://localhost:11434/api/tags 2>/dev/null || true)
    if [[ -z $tags ]]; then
        missing 'Ollama does not answer on http://localhost:11434' 'install it from https://ollama.com (Linux: curl -fsSL https://ollama.com/install.sh | sh), then start it'
        rm -f "$headers"
        return
    fi
    ok 'Ollama answers on http://localhost:11434'
    if tr -d '\r' < "$headers" | grep -qix "access-control-allow-origin: \($origin\|\*\)"; then
        ok "Ollama lets $origin talk to it"
    else
        missing "Ollama refuses $origin" "set OLLAMA_ORIGINS=$origin for Ollama and restart it (Linux service: sudo systemctl edit ollama, add [Service] and Environment=\"OLLAMA_ORIGINS=$origin\", then sudo systemctl restart ollama)"
    fi
    rm -f "$headers"
    # The browser picks a model the same way (local_ai_controller.js): qwen3*, else phi4* or gemma*, else the first.
    model=$(printf '%s' "$tags" | python3 -I -c '
import json, sys
names = [m.get("name", "") for m in json.load(sys.stdin).get("models", [])]
pick = next((n for n in names if n.startswith("qwen3")), None) \
    or next((n for n in names if n.startswith(("phi4", "gemma"))), None) \
    or (names[0] if names else "")
print(pick)' 2>/dev/null || true)
    if [[ -n $model ]]; then
        ok "pinround will use the model $model"
    else
        missing 'Ollama has no model' 'ollama pull qwen3:4b (about 2.5 GB)'
    fi
}

# Whether the switch is on as the dev app reads it: true, on, yes or a whole number other than 0, as Symfony's bool
# env processor reads them (it also counts a decimal other than 0, which nobody writes here).
demo_mode_is_on() {
    local value
    value=$(app_setting PINROUND_DEMO_SKIP_2FA)
    [[ ${value,,} =~ ^(true|on|yes)$ ]] || [[ $value =~ ^[+-]?[0-9]+$ && $((10#${value#[+-]})) -ne 0 ]]
}

# ADR-0049: the app reads the switch only with APP_ENV=dev and refuses to start with it on anywhere else, so it goes
# to .env.dev.local, which only dev reads.
cmd_demo_mode() {
    local choice=${1:-}
    case $choice in
        on) set_env_line .env.dev.local PINROUND_DEMO_SKIP_2FA 1 ;;
        off) set_env_line .env.dev.local PINROUND_DEMO_SKIP_2FA 0 ;;
        *) fail 'say on or off: demo-mode on skips two-step login on this laptop, demo-mode off asks for it again.' ;;
    esac
    echo "Demo mode $choice in .env.dev.local. Restarting the app and the workers."
    compose restart app worker worker-heavy > /dev/null 2>&1 || fail 'the app did not restart; run: make logs'
    wait_for_app
    if demo_mode_is_on; then
        ok 'demo mode is on: admin pages and tokens need no two-step login, and every page says so'
    else
        ok 'demo mode is off: admin pages and tokens need two-step login'
    fi
}

# The ready app image runs as uid 1000. Docker Desktop on macOS maps file owners on its mounts; on Linux the clone's
# owner must be that uid, or the app cannot write vendor/ and var/.
ready_images_fit() {
    [[ $(uname -s) == Darwin ]] || [[ $(id -u) == 1000 ]]
}

use_ready_images() {
    echo 'Pulling the ready-made app and storage images (about 1 GB on the first run).'
    docker pull -q "$ready_app_image" > /dev/null || return 1
    docker pull -q "$ready_minio_image" > /dev/null || return 1
    docker tag "$ready_app_image" pinround-app:dev
    docker tag "$ready_minio_image" pinround-minio:dev
}

cmd_demo() {
    if ! demo_password_is_set; then
        set_env_line .env.dev.local DEMO_PASSWORD "$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)"
    fi
    # Before the first start, so no restart is needed for it (ADR-0049: dev only, every page says so).
    set_env_line .env.dev.local PINROUND_DEMO_SKIP_2FA 1
    if ready_images_fit && use_ready_images; then
        ok 'ready-made images in place'
    else
        echo 'Building the images here instead (the first build takes 7 to 8 minutes).'
        compose build
    fi
    echo 'Starting pinround.'
    compose up -d --wait app > /dev/null || fail 'the app did not start; run: make logs'
    make --no-print-directory db-roles migrate > /dev/null || fail 'the database setup failed; run: make db-roles migrate'
    compose up -d --wait > /dev/null || fail 'a service did not start; run: make logs'
    echo 'Loading the demo people and their tickets.'
    make --no-print-directory seed > /dev/null || fail 'the demo data did not load; run: make seed'
    echo
    echo "Ready: open $(base_url) (your browser warns about the local certificate once: choose to continue)."
    echo 'Log in as mia@acme-foods.example (tester), omar@acme-foods.example (developer) or lena@acme-foods.example (lead).'
    echo 'They share one password; see it with: grep DEMO_PASSWORD .env.dev.local'
    echo 'Demo mode is on: no two-step login. Stop with: make down.'
}

case ${1:-} in
    check) cmd_check ;;
    demo) cmd_demo ;;
    stack) cmd_stack ;;
    trust-ca) cmd_trust_ca ;;
    extension) cmd_extension "${2:-}" ;;
    mcp-command) cmd_mcp_command "${2:-}" "${3:-}" ;;
    mcp-status) cmd_mcp_status "${2:-}" ;;
    ai-key) cmd_ai_key "${2:-}" ;;
    ai-status) cmd_ai_status ;;
    demo-mode) cmd_demo_mode "${2:-}" ;;
    *) awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print } /^set -euo/ { exit }' "$0"; exit 2 ;;
esac
