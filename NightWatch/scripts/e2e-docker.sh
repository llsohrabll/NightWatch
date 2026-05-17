#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${E2E_BASE_URL:-http://127.0.0.1:8080}"
COMPOSE="${DOCKER_COMPOSE_CMD:-docker compose}"
CLEANUP="${NIGHTWATCH_E2E_CLEANUP:-1}"

cleanup() {
  if [[ "$CLEANUP" == "1" ]]; then
    $COMPOSE down -v >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

$COMPOSE up -d --build

for _ in $(seq 1 60); do
  if curl -fsS "$BASE_URL/" >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

curl -fsS "$BASE_URL/" >/dev/null

if [[ ! -d node_modules ]]; then
  npm install
fi
npx playwright install chromium
E2E_BASE_URL="$BASE_URL" npx playwright test
