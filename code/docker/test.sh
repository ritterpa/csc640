#!/usr/bin/env bash
#
# test.sh: runs the 73 acceptance tests against the Docker Compose stack.
#
# Usage (from anywhere in the repository):
#   ./code/docker/test.sh          # print the results
#   ./code/docker/test.sh --save   # also save them to code/results/stage2-docker-<date>.txt
#                                  # (stage2-docker-<date>-<HHMMSS>.txt if that file exists)
#
# It runs setup.sh (builds and starts the stack if needed), restarts the app
# container so it serves the current code/stage2/src, and then runs
# code/tests/acceptance.sh on this machine against the published ports.
# Exit code: the test runner's (0 all passed, 1 some failed, 2 setup problem).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

SAVE=false
case "${1:-}" in
  --save)    SAVE=true ;;
  -h|--help) sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
  "")        ;;
  *)         echo "Unknown option: $1 (use --help)" >&2; exit 2 ;;
esac

./code/docker/setup.sh >/dev/null 2>&1 ||{ echo "setup.sh failed; run it on its own to see why." >&2; exit 2; }

# The app copies code/stage2/src only when it starts, so recreate it.
docker compose up -d --wait --force-recreate --no-deps app >/dev/null 2>&1 \
  || { echo "The app container did not become healthy: docker compose logs app" >&2; exit 2; }

# Settings for acceptance.sh: the published API port (its Stage 2 default),
# MySQL root through the published port, and the server's JWT secret.
set -a; . ./.env; set +a
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"     # keeps the password off the command line
export BASE=http://localhost:8181
export MYSQL="mysql -h 127.0.0.1 -P 3306 -u root"
export JWT_SECRET

if $SAVE; then
  out="code/results/stage2-docker-$(date +%F).txt"
  # Never overwrite an earlier run from the same day: add the time instead.
  [ -e "$out" ] && out="code/results/stage2-docker-$(date +%F-%H%M%S).txt"
  set +e      # a failed test run must still report where its output went
  ./code/tests/acceptance.sh --stage 2 | tee "$out"
  status=${PIPESTATUS[0]}
  set -e
  echo "Saved: $out"
  exit "$status"
fi
exec ./code/tests/acceptance.sh --stage 2
