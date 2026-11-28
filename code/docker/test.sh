#!/usr/bin/env bash
#
# test.sh: runs the acceptance tests against the Docker Compose stack.
#
# Usage (from anywhere in the repository):
#   ./code/docker/test.sh                # both stages, results printed
#   ./code/docker/test.sh --stage 2      # one stage only (1 or 2)
#   ./code/docker/test.sh --save         # also save code/results/stage<N>-docker-<date>.txt
#                                        # (stage<N>-docker-<date>-<HHMMSS>.txt if that file exists)
#
# It runs setup.sh (builds and starts the stack if needed), restarts the
# Stage 2 container so it serves the current code/stage2/src (Stage 1 reads
# its files per request and needs no restart), and then runs
# code/tests/acceptance.sh on this machine against the published ports.
# Exit code: 0 when every tested stage passed, 1 if tests failed, 2 for a setup problem.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

STAGES="1 2"
SAVE=false
while [ $# -gt 0 ]; do
  case "$1" in
    --save)    SAVE=true; shift ;;
    --stage)   [ $# -ge 2 ] || { echo "--stage needs a value: 1, 2, or both" >&2; exit 2; }
               case "$2" in 1|2) STAGES=$2 ;; both) STAGES="1 2" ;;
                 *) echo "Unknown stage: $2 (use 1, 2, or both)" >&2; exit 2 ;; esac
               shift 2 ;;
    -h|--help) sed -n '2,15p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *)         echo "Unknown option: $1 (use --help)" >&2; exit 2 ;;
  esac
done

./code/docker/setup.sh >/dev/null 2>&1 || { echo "setup.sh failed; run it on its own to see why." >&2; exit 2; }

# Stage 2 copies code/stage2/src only when it starts, so recreate it.
if [[ " $STAGES " == *" 2 "* ]]; then
  docker compose up -d --wait --force-recreate --no-deps app >/dev/null 2>&1 \
    || { echo "The Stage 2 container did not become healthy: docker compose logs app" >&2; exit 2; }
fi

# Settings for acceptance.sh: MySQL root through the published port, and the
# JWT secret both stages share. Each stage's API address is acceptance.sh's own
# default (8180 and 8181), which are the ports docker-compose.yml publishes.
set -a; . ./.env; set +a
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"     # keeps the password off the command line
export MYSQL="mysql -h 127.0.0.1 -P 3306 -u root"
export JWT_SECRET

result=0
for stage in $STAGES; do
  [ "$stage" = "${STAGES%% *}" ] || echo
  set +e      # a failed test run must still report, and the other stage must still run
  case $stage in
    1) export LABEL="Stage 1 (raw PHP, PHP built-in server in Docker)" ;;
    2) export LABEL="Stage 2 (Laravel, in Docker)" ;;
  esac
  if $SAVE; then
    out="code/results/stage$stage-docker-$(date +%F).txt"
    # Never overwrite an earlier run from the same day: add the time instead.
    [ -e "$out" ] && out="code/results/stage$stage-docker-$(date +%F-%H%M%S).txt"
    ./code/tests/acceptance.sh --stage "$stage" | tee "$out"
    status=${PIPESTATUS[0]}
    echo "Saved: $out"
  else
    ./code/tests/acceptance.sh --stage "$stage"
    status=$?
  fi
  set -e
  [ "$status" -gt "$result" ] && result=$status
done
exit "$result"
