#!/usr/bin/env bash
set -euo pipefail

CF_API_BASE="https://api.cloudflare.com/client/v4"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${ROOT_DIR}/.env"
ENV_TEMPLATE="${ROOT_DIR}/.env.example"
ENV_LIB="${ROOT_DIR}/scripts/lib/env.sh"
TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
OUTPUT_DIR_DEFAULT="${PWD}/ollama-cloudflare-setup-${TIMESTAMP}"
WIZARD_ENV_KEYS=(
    CLOUDFLARE_ACCOUNT_ID
    CLOUDFLARE_ZONE_ID
    CLOUDFLARE_API_TOKEN
    CLOUDFLARE_TUNNEL_NAME
    CLOUDFLARE_TUNNEL_UUID
    CLOUDFLARE_TUNNEL_DOMAIN
    OLLAMA_PUBLIC_HOSTNAME
    CLOUDFLARE_ACCESS_APP_NAME
    CLOUDFLARE_ACCESS_APP_ID
    CLOUDFLARE_SERVICE_TOKEN_NAME
    CLOUDFLARE_SERVICE_TOKEN_ID
    CLOUDFLARE_SERVICE_TOKEN_DURATION
    CLOUDFLARE_ACCESS_POLICY_ID
    CLOUDFLARE_ACCESS_HEADER_NAME
    CLOUDFLARE_ACCESS_HEADER_VALUE
    CF_ACCESS_CLIENT_ID
    CF_ACCESS_CLIENT_SECRET
    OLLAMA_LOCAL_URL
    OLLAMA_HOST_TARGET
    OLLAMA_ORIGIN_HOST_HEADER
)

if [[ ! -f "${ENV_LIB}" ]]; then
    echo "Missing env helper library: ${ENV_LIB}" >&2
    exit 1
fi

# shellcheck source=./lib/env.sh
source "${ENV_LIB}"

print_permissions() {
    cat <<'EOF'
Cloudflare API token permissions for the script:

Minimum permissions when you provide ACCOUNT_ID and ZONE_ID manually:
Account permissions:
  - Cloudflare Tunnel Edit
  - Access: Apps and Policies Edit
  - Access: Service Tokens Edit

Zone permissions:
  - DNS Edit

Optional extra permission only when you want the script to auto-detect ACCOUNT_ID and ZONE_ID from your domain:
  - Zone Read

Why these are needed:
  - Cloudflare Tunnel Edit: create tunnel, push tunnel config, fetch tunnel token
  - Access: Apps and Policies Edit: create/update the Access app and its Service Auth policy
  - Access: Service Tokens Edit: create the Access service token
  - DNS Edit: create/update the Ollama hostname DNS record
  - Zone Read: auto-detect the zone ID and account ID from your domain name when you do not enter them manually
EOF
}

print_help() {
    cat <<'EOF'
Usage:
  ./scripts/ollama-cloudflare-wizard.sh
  ./scripts/ollama-cloudflare-wizard.sh --help
  ./scripts/ollama-cloudflare-wizard.sh --permissions

What it does:
  - reads saved defaults from .env.example and .env when available
  - prompts for CLOUDFLARE_API_TOKEN, ACCOUNT_ID, ZONE_ID, and OLLAMA_PUBLIC_HOSTNAME
  - verifies the local Ollama endpoint
  - installs cloudflared and jq on Debian/Ubuntu when needed
  - creates or reuses the Cloudflare Tunnel
  - routes the desired hostname to that tunnel
  - updates /etc/cloudflared/config.yml with the Ollama ingress rule when local config mode is available
  - pushes the tunnel ingress config for managed-token mode compatibility
  - falls back to the Cloudflare DNS API if the local route command cannot be used
  - creates or reuses the Cloudflare Access app
  - rotates or creates the Access service token and updates the Service Auth policy
  - enables single-header mode
  - forces the Ollama origin Host header so the public endpoint actually works
  - saves the Cloudflare/Ollama defaults it used back into .env
  - tests the final public Ollama endpoint
  - prints the exact WordPress values to paste into AI Content Forge

Use --permissions to print the required Cloudflare API token scopes without starting the interactive flow.
EOF
}

is_wsl() {
    grep -qiE '(microsoft|wsl)' /proc/sys/kernel/osrelease 2>/dev/null
}

print_heading() {
    printf '\n%s\n' "$1"
    printf '%*s\n' "${#1}" '' | tr ' ' '='
}

prompt_with_default() {
    local var_name="$1"
    local label="$2"
    local default_value="$3"
    local input=""

    read -r -p "${label} [${default_value}]: " input
    printf -v "${var_name}" '%s' "${input:-${default_value}}"
}

prompt_secret() {
    local var_name="$1"
    local label="$2"
    local input=""

    read -r -s -p "${label}: " input
    printf '\n'
    printf -v "${var_name}" '%s' "${input}"
}

prompt_secret_with_default() {
    local var_name="$1"
    local label="$2"
    local current_default="$3"
    local input=""

    if [[ -n "${current_default}" ]]; then
        read -r -s -p "${label} [saved in .env, press Enter to keep]: " input
    else
        read -r -s -p "${label}: " input
    fi

    printf '\n'
    printf -v "${var_name}" '%s' "${input:-${current_default}}"
}

yes_no_prompt() {
    local label="$1"
    local default_answer="$2"
    local input=""

    read -r -p "${label} [${default_answer}]: " input
    input="${input:-${default_answer}}"

    case "${input}" in
        y|Y|yes|YES) return 0 ;;
        *) return 1 ;;
    esac
}

default_if_empty() {
    local var_name="$1"
    local default_value="$2"

    if [[ -z "${!var_name:-}" ]]; then
        printf -v "${var_name}" '%s' "${default_value}"
    fi
}

slugify_value() {
    local value="$1"

    printf '%s' "${value}" \
        | tr '[:upper:]' '[:lower:]' \
        | sed -E 's/[^a-z0-9]+/-/g; s/^-+//; s/-+$//; s/-+/-/g'
}

