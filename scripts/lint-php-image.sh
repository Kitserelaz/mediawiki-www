#!/bin/sh
# Check shipped PHP files with explicit, hash-verified future-version guards.
set -eu
cd "$(dirname "$0")/.."
docker run --rm --entrypoint python3 \
  -v "$PWD/scripts/lint-php-image.py:/opt/lint-php-image.py:ro" \
  -v "$PWD/docs/php-syntax-exceptions.json:/opt/php-syntax-exceptions.json:ro" \
  "${MW_IMAGE:-cloudwiki-php:local}" /opt/lint-php-image.py
