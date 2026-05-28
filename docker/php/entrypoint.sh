#!/usr/bin/env bash
set -euo pipefail

mkdir -p vendor var
chown -R app:app vendor var

if [[ ! -f vendor/autoload.php ]]; then
  su-exec app composer install --no-interaction --prefer-dist
fi

if [[ "${1:-}" == "php-fpm" ]]; then
  exec "$@"
fi

exec su-exec app "$@"
