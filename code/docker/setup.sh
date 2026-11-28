#!/usr/bin/env bash
#
# setup.sh: builds and starts the Docker Compose stack (MySQL, the Stage 1
# raw PHP API, and the Stage 2 Laravel API) and waits until all are healthy.
#
# Usage (from anywhere in the repository):
#   ./code/docker/setup.sh
#
# Then:  ./code/docker/test.sh       (acceptance tests against both stages)
#        http://localhost:8180/  (Stage 1 web pages and API)
#        http://localhost:8181/api/v1  (Stage 2 API)
#
# It creates .env at the repository root (git-ignored) with random passwords
# and secrets, adding any that are missing; existing values are kept. Safe to
# run again. Prerequisites: Docker Engine with Compose v2, run by a user in
# the docker group, and ports 3306, 8180 and 8181 free (stop a local MySQL,
# NGINX, and run.sh first).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

step() { printf '\n==> %s\n' "$*"; }
fail() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

step "1. Prerequisites"
command -v docker >/dev/null || fail "Docker is not installed."
docker compose version >/dev/null 2>&1 || fail "Docker Compose v2 is not installed (docker compose)."
docker info >/dev/null 2>&1 || fail "Cannot use Docker. Is it running, and is $USER in the docker group?"

step "2. Settings (.env)"
# Adds each missing setting with a new random value; existing values are kept.
umask 077
[ -f .env ] || echo "# Created by code/docker/setup.sh for docker-compose.yml. Never commit." > .env
add_setting() {  # name, value
  if ! grep -q "^$1=" .env; then
    echo "$1=$2" >> .env
    echo "    Added $1."
  fi
}
add_setting MYSQL_ROOT_PASSWORD "$(openssl rand -hex 16)"
add_setting DB_PASSWORD "$(openssl rand -hex 16)"            # Stage 2's MySQL account
add_setting STAGE1_DB_PASSWORD "$(openssl rand -hex 16)"     # Stage 1's MySQL account
add_setting APP_KEY "base64:$(openssl rand -base64 32)"
add_setting JWT_SECRET "$(openssl rand -hex 32)"             # shared by both stages
umask 022
echo "    .env is ready."

step "3. Build and start (docker compose up)"
docker compose up -d --build --wait
docker compose ps

printf '\nStage 1: http://localhost:8180/   Stage 2 API: http://localhost:8181/api/v1   (stop with: docker compose down)\n'
