#!/bin/sh
set -e

# Schema + admin seed are idempotent; safe to run on every boot.
# Only one container may run them: the `ws` service starts with RUN_MIGRATIONS=0
# so two boots cannot race on schema_migrations.
if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
    php /var/www/backend/migrate.php
fi

exec "$@"