default_tunnel_name() {
    local host_name=""
    host_name="$(hostname -s 2>/dev/null || hostname 2>/dev/null || printf 'local-machine')"
    host_name="$(slugify_value "${host_name}")"

    if [[ -z "${host_name}" ]]; then
        host_name="local-machine"
    fi

    printf 'acf-%s' "${host_name}"
}

extract_host_from_url() {
    local url="$1"

    printf '%s' "${url}" | sed -E 's#^[a-zA-Z]+://([^/]+).*$#\1#'
}

require_non_empty() {
    local var_name="$1"
    local label="$2"
    local value="${!var_name:-}"

    if [[ -z "${value}" ]]; then
        echo "${label} is required." >&2
        exit 1
    fi
}

require_command() {
    local command_name="$1"

    if ! command -v "${command_name}" >/dev/null 2>&1; then
        echo "Missing required command: ${command_name}" >&2
        exit 1
    fi
}

require_root_if_needed() {
    if [[ "${RUN_MODE}" == "service" ]] && ! command -v sudo >/dev/null 2>&1; then
        echo "sudo is required to install the cloudflared service." >&2
        exit 1
    fi
}

save_json() {
    local target_file="$1"
    local json_payload="$2"

    printf '%s\n' "${json_payload}" > "${target_file}"
}

load_wizard_env_defaults() {
    env_load_file "${ENV_TEMPLATE}" "${WIZARD_ENV_KEYS[@]}"
    env_load_file "${ENV_FILE}" "${WIZARD_ENV_KEYS[@]}"

    if [[ -z "${OLLAMA_LOCAL_URL:-}" && -n "${OLLAMA_HOST_TARGET:-}" ]]; then
        OLLAMA_LOCAL_URL="http://${OLLAMA_HOST_TARGET}"
    fi

    default_if_empty "CLOUDFLARE_ACCESS_APP_NAME" "Ollama API"
    default_if_empty "CLOUDFLARE_SERVICE_TOKEN_NAME" "ollama-api"
    default_if_empty "CLOUDFLARE_SERVICE_TOKEN_DURATION" "8760h"
    default_if_empty "CLOUDFLARE_ACCESS_HEADER_NAME" "Authorization"
    default_if_empty "OLLAMA_LOCAL_URL" "http://localhost:11434"
    default_if_empty "CLOUDFLARE_TUNNEL_NAME" "$(default_tunnel_name)"

    if [[ -z "${OLLAMA_HOST_TARGET:-}" ]]; then
        OLLAMA_HOST_TARGET="$(extract_host_from_url "${OLLAMA_LOCAL_URL}")"
    fi

    if [[ -z "${OLLAMA_ORIGIN_HOST_HEADER:-}" ]]; then
        OLLAMA_ORIGIN_HOST_HEADER="${OLLAMA_HOST_TARGET}"
    fi

    if [[ -z "${OLLAMA_PUBLIC_HOSTNAME:-}" && -n "${CLOUDFLARE_TUNNEL_DOMAIN:-}" ]]; then
        OLLAMA_PUBLIC_HOSTNAME="ollama.${CLOUDFLARE_TUNNEL_DOMAIN}"
    fi
}

persist_wizard_env() {
    env_ensure_file "${ENV_FILE}" "${ENV_TEMPLATE}"
    env_set "${ENV_FILE}" "CLOUDFLARE_API_TOKEN" "${CLOUDFLARE_API_TOKEN:-}"
    env_set "${ENV_FILE}" "CLOUDFLARE_TUNNEL_DOMAIN" "${CLOUDFLARE_TUNNEL_DOMAIN:-}"
    env_set "${ENV_FILE}" "OLLAMA_PUBLIC_HOSTNAME" "${OLLAMA_PUBLIC_HOSTNAME:-}"
    env_set "${ENV_FILE}" "OLLAMA_LOCAL_URL" "${OLLAMA_LOCAL_URL:-}"
    env_set "${ENV_FILE}" "OLLAMA_HOST_TARGET" "${OLLAMA_HOST_TARGET:-}"
    env_set "${ENV_FILE}" "OLLAMA_ORIGIN_HOST_HEADER" "${OLLAMA_ORIGIN_HOST_HEADER:-}"
    env_set "${ENV_FILE}" "CLOUDFLARE_TUNNEL_NAME" "${CLOUDFLARE_TUNNEL_NAME:-}"
    env_set "${ENV_FILE}" "CLOUDFLARE_TUNNEL_UUID" "${CLOUDFLARE_TUNNEL_UUID:-}"
    env_set "${ENV_FILE}" "CLOUDFLARE_ACCESS_APP_NAME" "${CLOUDFLARE_ACCESS_APP_NAME:-}"
    env_set "${ENV_FILE}" "CLOUDFLARE_ACCESS_APP_ID" "${CLOUDFLARE_ACCESS_APP_ID:-}"
    env_set "${ENV_FILE}" "CLOUDFLARE_SERVICE_TOKEN_NAME" "${CLOUDFLARE_SERVICE_TOKEN_NAME:-}"
    env_set "${ENV_FILE}" "CLOUDFLARE_SERVICE_TOKEN_ID" "${CLOUDFLARE_SERVICE_TOKEN_ID:-}"
    env_set "${ENV_FILE}" "CLOUDFLARE_SERVICE_TOKEN_DURATION" "${CLOUDFLARE_SERVICE_TOKEN_DURATION:-}"
    env_set "${ENV_FILE}" "CLOUDFLARE_ACCESS_POLICY_ID" "${CLOUDFLARE_ACCESS_POLICY_ID:-}"
    env_set "${ENV_FILE}" "CLOUDFLARE_ACCESS_HEADER_NAME" "${CLOUDFLARE_ACCESS_HEADER_NAME:-}"
    env_set "${ENV_FILE}" "CLOUDFLARE_ACCESS_HEADER_VALUE" "${CLOUDFLARE_ACCESS_HEADER_VALUE:-}"
    env_set "${ENV_FILE}" "CF_ACCESS_CLIENT_ID" "${CF_ACCESS_CLIENT_ID:-}"
    env_set "${ENV_FILE}" "CF_ACCESS_CLIENT_SECRET" "${CF_ACCESS_CLIENT_SECRET:-}"
    env_set "${ENV_FILE}" "CLOUDFLARE_ACCOUNT_ID" "${ACCOUNT_ID:-}"
    env_set "${ENV_FILE}" "CLOUDFLARE_ZONE_ID" "${ZONE_ID:-}"
}

