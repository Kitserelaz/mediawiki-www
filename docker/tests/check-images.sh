#!/bin/sh
set -eu
# Ensure the server-local cache extension can retain values, not just load.
docker run --rm --entrypoint php "${MW_IMAGE:-cloudwiki-php:local}" \
  -d apc.enable_cli=1 -r '
    if (!extension_loaded("apcu") || !apcu_enabled()) {
        throw new RuntimeException("The PHP image requires an enabled APCu cache");
    }
    $key = "farm-image-check:" . bin2hex(random_bytes(8));
    if (!apcu_store($key, "ok", 30) || apcu_fetch($key) !== "ok") {
        throw new RuntimeException("APCu cannot retain cache values");
    }
    apcu_delete($key);
    echo "APCu " . phpversion("apcu") . ": cache read/write passed.\n";
  '
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
