#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_SLUG="ai-content-forge"
CONTAINER_PLUGIN_REPO="/workspace/ai-content-forge"

cd "${ROOT_DIR}"

if ! command -v docker >/dev/null 2>&1; then
	echo "Docker is required but was not found in PATH." >&2
	exit 1
fi

ZIP_FILE="${1:-}"

if [[ -z "${ZIP_FILE}" ]]; then
	ZIP_FILE="$(find "${ROOT_DIR}" -maxdepth 1 -type f -name "${PLUGIN_SLUG}-v*.zip" -printf '%f\n' | sort -V | tail -n 1)"
fi

if [[ -z "${ZIP_FILE}" ]]; then
	echo "No ${PLUGIN_SLUG}-v*.zip archive found in ${ROOT_DIR}." >&2
	echo "Build a release first with ./build-release.sh." >&2
	exit 1
fi

echo "Installing ${ZIP_FILE} into the local Docker WordPress instance..."
docker compose run --rm wpcli wp plugin install "${CONTAINER_PLUGIN_REPO}/${ZIP_FILE}" --force --activate