cf_api() {
    local method="$1"
    local path="$2"
    local data="${3:-}"
    local response_file
    response_file="$(mktemp)"

    if [[ -n "${data}" ]]; then
        curl -fsS "${CF_API_BASE}${path}" \
            --request "${method}" \
            --header "Authorization: Bearer ${CLOUDFLARE_API_TOKEN}" \
            --header "Content-Type: application/json" \
            --data "${data}" \
            > "${response_file}"
    else
        curl -fsS "${CF_API_BASE}${path}" \
            --request "${method}" \
            --header "Authorization: Bearer ${CLOUDFLARE_API_TOKEN}" \
            > "${response_file}"
    fi

    if ! jq -e '.success == true' "${response_file}" >/dev/null 2>&1; then
        echo "Cloudflare API call failed: ${method} ${path}" >&2
        cat "${response_file}" >&2
        rm -f "${response_file}"
        exit 1
    fi

    cat "${response_file}"
    rm -f "${response_file}"
}

cf_api_get_zones() {
    local domain_name="$1"
    local response_file
    response_file="$(mktemp)"

    curl -fsS -G "${CF_API_BASE}/zones" \
        --header "Authorization: Bearer ${CLOUDFLARE_API_TOKEN}" \
        --data-urlencode "name=${domain_name}" \
        --data-urlencode "match=all" \
        > "${response_file}"

    if ! jq -e '.success == true' "${response_file}" >/dev/null 2>&1; then
        echo "Cloudflare zone lookup failed." >&2
        cat "${response_file}" >&2
        rm -f "${response_file}"
        exit 1
    fi

    cat "${response_file}"
    rm -f "${response_file}"
}

cf_api_get_dns_record() {
    local zone_id="$1"
    local hostname="$2"
    local response_file
    response_file="$(mktemp)"

    curl -fsS -G "${CF_API_BASE}/zones/${zone_id}/dns_records" \
        --header "Authorization: Bearer ${CLOUDFLARE_API_TOKEN}" \
        --data-urlencode "type=CNAME" \
        --data-urlencode "name=${hostname}" \
        > "${response_file}"

    if ! jq -e '.success == true' "${response_file}" >/dev/null 2>&1; then
        echo "Cloudflare DNS lookup failed." >&2
        cat "${response_file}" >&2
        rm -f "${response_file}"
        exit 1
    fi

    cat "${response_file}"
    rm -f "${response_file}"
}

cf_api_list_tunnels() {
    local account_id="$1"
    cf_api "GET" "/accounts/${account_id}/cfd_tunnel"
}

cf_api_get_tunnel_config() {
    local account_id="$1"
    local tunnel_id="$2"
    cf_api "GET" "/accounts/${account_id}/cfd_tunnel/${tunnel_id}/configurations"
}

cf_api_list_access_apps() {
    local account_id="$1"
    cf_api "GET" "/accounts/${account_id}/access/apps"
}

cf_api_list_service_tokens() {
    local account_id="$1"
    cf_api "GET" "/accounts/${account_id}/access/service_tokens"
}

cf_api_rotate_service_token() {
    local account_id="$1"
    local service_token_id="$2"
    cf_api "POST" "/accounts/${account_id}/access/service_tokens/${service_token_id}/rotate"
}

cf_api_try_get_zone() {
    local zone_id="$1"
    local response_file
    response_file="$(mktemp)"

    if ! curl -fsS "${CF_API_BASE}/zones/${zone_id}" \
        --header "Authorization: Bearer ${CLOUDFLARE_API_TOKEN}" \
        > "${response_file}"; then
        rm -f "${response_file}"
        return 1
    fi

    if ! jq -e '.success == true' "${response_file}" >/dev/null 2>&1; then
        rm -f "${response_file}"
        return 1
    fi

    cat "${response_file}"
    rm -f "${response_file}"
}

install_cloudflared_if_needed() {
    if command -v cloudflared >/dev/null 2>&1 && command -v jq >/dev/null 2>&1; then
        return
    fi

    if ! command -v apt-get >/dev/null 2>&1; then
        echo "Automatic installation currently supports Debian/Ubuntu systems with apt-get." >&2
        echo "Install cloudflared from https://pkg.cloudflare.com/ and jq from your package manager, then re-run this script." >&2
        exit 1
    fi

    require_command "sudo"
    sudo mkdir -p --mode=0755 /usr/share/keyrings
    curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg | sudo tee /usr/share/keyrings/cloudflare-main.gpg >/dev/null
    echo 'deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared any main' | sudo tee /etc/apt/sources.list.d/cloudflared.list >/dev/null
    sudo apt-get update
    sudo apt-get install -y cloudflared jq
}

install_or_update_cloudflared_service() {
    local tunnel_token="$1"
    local install_command=(sudo cloudflared service install "${tunnel_token}")

    if [[ "${RUN_MODE}" == "manual" ]]; then
        MANUAL_RUN_COMMAND="cloudflared tunnel run --token ${tunnel_token}"
        return
    fi

    if [[ "${RUN_MODE}" == "config_service" ]]; then
        sudo systemctl restart cloudflared
        return
    fi

    if ! command -v systemctl >/dev/null 2>&1; then
        echo "systemctl is not available, so the script will use manual cloudflared run mode instead."
        MANUAL_RUN_COMMAND="cloudflared tunnel run --token ${tunnel_token}"
        return
    fi

    if systemctl list-unit-files cloudflared.service >/dev/null 2>&1; then
        sudo cloudflared service uninstall || true
    fi

    "${install_command[@]}"
    sudo systemctl restart cloudflared
}

