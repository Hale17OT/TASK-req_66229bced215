#!/usr/bin/env bash
# Dockerized test runner.
#
#   ./scripts/run_tests.sh                     # run all tests
#   ./scripts/run_tests.sh --testsuite=api     # only API suite
#   ./scripts/run_tests.sh --filter=AuthApi    # only tests matching AuthApi
#
# Prerequisites: `docker compose up -d` has been run at least once and the
# app/db containers are healthy. The script:
#   1. Creates the test database if missing.
#   2. Runs migrations + seed against it (idempotent).
#   3. Executes phpunit from inside the app container with DB_NAME pointed at
#      the test database, so tests never touch the dev/prod DB.
set -euo pipefail

cd "$(dirname "$0")/.."

export MSYS_NO_PATHCONV=1

DB_USER="${MYSQL_USER:-studio}"
DB_PASS="${MYSQL_PASSWORD:-studio_change_me}"
ROOT_PASS="${MYSQL_ROOT_PASSWORD:-root_change_me}"
TEST_DB="${TEST_DB_NAME:-studio_console_test}"

# Verify compose is running
if ! docker compose ps --status=running --services 2>/dev/null | grep -q '^app$'; then
  echo "[run_tests] docker compose stack is not running; starting it..."
  docker compose up -d
  # Wait up to ~30s for app to be responsive
  for i in $(seq 1 30); do
    if docker compose exec -T app php -v >/dev/null 2>&1; then break; fi
    sleep 1
  done
fi

# The production image is built with `composer install --no-dev`, so phpunit
# (require-dev) isn't in the vendor/ baked into the image. A fresh `app-vendor`
# volume inherits that phpunit-less state on first boot. Install dev deps now
# so the mounted volume carries phpunit; subsequent runs skip the install.
if ! docker compose exec -T app test -x vendor/bin/phpunit 2>/dev/null; then
  echo "[run_tests] installing PHP dev dependencies (phpunit)..."
  docker compose exec -T app composer install --no-interaction --no-progress --prefer-dist
  # composer wipes vendor/services.php during install; regenerate it so
  # ThinkPHP discovers the migration service (otherwise `php think migrate:run`
  # below fails with "no commands in the 'migrate' namespace").
  docker compose exec -T app php think service:discover >/dev/null
fi

echo "[run_tests] ensuring test database '$TEST_DB' exists + migrated..."
# CREATE DATABASE and GRANT require root; studio user can then migrate.
docker compose exec -T -e TEST_DB="$TEST_DB" -e ROOT_PASS="$ROOT_PASS" -e DB_USER="$DB_USER" app php -r '
$pdo = new PDO("mysql:host=db;port=3306", "root", getenv("ROOT_PASS"),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db = getenv("TEST_DB");
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("GRANT ALL PRIVILEGES ON `{$db}`.* TO '\''" . getenv("DB_USER") . "'\''@'\''%'\''");
$pdo->exec("FLUSH PRIVILEGES");
echo "db ready\n";
'
docker compose exec -T -e DB_NAME="$TEST_DB" app php think migrate:run >/dev/null
docker compose exec -T -e DB_NAME="$TEST_DB" app php think db:seed >/dev/null

echo "[run_tests] running phpunit..."
docker compose exec -T -e DB_NAME="$TEST_DB" app vendor/bin/phpunit --colors=always "$@"
