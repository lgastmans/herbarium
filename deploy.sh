#!/usr/bin/env bash

set -Eeuo pipefail

PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer2}"

if ! "$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);'; then
    echo "Deployment aborted: PHP 8.2 or newer is required." >&2
    "$PHP_BIN" -v >&2 || true
    exit 1
fi

COMPOSER_PATH="$(command -v "$COMPOSER_BIN" || true)"

if [[ -z "$COMPOSER_PATH" ]]; then
    echo "Deployment aborted: Composer was not found ($COMPOSER_BIN)." >&2
    exit 1
fi

echo "Deploying with $("$PHP_BIN" -r 'echo "PHP ".PHP_VERSION;')"

git pull --ff-only origin main

"$PHP_BIN" "$COMPOSER_PATH" install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan config:clear
"$PHP_BIN" artisan cache:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache

echo "Deployment complete."