detect_run_mode() {
    if command -v systemctl >/dev/null 2>&1 && command -v sudo >/dev/null 2>&1; then
        if sudo systemctl cat cloudflared 2>/dev/null | grep -q -- '--config /etc/cloudflared/config.yml'; then
            RUN_MODE="config_service"
        else
            RUN_MODE="service"
        fi
    else
        RUN_MODE="manual"
    fi
}

find_local_credentials_file() {
    local tunnel_id="$1"
    local config_file="/etc/cloudflared/config.yml"
    local candidate=""

    if command -v sudo >/dev/null 2>&1 && sudo test -f "${config_file}"; then
        candidate="$(sudo sed -n 's/^credentials-file:[[:space:]]*//p' "${config_file}" | head -n 1)"
        if [[ -n "${candidate}" ]] && sudo test -f "${candidate}"; then
            printf '%s' "${candidate}"
            return 0
        fi
    fi

    for candidate in \
        "/etc/cloudflared/${tunnel_id}.json" \
        "/root/.cloudflared/${tunnel_id}.json" \
        "${HOME}/.cloudflared/${tunnel_id}.json"
    do
        if [[ "${candidate}" == /root/* || "${candidate}" == /etc/* ]]; then
            if command -v sudo >/dev/null 2>&1 && sudo test -f "${candidate}"; then
                printf '%s' "${candidate}"
                return 0
            fi
        elif [[ -f "${candidate}" ]]; then
            printf '%s' "${candidate}"
            return 0
        fi
    done

    return 1
}

update_local_cloudflared_config() {
    local tunnel_id="$1"
    local hostname="$2"
    local service_url="$3"
    local host_header="$4"
    local config_file="/etc/cloudflared/config.yml"
    local credentials_file=""
    local tmp_in=""
    local tmp_out=""

    if ! command -v sudo >/dev/null 2>&1; then
        return 1
    fi

    if ! credentials_file="$(find_local_credentials_file "${tunnel_id}")"; then
        return 1
    fi

    tmp_in="$(mktemp)"
    tmp_out="$(mktemp)"

    if sudo test -f "${config_file}"; then
        sudo cat "${config_file}" > "${tmp_in}"
    else
        cat > "${tmp_in}" <<EOF
tunnel: ${tunnel_id}
credentials-file: ${credentials_file}

ingress:
  - service: http_status:404
EOF
    fi

    awk \
        -v tunnel_id="${tunnel_id}" \
        -v credentials_file="${credentials_file}" \
        -v hostname="${hostname}" \
        -v service_url="${service_url}" \
        -v host_header="${host_header}" '
        function print_rule() {
            print "  - hostname: " hostname
            print "    service: " service_url
            print "    originRequest:"
            print "      httpHostHeader: " host_header
        }
        BEGIN {
            in_ingress = 0
            skipping = 0
            inserted = 0
            saw_tunnel = 0
            saw_credentials = 0
            saw_fallback = 0
        }
        {
            if ($0 ~ /^tunnel:[[:space:]]*/) {
                print "tunnel: " tunnel_id
                saw_tunnel = 1
                next
            }

            if ($0 ~ /^credentials-file:[[:space:]]*/) {
                print "credentials-file: " credentials_file
                saw_credentials = 1
                next
            }

            if ($0 ~ /^ingress:[[:space:]]*$/) {
                in_ingress = 1
                print
                next
            }

            if (in_ingress) {
                if ($0 ~ /^  - hostname: /) {
                    if (skipping) {
                        skipping = 0
                    }
                    if ($0 == "  - hostname: " hostname) {
                        skipping = 1
                        next
                    }
                }

                if (skipping) {
                    if ($0 ~ /^  - /) {
                        skipping = 0
                    } else {
                        next
                    }
                }

                if ($0 ~ /^  - service: http_status:404/) {
                    if (!inserted) {
                        print_rule()
                        inserted = 1
                    }
                    saw_fallback = 1
                    print
                    next
                }

                print
                next
            }

            print
        }
        END {
            if (!saw_tunnel) {
                print "tunnel: " tunnel_id
            }
            if (!saw_credentials) {
                print "credentials-file: " credentials_file
            }
            if (!in_ingress) {
                print ""
                print "ingress:"
                print_rule()
                print "  - service: http_status:404"
            } else if (!inserted) {
                print_rule()
            }
            if (!saw_fallback) {
                print "  - service: http_status:404"
            }
        }
    ' "${tmp_in}" > "${tmp_out}"

    sudo cp "${tmp_out}" "${config_file}"
    rm -f "${tmp_in}" "${tmp_out}"
    return 0
}

route_dns_with_cloudflared() {
    local tunnel_name="$1"
    local hostname="$2"

    if ! command -v cloudflared >/dev/null 2>&1; then
        return 1
    fi

    if command -v sudo >/dev/null 2>&1; then
        sudo cloudflared tunnel route dns "${tunnel_name}" "${hostname}"
    else
        cloudflared tunnel route dns "${tunnel_name}" "${hostname}"
    fi
}

wait_for_public_endpoint() {
    local auth_header="$1"
    local url="$2"
    local attempts=0
    local max_attempts=30

    until curl -fsS -H "${CLOUDFLARE_ACCESS_HEADER_NAME}: ${auth_header}" "${url}" >/dev/null 2>&1; do
        (( attempts += 1 ))
        if (( attempts >= max_attempts )); then
            return 1
        fi
        sleep 2
    done

    return 0
}

print_heading "AI Content Forge Ollama + Cloudflare Wizard"
echo "This script can create and verify the Cloudflare Tunnel, DNS record, Access app,"
echo "service token, and service-auth policy needed for a remote Ollama endpoint."
echo "It prompts for your Cloudflare API token, account ID, zone ID, and desired public Ollama hostname."

case "${1:-}" in
    --help|-h)
        print_help
        exit 0
        ;;
    --permissions)
        print_permissions
        exit 0
        ;;
    "")
        ;;
    *)
        echo "Unknown argument: ${1}" >&2
        print_help >&2
        exit 1
        ;;
