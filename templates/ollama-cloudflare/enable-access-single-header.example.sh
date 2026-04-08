#!/usr/bin/env bash
set -euo pipefail

: "${CLOUDFLARE_API_TOKEN:?Set CLOUDFLARE_API_TOKEN first}"
: "${ACCOUNT_ID:?Set ACCOUNT_ID first}"
: "${APP_ID:?Set APP_ID first}"
: "${HEADER_NAME:?Set HEADER_NAME first}"

curl "https://api.cloudflare.com/client/v4/accounts/${ACCOUNT_ID}/access/apps/${APP_ID}" \
  --request GET \
  --header "Authorization: Bearer ${CLOUDFLARE_API_TOKEN}" \
  > access-app-response.json

jq ".result | .read_service_tokens_from_header = \"${HEADER_NAME}\"" \
  access-app-response.json \
  > access-app-update.json

curl "https://api.cloudflare.com/client/v4/accounts/${ACCOUNT_ID}/access/apps/${APP_ID}" \
  --request PUT \
  --header "Authorization: Bearer ${CLOUDFLARE_API_TOKEN}" \
  --header "Content-Type: application/json" \
  --data @access-app-update.json
