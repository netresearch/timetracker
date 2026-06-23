#!/bin/sh
#
# Production entrypoint: bring the database schema up to date before starting
# PHP-FPM, so deploying a new image over an existing database applies any pending
# Doctrine migrations automatically instead of leaving the live schema behind the
# code that reads it.
#
# Set AUTO_MIGRATE=0 to skip this (e.g. when migrations are applied out-of-band,
# or for read-only/replica containers).
#
set -eu

PROJECT_DIR=/var/www/html

console() { php "${PROJECT_DIR}/bin/console" "$@"; }

# Run a single-value SQL query and echo just the number, robust against the
# console's table formatting. Empty/absent result is treated as 0 by callers.
scalar() { console dbal:run-sql "$1" 2>/dev/null | grep -oE '[0-9]+' | head -n1; }

# Mark a migration as applied WITHOUT running it, when its schema change is
# already present. This baselines a database that was created from sql/full.sql
# before migration tracking existed (its tables exist but no versions are
# recorded), so migrate() below only runs the migrations that are genuinely
# missing instead of re-attempting already-applied DDL.
baseline_if_present() {
    fqcn="$1"
    present="$(scalar "$2")"
    if [ "${present:-0}" != "0" ]; then
        echo "[entrypoint]   already applied, recording: ${fqcn}"
        console doctrine:migrations:version "${fqcn}" --add --no-interaction >/dev/null
    fi
}

auto_migrate() {
    echo "[entrypoint] AUTO_MIGRATE on — reconciling database schema"

    # Wait for the database to accept connections (up to ~60s).
    n=0
    until console dbal:run-sql 'SELECT 1' >/dev/null 2>&1; do
        n=$((n + 1))
        if [ "${n}" -ge 30 ]; then
            echo "[entrypoint] ERROR: database not reachable after 60s" >&2
            exit 1
        fi
        sleep 2
    done

    console doctrine:migrations:sync-metadata-storage --no-interaction >/dev/null

    # If nothing is recorded yet but the application schema already exists, this is
    # a pre-tracking database — baseline it from the live schema before migrating.
    versions="$(scalar 'SELECT COUNT(*) FROM doctrine_migration_versions')"
    appschema="$(scalar "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'entries'")"
    if [ "${versions:-0}" = "0" ] && [ "${appschema:-0}" != "0" ]; then
        echo "[entrypoint] un-versioned existing database — baselining from live schema"
        baseline_if_present 'DoctrineMigrations\Version20250901_AddPerformanceIndexes' \
            "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'entries' AND index_name = 'idx_entries_user_day'"
        baseline_if_present 'DoctrineMigrations\Version20250901_EncryptTokenFields' \
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users_ticket_systems' AND column_name = 'accesstoken' AND data_type = 'text'"
        baseline_if_present 'DoctrineMigrations\Version20260612_FixHolidaysSchema' \
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'holidays' AND column_name = 'name'"
        baseline_if_present 'DoctrineMigrations\Version20260622_AddMinEntryDuration' \
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'min_entry_duration'"
        baseline_if_present 'DoctrineMigrations\Version20260622_AddJiraCloudSupport' \
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ticket_systems' AND column_name = 'cloud_id'"
    fi

    # Apply the remaining pending migrations. Fails loudly (set -e) so a bad
    # migration aborts the deploy instead of serving a half-migrated schema.
    console doctrine:migrations:migrate --no-interaction --allow-no-migration
    echo "[entrypoint] database schema up to date"
}

if [ "${1:-}" = "php-fpm" ] && [ "${AUTO_MIGRATE:-1}" = "1" ]; then
    auto_migrate
fi

exec docker-php-entrypoint "$@"