esac

print_permissions

load_wizard_env_defaults

prompt_secret_with_default "CLOUDFLARE_API_TOKEN" "Paste your Cloudflare API token" "${CLOUDFLARE_API_TOKEN:-}"
prompt_with_default "ACCOUNT_ID" "Cloudflare ACCOUNT_ID" "${CLOUDFLARE_ACCOUNT_ID:-${ACCOUNT_ID:-}}"
prompt_with_default "ZONE_ID" "Cloudflare ZONE_ID" "${CLOUDFLARE_ZONE_ID:-${ZONE_ID:-}}"
prompt_with_default "OLLAMA_PUBLIC_HOSTNAME" "Desired public Ollama hostname" "${OLLAMA_PUBLIC_HOSTNAME:-}"
OUTPUT_DIR="${OUTPUT_DIR_DEFAULT}"

require_non_empty "CLOUDFLARE_API_TOKEN" "Cloudflare API token"
require_non_empty "ACCOUNT_ID" "Cloudflare ACCOUNT_ID"
require_non_empty "ZONE_ID" "Cloudflare ZONE_ID"
require_non_empty "OLLAMA_PUBLIC_HOSTNAME" "Desired public Ollama hostname"
require_non_empty "OLLAMA_LOCAL_URL" "Local Ollama URL"
require_non_empty "CLOUDFLARE_TUNNEL_NAME" "Tunnel name"
require_non_empty "CLOUDFLARE_ACCESS_APP_NAME" "Access app name"
require_non_empty "CLOUDFLARE_SERVICE_TOKEN_NAME" "Service token name"
require_non_empty "CLOUDFLARE_SERVICE_TOKEN_DURATION" "Service token duration"
require_non_empty "CLOUDFLARE_ACCESS_HEADER_NAME" "Access header name"

if [[ -z "${CLOUDFLARE_TUNNEL_DOMAIN:-}" ]]; then
    if ZONE_LOOKUP_JSON="$(cf_api_try_get_zone "${ZONE_ID}")"; then
        CLOUDFLARE_TUNNEL_DOMAIN="$(jq -r '.result.name // empty' <<< "${ZONE_LOOKUP_JSON}")"
    fi
fi

require_non_empty "CLOUDFLARE_TUNNEL_DOMAIN" "Cloudflare main domain (set CLOUDFLARE_TUNNEL_DOMAIN in .env if your API token cannot read zone details)"

if [[ "${OLLAMA_PUBLIC_HOSTNAME}" != *".${CLOUDFLARE_TUNNEL_DOMAIN}" && "${OLLAMA_PUBLIC_HOSTNAME}" != "${CLOUDFLARE_TUNNEL_DOMAIN}" ]]; then
    echo "The hostname ${OLLAMA_PUBLIC_HOSTNAME} is not inside the Cloudflare zone ${CLOUDFLARE_TUNNEL_DOMAIN}." >&2
    exit 1
fi

OLLAMA_HOSTNAME="${OLLAMA_PUBLIC_HOSTNAME}"
PUBLIC_OLLAMA_URL="https://${OLLAMA_HOSTNAME}"
mkdir -p "${OUTPUT_DIR}"

persist_wizard_env
detect_run_mode

if is_wsl; then
    echo
    echo "WSL detected."
    echo "Recommended: run both Ollama and cloudflared inside this same Ubuntu/WSL environment."
fi

install_cloudflared_if_needed
require_command "curl"
require_command "jq"
require_command "cloudflared"
require_root_if_needed

print_heading "Checking local Ollama"
if ! curl -fsS "${OLLAMA_LOCAL_URL}/api/tags" > "${OUTPUT_DIR}/local-ollama-tags.json"; then
    echo "The local Ollama check failed at ${OLLAMA_LOCAL_URL}/api/tags." >&2
    echo "Fix Ollama first, then re-run this script." >&2
    exit 1
fi
echo "Local Ollama is responding."

print_heading "Using manually provided account and zone IDs"

CLOUDFLARE_ACCOUNT_ID="${ACCOUNT_ID}"
CLOUDFLARE_ZONE_ID="${ZONE_ID}"
persist_wizard_env

echo "Zone ID: ${ZONE_ID}"
echo "Account ID: ${ACCOUNT_ID}"

print_heading "Creating or reusing tunnel"
TUNNELS_JSON="$(cf_api_list_tunnels "${ACCOUNT_ID}")"
save_json "${OUTPUT_DIR}/tunnels.json" "${TUNNELS_JSON}"

TUNNEL_ID="$(jq -r --arg name "${CLOUDFLARE_TUNNEL_NAME}" '.result[] | select(.name == $name) | .id' <<< "${TUNNELS_JSON}" | head -n 1)"
TUNNEL_TOKEN=""

if [[ -n "${TUNNEL_ID}" ]]; then
    echo "Reusing existing tunnel: ${CLOUDFLARE_TUNNEL_NAME} (${TUNNEL_ID})"
    TUNNEL_TOKEN_RESPONSE="$(cf_api "GET" "/accounts/${ACCOUNT_ID}/cfd_tunnel/${TUNNEL_ID}/token")"
    save_json "${OUTPUT_DIR}/tunnel-token.json" "${TUNNEL_TOKEN_RESPONSE}"
    TUNNEL_TOKEN="$(jq -r '.result // empty' <<< "${TUNNEL_TOKEN_RESPONSE}")"
