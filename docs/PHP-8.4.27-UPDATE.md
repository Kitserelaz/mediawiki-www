# Main stack: MediaWiki 1.46.2 and PHP 8.4.27

The main `cloudwiki` development stack was upgraded on 2026-10-21 to
MediaWiki 1.46.2 and PHP 8.4.27 FPM on Debian Bookworm. The official PHP
multi-platform index is pinned to
`sha256:df5a5113e1179ddf4560cd15727eb3812f6457829756d2a96c6c11bb4c250089`.
Both ARM64 and AMD64 manifests were present. Builds in this update are native
ARM64; the earlier AMD64 report covers PHP 8.4.26 only.

The source Dockerfile, upgrade targets, dependency inventory, and runtime
reference documentation agree on PHP 8.4.27. The local `.env` selects
`cloudwiki-php:local` for PHP-FPM, workers, scheduler, and jobchron, and
`cloudwiki-web:local` for matching web assets. Both images were rebuilt.
No production deployment was performed.

## Migration and recovery

The main stack's original images contained MediaWiki 1.45.1 on PHP 8.3.30;
jobchron separately used PHP 8.4.26. The checkout already targeted MediaWiki
1.46.2. Recreating the main stack directly from the checkout initially exposed
a login database error because the application also changed. Its original
application was restored and a PHP-only image was temporarily verified. The
user then explicitly requested that the main stack use MediaWiki 1.46.2.

Before the coordinated application upgrade, all application writers and Redis
were stopped and a snapshot was taken at
`backups/pre-main-1462-php8427-20261021`. It contains 11 database dumps, uploads,
dumps, Redis, counter and Caddy archives, and private configuration. Completeness
was verified and 21 files were recorded in `SHA256SUMS`. Saved configuration
and secrets are private and excluded from Git.

The read-only preflight passed historical schema/index, platform, privilege,
and shared-domain mapping checks. The explicit coordinated updater completed
shared/per-wiki migrations and metadata refresh successfully. CheckUser's data migration
requires Redis to enqueue jobs; Redis was restarted after the updater first
stopped on that missing dependency, with application writers still stopped.

Original images remain tagged `cloudwiki-php:pre-8427`,
`cloudwiki-web:pre-8427`, and `cloudwiki-php:jobchron-pre-8427`.
After migration, rollback requires restoring the coordinated snapshot into
empty volumes with matching old images/configuration as described in
`CREATEWIKI-MANAGEWIKI-UPGRADE-RUNBOOK.md`; selecting old images against upgraded
schemas is insufficient. The intermediate PHP-only image is no longer selected and its temporary tag was removed.

## Validation

- Source checkout verification and `git diff --check` passed.
- PHP 8.4.27 loads all 16 required native modules, including Redis 6.2.0 and
  Memcached 3.3.0.
- Checkout-image syntax checker passed 33,847 PHP files, with one existing
  hash-verified exclusion for syntax requiring a newer PHP version.
- The complete disposable jobchron lifecycle suite passed on the rebuilt
  MediaWiki 1.46.2/PHP 8.4.27 image: delayed execution, interrupted-claim recovery,
  retry exhaustion, Redis durability, heartbeat failure/recovery, and SIGTERM.
- The first job lifecycle run timed out observing a worker claim during image
  build contention; a full rerun after the build passed.
- HTTPS and browser login/SSO/logout, editing, Lua, uploads, and thumbnails passed
  on both the intermediate image and the final migrated MediaWiki 1.46.2/PHP
  8.4.27 stack. Final login/editing browser checks: 2 passed in 14.6 seconds.
- Private-wiki API/file protection and XML/ZIP dump generation browser checks
  passed: 2 tests in 12.9 seconds.
- Main-stack queue durability passed: Redis restart preserved queued jobs and
  the worker drained existing and newly provisioned wiki queues.

Playwright Chromium was missing and was installed into temporary storage. The
main web container previously had no published host ports; recreating it restored
loopback HTTPS.

## Rehearsal cleanup

At the user's explicit request, all six retired upgrade/test projects were
deleted: `cloudwiki-upgrade1462`, `cloudwiki-upgrade-restore1462`,
`cloudwiki-upgrade-recovery1462`, `cloudwiki-upgrade-production1462`,
`cloudwiki-upgrade-rollback1462`, and `cloudwiki-upgrade-unit1462`.
This removed 36 containers, 51 dedicated volumes, and 9 networks. The first four
were found in the running inventory; the last two were stopped and found during
the final inventory. Saved backup files and the main `cloudwiki` volumes remain.
Historical reports remain and do not indicate those environments still exist.
Disposable jobchron test stacks clean up their containers, volumes, and networks.

Final image identities and validation results are recorded in
`upgrade-evidence/php-8427-main.json`.
