#!/bin/sh
set -eu
if [ "$#" -eq 0 ] || [ "$1" = "apache2-foreground" ]; then
    php artisan migrate --force --no-interaction
    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
fi
exec "$@"
