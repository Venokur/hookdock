#!/bin/sh
set -e

# Schema + admin seed are idempotent; safe to run on every boot.
php /var/www/backend/migrate.php

exec "$@"
