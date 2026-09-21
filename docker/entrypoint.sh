#!/usr/bin/env bash
set -e

php artisan config:clear
php artisan migrate --force

# Reverb runs in the background; the web server is the foreground process
# Dokploy watches. Both live in this one container, so the app can always
# reach Reverb at 127.0.0.1:8080 with no extra inter-service networking.
php artisan reverb:start --host=0.0.0.0 --port=8080 &

exec php artisan serve --host=0.0.0.0 --port=80