else
    CREATE_TUNNEL_PAYLOAD="$(jq -nc --arg name "${CLOUDFLARE_TUNNEL_NAME}" '{"name": $name, "config_src": "cloudflare"}')"
    CREATE_TUNNEL_RESPONSE="$(cf_api "POST" "/accounts/${ACCOUNT_ID}/cfd_tunnel" "${CREATE_TUNNEL_PAYLOAD}")"
    save_json "${OUTPUT_DIR}/create-tunnel.json" "${CREATE_TUNNEL_RESPONSE}"
    TUNNEL_ID="$(jq -r '.result.id' <<< "${CREATE_TUNNEL_RESPONSE}")"
    TUNNEL_TOKEN="$(jq -r '.result.token // empty' <<< "${CREATE_TUNNEL_RESPONSE}")"

    if [[ -z "${TUNNEL_TOKEN}" ]]; then
        TUNNEL_TOKEN_RESPONSE="$(cf_api "GET" "/accounts/${ACCOUNT_ID}/cfd_tunnel/${TUNNEL_ID}/token")"
        save_json "${OUTPUT_DIR}/tunnel-token.json" "${TUNNEL_TOKEN_RESPONSE}"
        TUNNEL_TOKEN="$(jq -r '.result // empty' <<< "${TUNNEL_TOKEN_RESPONSE}")"
    fi

    echo "Created tunnel: ${CLOUDFLARE_TUNNEL_NAME} (${TUNNEL_ID})"
fi

if [[ -z "${TUNNEL_TOKEN}" ]]; then
    echo "Could not determine the tunnel token." >&2
    exit 1
fi

CLOUDFLARE_TUNNEL_UUID="${TUNNEL_ID}"
persist_wizard_env

print_heading "Pushing tunnel ingress config"
EXISTING_TUNNEL_CONFIG_RESPONSE="$(cf_api_get_tunnel_config "${ACCOUNT_ID}" "${TUNNEL_ID}")"
save_json "${OUTPUT_DIR}/existing-tunnel-config.json" "${EXISTING_TUNNEL_CONFIG_RESPONSE}"
TUNNEL_CONFIG_PAYLOAD="$(jq -c \
    --arg hostname "${OLLAMA_HOSTNAME}" \
    --arg service "${OLLAMA_LOCAL_URL}" \
    --arg host_header "${OLLAMA_ORIGIN_HOST_HEADER}" \
    '
    .result.config = (.result.config // {}) |
    .result.config.ingress = (
        ((.result.config.ingress // [])
            | map(select((.hostname // "") != $hostname and (.service // "") != "http_status:404")))
        + [{
            "hostname": $hostname,
            "service": $service,
            "originRequest": {
                "httpHostHeader": $host_header
            }
        }]
        + [{"service": "http_status:404"}]
    ) |
    {config: .result.config}
    ' <<< "${EXISTING_TUNNEL_CONFIG_RESPONSE}"
)"
TUNNEL_CONFIG_RESPONSE="$(cf_api "PUT" "/accounts/${ACCOUNT_ID}/cfd_tunnel/${TUNNEL_ID}/configurations" "${TUNNEL_CONFIG_PAYLOAD}")"
save_json "${OUTPUT_DIR}/tunnel-config-response.json" "${TUNNEL_CONFIG_RESPONSE}"
echo "Tunnel config uploaded."

LOCAL_CONFIG_STATUS="skipped"
print_heading "Updating local /etc/cloudflared/config.yml"
if update_local_cloudflared_config "${TUNNEL_ID}" "${OLLAMA_HOSTNAME}" "${OLLAMA_LOCAL_URL}" "${OLLAMA_ORIGIN_HOST_HEADER}"; then
    sudo cat /etc/cloudflared/config.yml > "${OUTPUT_DIR}/cloudflared-config.yml"
    LOCAL_CONFIG_STATUS="updated"
    echo "Local cloudflared config updated."
elif [[ "${RUN_MODE}" == "config_service" ]]; then
    LOCAL_CONFIG_STATUS="failed"
    RUN_MODE="service"
    echo "Could not update /etc/cloudflared/config.yml; falling back to token-managed service mode." >&2
else
    LOCAL_CONFIG_STATUS="failed"
    echo "Could not update /etc/cloudflared/config.yml on this machine." >&2
fi

print_heading "Creating or updating DNS route"
DNS_ROUTE_STATUS="cloudflared"
if route_dns_with_cloudflared "${CLOUDFLARE_TUNNEL_NAME}" "${OLLAMA_HOSTNAME}" > "${OUTPUT_DIR}/cloudflared-route-dns.txt" 2>&1; then
    echo "cloudflared tunnel route dns completed for ${OLLAMA_HOSTNAME}."
else
    DNS_ROUTE_STATUS="api-fallback"
    DNS_LOOKUP_JSON="$(cf_api_get_dns_record "${ZONE_ID}" "${OLLAMA_HOSTNAME}")"
    save_json "${OUTPUT_DIR}/dns-lookup.json" "${DNS_LOOKUP_JSON}"

    EXPECTED_CNAME="${TUNNEL_ID}.cfargotunnel.com"
    DNS_RECORD_ID="$(jq -r '.result[0].id // empty' <<< "${DNS_LOOKUP_JSON}")"

    DNS_PAYLOAD="$(jq -nc \
        --arg name "${OLLAMA_HOSTNAME}" \
        --arg content "${EXPECTED_CNAME}" \
        '{
            "type": "CNAME",
            "proxied": true,
            "name": $name,
            "content": $content
        }'
    )"

    if [[ -n "${DNS_RECORD_ID}" ]]; then
        DNS_RESPONSE="$(cf_api "PUT" "/zones/${ZONE_ID}/dns_records/${DNS_RECORD_ID}" "${DNS_PAYLOAD}")"
    else
        DNS_RESPONSE="$(cf_api "POST" "/zones/${ZONE_ID}/dns_records" "${DNS_PAYLOAD}")"
    fi
    save_json "${OUTPUT_DIR}/dns-response.json" "${DNS_RESPONSE}"
    echo "Fell back to the Cloudflare DNS API for ${OLLAMA_HOSTNAME}."
fi

print_heading "Installing or updating local cloudflared"
install_or_update_cloudflared_service "${TUNNEL_TOKEN}"
if [[ -n "${MANUAL_RUN_COMMAND:-}" ]]; then
    echo "Manual cloudflared command prepared."
    printf '%s\n' "${MANUAL_RUN_COMMAND}" > "${OUTPUT_DIR}/manual-cloudflared-run-command.txt"
else
    sudo systemctl status cloudflared --no-pager > "${OUTPUT_DIR}/cloudflared-service-status.txt" 2>&1 || true
fi

print_heading "Creating or reusing Access app"
ACCESS_APPS_JSON="$(cf_api_list_access_apps "${ACCOUNT_ID}")"
save_json "${OUTPUT_DIR}/access-apps.json" "${ACCESS_APPS_JSON}"

ACCESS_APP_ID="${CLOUDFLARE_ACCESS_APP_ID:-}"
if [[ -z "${ACCESS_APP_ID}" ]]; then
    ACCESS_APP_ID="$(jq -r --arg domain "${OLLAMA_HOSTNAME}" '.result[] | select(.domain == $domain) | .id' <<< "${ACCESS_APPS_JSON}" | head -n 1)"
fi

ACCESS_APP_RESPONSE=""

if [[ -z "${ACCESS_APP_ID}" ]]; then
    CREATE_ACCESS_APP_PAYLOAD="$(jq -nc \
        --arg name "${CLOUDFLARE_ACCESS_APP_NAME}" \
        --arg domain "${OLLAMA_HOSTNAME}" \
        --arg header "${CLOUDFLARE_ACCESS_HEADER_NAME}" \
        '{
            "name": $name,
            "domain": $domain,
            "type": "self_hosted",
            "app_launcher_visible": false,
            "auto_redirect_to_identity": false,
            "session_duration": "24h",
            "read_service_tokens_from_header": $header
        }'
    )"
    CREATE_ACCESS_APP_RESPONSE="$(cf_api "POST" "/accounts/${ACCOUNT_ID}/access/apps" "${CREATE_ACCESS_APP_PAYLOAD}")"
    save_json "${OUTPUT_DIR}/create-access-app.json" "${CREATE_ACCESS_APP_RESPONSE}"
    ACCESS_APP_ID="$(jq -r '.result.id' <<< "${CREATE_ACCESS_APP_RESPONSE}")"
    ACCESS_APP_RESPONSE="${CREATE_ACCESS_APP_RESPONSE}"
    echo "Created Access app: ${CLOUDFLARE_ACCESS_APP_NAME} (${ACCESS_APP_ID})"
else
    echo "Reusing existing Access app for ${OLLAMA_HOSTNAME}: ${ACCESS_APP_ID}"
    EXISTING_ACCESS_APP_RESPONSE="$(cf_api "GET" "/accounts/${ACCOUNT_ID}/access/apps/${ACCESS_APP_ID}")"
    save_json "${OUTPUT_DIR}/existing-access-app.json" "${EXISTING_ACCESS_APP_RESPONSE}"
    UPDATE_ACCESS_APP_PAYLOAD="$(jq -c \
        --arg header "${CLOUDFLARE_ACCESS_HEADER_NAME}" \
        '.result | .read_service_tokens_from_header = $header' \
        <<< "${EXISTING_ACCESS_APP_RESPONSE}"
    )"
    UPDATE_ACCESS_APP_RESPONSE="$(cf_api "PUT" "/accounts/${ACCOUNT_ID}/access/apps/${ACCESS_APP_ID}" "${UPDATE_ACCESS_APP_PAYLOAD}")"
    save_json "${OUTPUT_DIR}/update-access-app.json" "${UPDATE_ACCESS_APP_RESPONSE}"
    ACCESS_APP_RESPONSE="${UPDATE_ACCESS_APP_RESPONSE}"
