#!/bin/sh
set -eu
# Ensure the server-local cache extension can retain values, not just load.
docker run --rm --entrypoint php "${MW_IMAGE:-cloudwiki-php:local}" \
  -d apc.enable_cli=1 -r '
    foreach (["intl", "mbstring", "mysqli", "pdo_mysql", "gd", "zip", "gmp",
              "bcmath", "ldap", "exif", "calendar", "pcntl", "sockets",
              "Zend OPcache", "redis", "memcached", "apcu"] as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException("Missing runtime extension: " . $extension);
        }
    }
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
  php-fpm --test
  python3 -c "import aiohttp, redis, pygments"
  for tool in convert ffmpeg lua5.1 zip unzip diff rsvg-convert gs dot ploticus \
      curl mariadb tini kakasi pdftoppm ddjvu tiffinfo 7z zstd; do
    command -v "$tool" > /dev/null
  done
  test -r docker/mediawiki/bootstrap.php
  test -r docker/mediawiki/runtime.php
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
