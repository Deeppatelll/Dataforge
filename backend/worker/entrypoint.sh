#!/usr/bin/env sh
set -eu

if [ ! -d vendor ]; then
  echo "[php-worker] vendor missing, running composer install"
  composer install --no-interaction --prefer-dist --no-progress
fi

exec "$@"
