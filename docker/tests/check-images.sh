#!/bin/sh
set -eu
# Explicitly test runtime data and host configuration did not enter either image.
docker run --rm --entrypoint sh "${MW_IMAGE:-cloudwiki-php:local}" -ec '
  test ! -e OAuth2.key
  test ! -e LocalSettings.php.save
  test ! -e dump.rdb
  test ! -e docker/secrets/farm.json
  test ! -e cw_cache/databases.php
  test ! -e images/metawiki
  test ! -e extensions_old
  ! grep -q "wgDBpassword = '\''" PrivateSettings.php
'
docker run --rm --entrypoint sh "${COUNTER_IMAGE:-cloudwiki-counter:local}" -ec '
  test ! -e /app/.env
  test ! -e /app/count.db
  test ! -e /app/.git
  test "$(id -u)" != 0
'
