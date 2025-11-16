#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 1 ]]; then
  echo "Usage: $0 <tag> [release-notes-file]" >&2
  exit 1
fi

TAG="$1"
NOTES_FILE="${2:-}" 
REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CLI_DIR="${REPO_ROOT}/cli"
DIST_PHAR="${CLI_DIR}/dist/localpoc.phar"
BOX_BIN="${CLI_DIR}/vendor/bin/box"

if ! command -v gh >/dev/null 2>&1; then
  echo "[localpoc] GitHub CLI (gh) is required for publishing releases." >&2
  exit 1
fi

if [[ ! -d "${CLI_DIR}" ]]; then
  echo "[localpoc] CLI directory not found at ${CLI_DIR}." >&2
  exit 1
fi

pushd "${CLI_DIR}" >/dev/null

echo "[localpoc] Installing PHP dependencies..."
composer install --prefer-dist --no-progress

if [[ ! -x "${BOX_BIN}" ]]; then
  echo "[localpoc] Box binary missing. Ensure humbug/box is listed in composer require-dev." >&2
  exit 1
fi

echo "[localpoc] Building PHAR with Box..."
"${BOX_BIN}" compile

if [[ ! -f "${DIST_PHAR}" ]]; then
  echo "[localpoc] Expected PHAR not found at ${DIST_PHAR}." >&2
  exit 1
fi

popd >/dev/null

echo "[localpoc] Creating GitHub release ${TAG}..."
GH_ARGS=("${TAG}" "${DIST_PHAR}" "--title" "LocalPOC ${TAG}")
if [[ -n "${NOTES_FILE}" ]]; then
  GH_ARGS+=("--notes-file" "${NOTES_FILE}")
else
  GH_ARGS+=("--notes" "Automated release for LocalPOC ${TAG}.")
fi

gh release create "${GH_ARGS[@]}"

echo "[localpoc] Release ${TAG} published with asset ${DIST_PHAR}."