fi

CLOUDFLARE_ACCESS_APP_ID="${ACCESS_APP_ID}"
persist_wizard_env

NEXT_POLICY_PRECEDENCE="$(jq -r '(.result.policies // []) | map(.precedence // 0) | max // 0 | . + 1' <<< "${ACCESS_APP_RESPONSE}")"

print_heading "Creating or rotating Access service token"
SERVICE_TOKENS_JSON="$(cf_api_list_service_tokens "${ACCOUNT_ID}")"
save_json "${OUTPUT_DIR}/service-tokens.json" "${SERVICE_TOKENS_JSON}"

SERVICE_TOKEN_ID="${CLOUDFLARE_SERVICE_TOKEN_ID:-}"
if [[ -z "${SERVICE_TOKEN_ID}" ]]; then
    SERVICE_TOKEN_ID="$(jq -r --arg name "${CLOUDFLARE_SERVICE_TOKEN_NAME}" '.result[] | select(.name == $name) | .id' <<< "${SERVICE_TOKENS_JSON}" | head -n 1)"
fi

if [[ -n "${SERVICE_TOKEN_ID}" ]]; then
    echo "Rotating existing service token: ${CLOUDFLARE_SERVICE_TOKEN_NAME} (${SERVICE_TOKEN_ID})"
    SERVICE_TOKEN_RESPONSE="$(cf_api_rotate_service_token "${ACCOUNT_ID}" "${SERVICE_TOKEN_ID}")"
    save_json "${OUTPUT_DIR}/rotate-service-token.json" "${SERVICE_TOKEN_RESPONSE}"
else
    CREATE_SERVICE_TOKEN_PAYLOAD="$(jq -nc \
        --arg name "${CLOUDFLARE_SERVICE_TOKEN_NAME}" \
        --arg duration "${CLOUDFLARE_SERVICE_TOKEN_DURATION}" \
        '{
            "name": $name,
            "duration": $duration
        }'
    )"
    SERVICE_TOKEN_RESPONSE="$(cf_api "POST" "/accounts/${ACCOUNT_ID}/access/service_tokens" "${CREATE_SERVICE_TOKEN_PAYLOAD}")"
    save_json "${OUTPUT_DIR}/create-service-token.json" "${SERVICE_TOKEN_RESPONSE}"
    SERVICE_TOKEN_ID="$(jq -r '.result.id' <<< "${SERVICE_TOKEN_RESPONSE}")"
fi

