#!/bin/sh
# Disposable database and PHP containers; no farm credentials, ports or volumes.
set -eu
source_root=$(CDPATH= cd -- "$(dirname -- "$0")/../../.." && pwd)
run_name="archivewikiimport-test-$$"
db_name="$run_name-db"
cleanup() { docker rm -f "$run_name" "$db_name" >/dev/null 2>&1 || true; }
trap cleanup EXIT INT TERM
docker run -d --name "$db_name" --tmpfs /var/lib/mysql \
 -e MARIADB_ROOT_PASSWORD=disposable-test-password mariadb:11.4.10 >/dev/null
for attempt in $(seq 1 60); do
 if docker exec "$db_name" mariadb-admin ping --host=127.0.0.1 -uroot -pdisposable-test-password --silent >/dev/null 2>&1; then break; fi
 sleep 1
done
docker run --rm --name "$run_name" --network "container:$db_name" \
 --mount "type=bind,src=$source_root,dst=/var/www/mediawiki,readonly" \
 -e AWI_LIVE_ARCHIVE="${AWI_LIVE_ARCHIVE:-0}" \
 --entrypoint sh "${AWI_PHP_IMAGE:-cloudwiki-php:local}" -eu -c '
 if ! command -v 7z >/dev/null || ! command -v zstd >/dev/null; then
  apt-get update -qq
  apt-get install -y -qq --no-install-recommends p7zip-full zstd >/dev/null
 fi
 php extensions/ArchiveWikiImport/tests/unit.php
 mkdir -p /tmp/awi-test /tmp/awi-test/runs /tmp/awi-test/images /tmp/awi-test/cache
 mkdir /tmp/awi-core
 for entry in /var/www/mediawiki/*; do
  case "$entry" in */LocalSettings.php|*/PrivateSettings.php) continue;; esac
  ln -s "$entry" /tmp/awi-core/
 done
 export MW_INSTALL_PATH=/tmp/awi-core
 php maintenance/run.php install \
  --confpath /tmp/awi-test --dbname archivewikiimport_disposable \
  --dbserver 127.0.0.1 --dbuser root --dbpass disposable-test-password \
  --installdbuser root --installdbpass disposable-test-password \
  --server http://archivewikiimport.invalid --scriptpath "" --pass DisposableAdminPassword123 \
  --skins Vector --extensions ArchiveWikiImport "Archive import test" Admin >/tmp/awi-install.log 2>&1 || { cat /tmp/awi-install.log; exit 1; }
 cat >> /tmp/awi-test/LocalSettings.php <<"SETTINGS"
$wgArchiveWikiImportDirectory = "/tmp/awi-test/runs";
$wgUploadDirectory = "/tmp/awi-test/images";
$wgCacheDirectory = "/tmp/awi-test/cache";
$wgLocalisationCacheConf["storeDirectory"] = "/tmp/awi-test/cache";
$wgEnableUploads = true;
$wgExtraNamespaces[3000] = "TestSpace";
$wgExtraNamespaces[3001] = "TestSpace_talk";
SETTINGS
 php maintenance/run.php /var/www/mediawiki/extensions/ArchiveWikiImport/tests/integration.php --conf /tmp/awi-test/LocalSettings.php
 php maintenance/run.php /var/www/mediawiki/extensions/ArchiveWikiImport/maintenance/recoverImports.php --conf /tmp/awi-test/LocalSettings.php
 cat >> /tmp/awi-test/LocalSettings.php <<"DEPENDENCIES"
wfLoadExtension( "TemplateStyles" );
DEPENDENCIES
 php maintenance/run.php /var/www/mediawiki/extensions/ArchiveWikiImport/tests/integration.php --conf /tmp/awi-test/LocalSettings.php --dependencies-resume
'
