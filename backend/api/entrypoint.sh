#!/usr/bin/env sh
set -eu

if [ ! -d vendor ]; then
  echo "[php-api] vendor missing, running composer install"
  composer install --no-interaction --prefer-dist --no-progress
fi

exec php -S 0.0.0.0:${APP_PORT:-8081} -t public