CLIENT_ID="$(jq -r '.result.client_id' <<< "${SERVICE_TOKEN_RESPONSE}")"
CLIENT_SECRET="$(jq -r '.result.client_secret' <<< "${SERVICE_TOKEN_RESPONSE}")"
CF_ACCESS_CLIENT_ID="${CLIENT_ID}"
CF_ACCESS_CLIENT_SECRET="${CLIENT_SECRET}"
CLOUDFLARE_SERVICE_TOKEN_ID="${SERVICE_TOKEN_ID}"

print_heading "Creating or updating Service Auth policy"
POLICY_ID="${CLOUDFLARE_ACCESS_POLICY_ID:-}"
if [[ -z "${POLICY_ID}" ]]; then
    POLICY_ID="$(jq -r '.result.policies[]? | select(.name == "AI Content Forge Service Auth") | .id' <<< "${ACCESS_APP_RESPONSE}" | head -n 1)"
fi

POLICY_PRECEDENCE="$(jq -r --arg policy_id "${POLICY_ID}" '
    if $policy_id == "" then
        ((.result.policies // []) | map(.precedence // 0) | max // 0) + 1
    else
        ((.result.policies // []) | map(select(.id == $policy_id) | .precedence // 0) | first) // 1
    end
' <<< "${ACCESS_APP_RESPONSE}")"

POLICY_PAYLOAD="$(jq -nc \
    --arg token_id "${SERVICE_TOKEN_ID}" \
    --argjson precedence "${POLICY_PRECEDENCE}" \
    '{
        "name": "AI Content Forge Service Auth",
        "decision": "non_identity",
        "include": [
            {
                "service_token": {
                    "token_id": $token_id
                }
            }
        ],
        "exclude": [],
        "require": [],
        "precedence": $precedence
    }'
)"

if [[ -n "${POLICY_ID}" ]]; then
    POLICY_RESPONSE="$(cf_api "PUT" "/accounts/${ACCOUNT_ID}/access/apps/${ACCESS_APP_ID}/policies/${POLICY_ID}" "${POLICY_PAYLOAD}")"
    save_json "${OUTPUT_DIR}/update-policy.json" "${POLICY_RESPONSE}"
else
    POLICY_RESPONSE="$(cf_api "POST" "/accounts/${ACCOUNT_ID}/access/apps/${ACCESS_APP_ID}/policies" "${POLICY_PAYLOAD}")"
    save_json "${OUTPUT_DIR}/create-policy.json" "${POLICY_RESPONSE}"
    POLICY_ID="$(jq -r '.result.id' <<< "${POLICY_RESPONSE}")"
fi

CLOUDFLARE_ACCESS_POLICY_ID="${POLICY_ID}"

AUTH_HEADER_VALUE="$(jq -cn \
    --arg client_id "${CLIENT_ID}" \
    --arg client_secret "${CLIENT_SECRET}" \
    '{"cf-access-client-id": $client_id, "cf-access-client-secret": $client_secret}' \
)"
CLOUDFLARE_ACCESS_HEADER_VALUE="${AUTH_HEADER_VALUE}"
persist_wizard_env

print_heading "Testing the public Ollama endpoint"
if wait_for_public_endpoint "${AUTH_HEADER_VALUE}" "${PUBLIC_OLLAMA_URL}/api/tags"; then
    curl -fsS -H "${CLOUDFLARE_ACCESS_HEADER_NAME}: ${AUTH_HEADER_VALUE}" "${PUBLIC_OLLAMA_URL}/api/tags" > "${OUTPUT_DIR}/public-ollama-tags.json"
    TEST_STATUS="success"
    echo "Public test succeeded."
else
    TEST_STATUS="failed"
    echo "The public test did not succeed within the timeout window." >&2
    echo "Check the files in ${OUTPUT_DIR} and confirm cloudflared is running locally." >&2
fi

cat <<EOF

Setup summary
=============

Cloudflare account ID: ${ACCOUNT_ID}
Cloudflare zone ID: ${ZONE_ID}
Tunnel ID: ${TUNNEL_ID}
Access app ID: ${ACCESS_APP_ID}
Service token ID: ${SERVICE_TOKEN_ID}
Public URL: ${PUBLIC_OLLAMA_URL}
Public test status: ${TEST_STATUS}
Run mode: ${RUN_MODE}
Local config status: ${LOCAL_CONFIG_STATUS}
DNS route mode: ${DNS_ROUTE_STATUS}

Paste these into AI Content Forge -> Ollama:

Base URL: ${PUBLIC_OLLAMA_URL}
Access Header Name: ${CLOUDFLARE_ACCESS_HEADER_NAME}
Access Header Value: ${AUTH_HEADER_VALUE}

Raw wp-admin paste value:
  ${AUTH_HEADER_VALUE}

Note:
  The .env file stores CLOUDFLARE_ACCESS_HEADER_VALUE in escaped form for Docker Compose compatibility.
  Copy the raw value shown above for the wp-admin "Header Value" field.

Saved files:
  ${OUTPUT_DIR}/create-tunnel.json
  ${OUTPUT_DIR}/tunnel-config-response.json
  ${OUTPUT_DIR}/dns-response.json
  ${OUTPUT_DIR}/access-apps.json
  ${OUTPUT_DIR}/service-tokens.json
  ${OUTPUT_DIR}/cloudflared-route-dns.txt
  ${OUTPUT_DIR}/cloudflared-config.yml

Saved to .env:
  CLOUDFLARE_TUNNEL_UUID
  CLOUDFLARE_ACCESS_APP_ID
  CLOUDFLARE_SERVICE_TOKEN_ID
  CLOUDFLARE_ACCESS_POLICY_ID
  OLLAMA_PUBLIC_HOSTNAME
  CF_ACCESS_CLIENT_ID
  CF_ACCESS_CLIENT_SECRET
  CLOUDFLARE_ACCESS_HEADER_NAME
  CLOUDFLARE_ACCESS_HEADER_VALUE

EOF

if [[ -n "${MANUAL_RUN_COMMAND:-}" ]]; then
    cat <<EOF
cloudflared was not installed as a system service.
Run this command in a dedicated terminal and keep it open:

${MANUAL_RUN_COMMAND}

EOF
fi
