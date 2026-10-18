#!/bin/sh
set -eu
# This only initializes mounted directory ownership, never schemas or data.
if [ "$(id -u)" = 0 ]; then
    for dir in "${MW_UPLOAD_ROOT:-/var/www/mediawiki/images}" cw_cache cache "${MW_DUMP_DIRECTORY:-/var/www/dumps}" /var/log/mediawiki; do
        mkdir -p "$dir"
        chown www-data:www-data "$dir"
    done
    if [ "${1:-}" != php-fpm ] && [ "${MW_MANAGEMENT:-0}" != 1 ]; then
        exec su -s /bin/sh www-data -c 'exec "$@"' -- sh "$@"
    fi
fi
exec "$@"
