#!/usr/bin/env bash
# Does sql/full.sql describe the schema its own doctrine_migration_versions seed
# claims? The dump records every shipped migration as applied, so a fresh install
# runs none of them — any column, index or table the dump states differently from
# the migration seeded as applied is drift that no migration will ever repair,
# and it reaches fresh installs only (#715, #717).
#
# Method: seed one database from the dump and let a fresh install's pending
# migrations run on it; clone it, clear the version rows, replay EVERY migration
# over the clone; dump both structures and diff. The migrations carry IF [NOT]
# EXISTS guards, so a replay is a no-op wherever the dump already agrees. Any
# diff line is a finding.
#
# Blind spot worth knowing: a difference inside a CREATE TABLE IF NOT EXISTS —
# an index named differently there, say — is invisible, because the replay skips
# the statement entirely when the table is present.
#
# The three commands it needs are injectable, because the app image ships no
# database client and CI has neither on the same host:
#   DB_CLIENT  run the mariadb client, reading SQL from stdin   (default: mariadb)
#   DB_DUMP    run mariadb-dump                                  (default: mariadb-dump)
#   PHP_CONSOLE  run bin/console                                 (default: php bin/console)
# Each is a command prefix; connection flags belong in it.
#
# Example, against a compose stack:
#   DB_CLIENT='docker compose exec -T -e MYSQL_PWD=secret db mariadb -uroot' \
#   DB_DUMP='docker compose exec -T -e MYSQL_PWD=secret db mariadb-dump -uroot' \
#   PHP_CONSOLE='docker compose run --rm -T app bin/console' \
#   scripts/schema-drift-check.sh
set -o pipefail
set -eu

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

DB_CLIENT="${DB_CLIENT:-mariadb}"
DB_DUMP="${DB_DUMP:-mariadb-dump}"
PHP_CONSOLE="${PHP_CONSOLE:-php bin/console}"
# How the console reaches the database. The replay runs as root because it
# creates and drops objects the application user is not granted.
MIGRATE_DATABASE_URL="${MIGRATE_DATABASE_URL:?set MIGRATE_DATABASE_URL to a DSN pointing at drift_migrated}"

echo "==> seeding drift_dump from sql/full.sql, as a fresh install does"
$DB_CLIENT -e 'DROP DATABASE IF EXISTS drift_dump; CREATE DATABASE drift_dump;'
$DB_CLIENT drift_dump < "$ROOT/sql/full.sql"
# A fresh install runs whatever the dump does NOT record as applied — the newest
# migration usually has no seeded row yet. Both sides must carry that effect,
# otherwise the pending migration reads as drift.
DATABASE_URL="${DUMP_DATABASE_URL:?set DUMP_DATABASE_URL to a DSN pointing at drift_dump}" \
  $PHP_CONSOLE doctrine:migrations:migrate -n --allow-no-migration

echo "==> cloning it into drift_migrated, clearing the version rows"
$DB_CLIENT -e 'DROP DATABASE IF EXISTS drift_migrated; CREATE DATABASE drift_migrated;'
$DB_DUMP --no-data --skip-comments drift_dump > "$WORK/clone.sql"
$DB_CLIENT drift_migrated < "$WORK/clone.sql"
$DB_CLIENT drift_migrated -e 'TRUNCATE TABLE doctrine_migration_versions'

echo "==> replaying every migration over the clone"
DATABASE_URL="$MIGRATE_DATABASE_URL" $PHP_CONSOLE doctrine:migrations:migrate -n --allow-no-migration

# Structure only, and normalised. AUTO_INCREMENT counters differ by construction,
# and the ORDER of the KEY/CONSTRAINT lines inside a CREATE TABLE follows the
# order the objects were created — a migration that drops and recreates an index
# moves its line to the end without changing anything. Sort those lines so the
# diff reports definitions, not history.
dump_structure() {
  $DB_DUMP --no-data --skip-comments --compact "$1" \
    | sed -E 's/ AUTO_INCREMENT=[0-9]+//' \
    | grep -v '^/\*!' \
    | sed -E 's/[[:space:]]+$//' \
    | awk '
        /^[[:space:]]*(PRIMARY KEY|UNIQUE KEY|KEY|CONSTRAINT|FULLTEXT KEY|SPATIAL KEY)/ { line = $0; sub(/,$/, "", line); buf[n++] = line; next }
        { if (n) { asort_lines(); }; print }
        function asort_lines(   i, j, t) {
          for (i = 0; i < n; i++) for (j = i + 1; j < n; j++) if (buf[j] < buf[i]) { t = buf[i]; buf[i] = buf[j]; buf[j] = t }
          for (i = 0; i < n; i++) print buf[i]
          n = 0
        }
        END { if (n) { for (i = 0; i < n; i++) print buf[i] } }
      '
}

dump_structure drift_dump > "$WORK/dump.sql"
dump_structure drift_migrated > "$WORK/migrated.sql"

if diff -u "$WORK/dump.sql" "$WORK/migrated.sql" > "$WORK/diff.txt"; then
  echo "==> sql/full.sql matches the migrations it records as applied"
  exit 0
fi

{
  echo "==> DRIFT: sql/full.sql differs from the migrated schema"
  echo "    -  is sql/full.sql as seeded, +  is the same schema after replaying the migrations"
  cat "$WORK/diff.txt"
} >&2
exit 1
