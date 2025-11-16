#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 1 ]]; then
  echo "Usage: $0 <tag> [release-notes-file]" >&2
  exit 1
fi

TAG="$1"
NOTES_FILE="${2:-}"
REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_DIR="${REPO_ROOT}/plugin"
ZIP_NAME="localpoc-plugin-${TAG}.zip"
ZIP_PATH="${REPO_ROOT}/${ZIP_NAME}"

command -v gh >/dev/null 2>&1 || { echo "[localpoc] GitHub CLI (gh) is required." >&2; exit 1; }
command -v zip >/dev/null 2>&1 || { echo "[localpoc] 'zip' command is required." >&2; exit 1; }

if [[ ! -d "${PLUGIN_DIR}" ]]; then
  echo "[localpoc] Plugin directory not found at ${PLUGIN_DIR}." >&2
  exit 1
fi

rm -f "${ZIP_PATH}"
pushd "${PLUGIN_DIR}" >/dev/null
zip -r -q "${ZIP_PATH}" . -x '*.DS_Store'
popd >/dev/null

echo "[localpoc] Created plugin archive ${ZIP_PATH}" 

if gh release view "${TAG}" >/dev/null 2>&1; then
  echo "[localpoc] Release ${TAG} exists. Uploading asset..."
  gh release upload "${TAG}" "${ZIP_PATH}" --clobber
else
  echo "[localpoc] Release ${TAG} not found. Creating new release..."
  GH_ARGS=("${TAG}" "${ZIP_PATH}" "--title" "LocalPOC Plugin ${TAG}")
  if [[ -n "${NOTES_FILE}" ]]; then
    GH_ARGS+=("--notes-file" "${NOTES_FILE}")
  else
    GH_ARGS+=("--notes" "Plugin release ${TAG}.")
  fi
  gh release create "${GH_ARGS[@]}"
fi

rm -f "${ZIP_PATH}"
echo "[localpoc] Plugin release complete."
