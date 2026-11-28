#!/usr/bin/env bash
#
# setup.sh: builds and starts the Stage 2 Docker Compose stack (MySQL + the
# Laravel API) and waits until both are healthy.
#
# Usage (from anywhere in the repository):
#   ./code/docker/setup.sh
#
# Then:  ./code/docker/test.sh       (73 acceptance tests)
#        http://localhost:8181/api/v1
#
# On the first run it creates .env at the repository root (git-ignored) with
# random passwords and secrets; later runs keep it. Safe to run again.
# Prerequisites: Docker Engine with Compose v2, run by a user in the docker
# group, and ports 3306 and 8181 free (stop a local MySQL and run.sh first).
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
if [ ! -f .env ]; then
  umask 077
  cat > .env <<EOF
# Created by code/docker/setup.sh for docker-compose.yml. Never commit.
MYSQL_ROOT_PASSWORD=$(openssl rand -hex 16)
DB_PASSWORD=$(openssl rand -hex 16)
APP_KEY=base64:$(openssl rand -base64 32)
JWT_SECRET=$(openssl rand -hex 32)
EOF
  echo "    Created .env with new passwords and secrets."
else
  echo "    Using the existing .env."
fi

step "3. Build and start (docker compose up)"
docker compose up -d --build --wait
docker compose ps

printf '\nStage 2 API: http://localhost:8181/api/v1   (stop with: docker compose down)\n'
