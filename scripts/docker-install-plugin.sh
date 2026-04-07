#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="ai-content-forge"
CONTAINER_PLUGIN_REPO="/workspace/ai-content-forge"
WP="docker compose run --rm --no-deps wpcli wp"

cd "${ROOT_DIR}"

if ! command -v docker >/dev/null 2>&1; then
	echo "Docker is required but was not found in PATH." >&2
	exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
	echo "Docker Compose v2 ('docker compose') is required." >&2
	exit 1
fi

ZIP_FILE="${1:-}"

semver_gte() {
	local left="$1"
	local right="$2"
	local left_parts=()
	local right_parts=()
	local max_parts=0
	local i=0
	local left_value=0
	local right_value=0

	IFS=. read -r -a left_parts <<< "${left}"
	IFS=. read -r -a right_parts <<< "${right}"
	max_parts="${#left_parts[@]}"

	if (( ${#right_parts[@]} > max_parts )); then
		max_parts="${#right_parts[@]}"
	fi

	for (( i=0; i<max_parts; i++ )); do
		left_value="${left_parts[i]:-0}"
		right_value="${right_parts[i]:-0}"

		(( 10#${left_value} > 10#${right_value} )) && return 0
		(( 10#${left_value} < 10#${right_value} )) && return 1
	done

	return 0
}

resolve_latest_plugin_zip() {
	local candidate_path=""
	local candidate_file=""
	local best_file=""
	local candidate_version=""
	local best_version=""

	shopt -s nullglob
	for candidate_path in "${ROOT_DIR}/${PLUGIN_SLUG}-v"*.zip; do
		candidate_file="$(basename "${candidate_path}")"
		candidate_version="${candidate_file#${PLUGIN_SLUG}-v}"
		candidate_version="${candidate_version%.zip}"

		if [[ -z "${best_file}" ]] || semver_gte "${candidate_version}" "${best_version}"; then
			best_file="${candidate_file}"
			best_version="${candidate_version}"
		fi
	done
	shopt -u nullglob

	printf '%s' "${best_file}"
}

wait_for_wordpress_install() {
	local attempts=0
	local max_attempts=30

	until ${WP} core is-installed >/dev/null 2>&1; do
		((attempts += 1))
		if (( attempts >= max_attempts )); then
			echo "WordPress did not become install-ready after $(( max_attempts * 2 )) seconds." >&2
			exit 1
		fi

		printf "."
		sleep 2
	done
}

if [[ -z "${ZIP_FILE}" ]]; then
	ZIP_FILE="$(resolve_latest_plugin_zip)"
fi

if [[ -z "${ZIP_FILE}" ]]; then
	echo "No ${PLUGIN_SLUG}-v*.zip archive found in ${ROOT_DIR}." >&2
	echo "Build a release first with ./scripts/build-release.sh." >&2
	exit 1
fi

echo "Installing ${ZIP_FILE} into the local Docker WordPress instance..."
echo "Ensuring the local WordPress services are running..."
docker compose up -d db wordpress >/dev/null

wait_for_wordpress_install
echo

${WP} plugin install "${CONTAINER_PLUGIN_REPO}/${ZIP_FILE}" --force --activate
