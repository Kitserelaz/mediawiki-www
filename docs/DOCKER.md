# Docker development and deployment

This candidate packages MediaWiki 1.46.2 on PHP 8.4.27 with the pinned CreateWiki/ManageWiki upgrade and Moe-Counter. It preserves the supplied catalogue with explicit component source/dependency ports recorded in `upgrade-targets.json` and the source-port reports. Application base images use immutable multi-platform digests, and apt resolves against the signed Debian snapshot dated 2026-10-15; update these deliberately and rebuild both architectures. The container has one core runtime (1.46); historical per-wiki version metadata does not select additional code installations. Component versions, installed Composer package identities, PHP requirements, local patch hashes, and lockfile hashes are recorded in `dependency-inventory.json`; regenerate with `python3 scripts/inventory.py` after intentional dependency changes.

The PHP image includes pinned APCu 5.1.28 with 128 MB of server-local cache for
ResourceLoader and other local-cache consumers. OPcache caches PHP bytecode;
Memcached remains the farm's main object cache. APCu is enabled for FPM and
keeps its default disabled state for CLI. Verify the actual FPM cache across
requests with:

```sh
docker compose exec -T php python3 - < docker/tests/local-cache.py
```

## First local start

Install and start Docker Desktop (Compose 2.24.4+ is required for the production override). Keep at least 20 GiB free on the drive holding Docker’s disk image; placing the checkout on another drive does not move Docker storage. The helper builds images sequentially to limit peak resource use. From the workspace root:

```sh
scripts/farm setup
scripts/farm build
docker compose --profile dev up -d database redis memcached mailpit
scripts/farm init
scripts/farm up
scripts/farm certificate
scripts/farm smoke
```

Open https://test.localhost, https://meta.localhost, https://login.localhost, and https://counter.localhost. `auth.localhost` serves shared authentication routes, not a wiki homepage. Local email is captured at http://localhost:8025. The admin username defaults to `Admin`; read the generated password from `docker/secrets/admin-password`. Fresh initialization assigns the farm-management `steward` role on meta. Local development disables the inherited group two-factor requirement. Production retains it: sign into meta and enroll at `Special:OATHManage` before using steward permissions such as CreateWiki and ManageWiki. Save recovery codes privately.

Caddy issues individual local-hostname certificates through its persistent internal CA. This avoids relying on browser acceptance of a second-level `*.localhost` wildcard certificate. The on-demand permission endpoint allows only `localhost` names and is not published. `scripts/farm certificate` exports the public CA certificate only. On macOS, trust it once with:

```sh
sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain docker/secrets/local-ca.crt
```

On Linux import this certificate with your distribution's CA tools; Firefox may require importing it in its own Authorities store. Certificate trust is a host-level action, not performed by setup. The smoke test trusts this specific certificate without modifying the host trust store. Use a browser that resolves `*.localhost` to loopback; for tools without this behavior, use explicit loopback mappings. Do not add public DNS records for local development.

The optional development override mounts `includes`, extensions, skins, and static assets read-only for live edits:

```sh
docker compose -f compose.yaml -f compose.development.yaml --profile dev up -d
```

Changes to farm settings, worker code, or image dependencies require `scripts/farm build` and container recreation. Existing host databases, uploads, cached farm configuration, and `.env` files are never used automatically.

## Configuration and storage

Copy `.env.example` to `.env` or run setup. `MW_DOMAIN`, `MW_BETA_DOMAIN`, and `MW_REALM` replace host-name-based deployment assumptions. Local database names remain `metawiki`, `testwiki`, and `loginwiki`. Local URL overrides are in memory; restoring production metadata does not rewrite its stored domain values.

`docker/secrets/farm.json` holds `db_password`, `secret_key`, `upgrade_key`, and optional `smtp_user`, `smtp_password`, `hcaptcha_site_key`, `hcaptcha_secret_key`, `matomo_token`, `cloudflare_zone`, `cloudflare_token`, and `discord_webhook`. Existing secrets are never replaced by setup. Restoring an existing farm requires its original signing/session keys and OAuth key pair; generating replacements invalidates sessions or tokens. A new production farm generates its own credentials in a separate directory with `setup-production`. Runtime files are excluded from images. The host secrets directory is mode 0700; files must be readable by container service users on Docker Desktop.

`MW_UPLOAD_ROOT` and `MW_DUMP_DIRECTORY` set container storage paths and volume destinations; keep these absolute and consistent for every farm service. Database and Redis persistence, uploads, CreateWiki metadata, localization caches, dumps, counter data, and Caddy certificate state use named volumes. Never use `docker compose down -v` for routine shutdown. `scripts/farm stop` preserves everything. MariaDB is internal; only the reverse proxy and the loopback Mailpit UI publish ports.

The app database user has database-level privileges for `*wiki`/`*wikibeta`, `wikidb`, `centralauth`, and `incidents` so CreateWiki can provision wiki schemas. Database root credentials are mounted only in the database and explicit initialization service. Before restoring databases outside these conventions, extend grants deliberately.

Moe-Counter uses `DB_PATH=/app/data/count.db`; outside Docker its original `count.db` default is retained. Use one counter replica: SQLite and the process-local read/update queue do not provide distributed increments. MongoDB remains available through Compose variables `COUNTER_DB_TYPE=mongodb` and `COUNTER_DB_URL` (mapped to the app's `DB_TYPE` and `DB_URL`), but adding replicas still requires atomic database increments.

## Redis queue maintenance

`worker` executes ready jobs and provisions new wikis. The separate `jobchron` service
runs Wikimedia's pinned `redisJobChronService` to release delayed jobs, recycle expired
claims, abandon jobs that exhaust their attempts, and prune old abandoned records.
Both services must run for a complete Redis queue lifecycle. Chron connects only to
this stack's internal Redis; it has no farm secrets, database credentials or upload mounts.

The unmodified upstream subset and license are in `docker/mediawiki/jobrunner`.
`SOURCE.json` records commit `09f30782077b6101871fa95715f7181391d20486` and file hashes,
checked by `scripts/verify-checkout.py`. The adapter uses `wikimedia/ip-utils` 6.0.1
from the existing MediaWiki Composer lock. No dependencies are resolved at startup.

The baked policy is `docker/mediawiki/jobchron-policy.json`: three total attempts,
a one-hour ordinary claim timeout, and one day for `DataDumpGenerateJob` and
`webVideoTranscode`. Choose per-type timeouts longer than legitimate job execution;
these are not execution time limits. Recovery provides at-least-once execution and
can repeat work after an interrupted acknowledgement. Review long-running custom
jobs and make their side effects safe to retry. To override the policy, mount a JSON
file into `jobchron` and set its `MW_JOBCHRON_POLICY` to that container path. Keep one
chron replica per stack. Leave `daemonized=true` in the Redis queue configuration.

Run `docker compose logs jobchron` and `docker compose ps jobchron` to inspect it.
The health check requires a successful maintenance pass within 120 seconds, rather
than merely a live process. Repeated Redis failures eventually make it unhealthy;
Docker restarts an exited service, but does not restart it solely for being unhealthy.
Monitor unhealthy status and overdue/claimed/abandoned job counts. Snapshot tooling
stops chron alongside the worker before backing up Redis.
The service explicitly uses `SIGTERM` for shutdown because the PHP-FPM base image's
default `SIGQUIT` is not handled by the chron daemon.

For an existing queue, inspect old claims before first startup: chron can immediately
retry expired claims or abandon those already at the attempt limit. A record's age
alone does not establish that its work is safe to repeat. Keep the previous image
tag for rollback; stop chron to suspend maintenance without deleting queued work.

`JOBCHRON_IMAGE` optionally selects a separate tested PHP image for chron, allowing
an independent rollout while existing web/worker containers retain their images.
It defaults to `MW_IMAGE`. Keep either image pinned in deployment configuration.
After rebuilding the PHP image, start the service with
`docker compose up -d --no-deps jobchron` (Redis must already be healthy). The isolated
acceptance check is `python3 docker/tests/jobchron.py --image YOUR_PHP_IMAGE`; it creates
and removes its own private project/volumes and tests actual MediaWiki NullJobs,
delayed release, claim recovery after SIGKILL, retry exhaustion, Redis restart,
heartbeat failure/recovery and graceful shutdown. It never targets an existing farm.

## Initialization, jobs, and optional services

`init` is explicit and rerunnable. A successful fresh seed is recorded; repeating it does not reset accounts or data. On a partial failure fix the cause and rerun. Ordinary startup never updates schemas. Fresh initialization also persists ManageWiki default permissions and namespaces, so metadata rebuilds preserve edit/upload access. The separate bootstrap config loads extension schemas without requiring a working farm cache. `scripts/farm update` explicitly updates every registered wiki and rebuilds metadata. Take a backup first.

Core and CentralAuth sessions use persistent Redis storage in Docker. Migrating the legacy SQL session backend requires users to sign in again; signing keys and accounts are preserved.

The worker polls the database for non-deleted wikis and processes bounded batches. New wikis join automatically. CreateWiki marks new databases for provisioning; the worker installs their complete baseline and enabled-extension schemas before they serve traffic. Only these explicitly pending creations are initialized automatically. Existing wiki upgrades remain explicit. A single scheduler refreshes metadata hourly; no automatic wiki inactivity/deletion jobs are enabled. For an existing farm, carry over the inventoried production maintenance schedule in `docker/maintenance.json`. A new farm starts with the hourly metadata refresh; add other maintenance jobs deliberately. Each entry specifies a unique `name`, `interval_seconds` (at least 60), an argument-array `command`, and `per_wiki` to append the current wiki. Commands run without a shell. Logs go to `docker compose logs`; inspect service health with `docker compose ps`. A failed init, unhealthy PHP service, repeated worker failures, or growing job queue requires attention.

Search is optional. The bundled CirrusSearch README specifies Elasticsearch 7.10; the profile pins 7.10.2 and is not a recommendation to expose that old service publicly. Enable it only on the internal network:

```sh
MW_SEARCH=1 docker compose --profile dev --profile search up -d
# Run index configuration and initial indexing for every selected wiki:
docker compose exec php php maintenance/run.php /var/www/mediawiki/extensions/CirrusSearch/maintenance/UpdateSearchIndexConfig.php --wiki=testwiki
docker compose exec php php maintenance/run.php /var/www/mediawiki/extensions/CirrusSearch/maintenance/ForceSearchIndex.php --wiki=testwiki --skipLinks --indexOnSkip
docker compose exec php php maintenance/run.php /var/www/mediawiki/extensions/CirrusSearch/maintenance/ForceSearchIndex.php --wiki=testwiki --skipParse
# Backfill schedules index-write jobs; workers must also run with MW_SEARCH=1.
docker compose exec php php maintenance/run.php runJobs --wiki=testwiki --maxjobs=1000 --maxtime=120
```

The optional legacy Elasticsearch image uses AMD64 emulation on ARM hosts. Search rebuilds from the databases; its volume is not the authoritative backup. Remote file repositories are disabled locally unless `MW_REMOTE_REPOSITORIES=1`; global user pages use the local meta wiki. CAPTCHA, analytics, Discord notifications, Cloudflare purging, and production SSL provisioning are disabled locally. Both development and production enable integrations explicitly: `MW_ANALYTICS=1`, `MW_NOTIFICATIONS=1`, `MW_CACHE_PURGE=1`, and `MW_CAPTCHA=1` require their corresponding runtime credentials and services. Matomo also requires its database and deliberately configured grants; it is not provisioned by a default fresh install. SMTP uses `MW_EMAIL_ENABLED`, `MW_SMTP_HOST` and `MW_SMTP_PORT`. Review [extension runtime requirements](EXTENSION-DEPENDENCIES.md) and the manifest inventory: LDAP requires an LDAP server; Math and other optional integrations may require separate services beyond installed PHP packages.

## Backup and restore

Stop web traffic and workers, or use a coordinated write freeze, for a consistent cross-database and uploads snapshot. Each individual database dump uses a transaction; that alone does not make all databases and files atomic.

```sh
scripts/snapshot pre-upgrade
```

Coordinated snapshots contain one SQL file per registered wiki plus `wikidb` and `centralauth`, archives of uploads, generated dumps, Redis, counter data, and Caddy state, a manifest, and a private copy of signing/database/OAuth secrets. The snapshot script stops every writer and restarts services only after success. Copy this directory off-host together with the exact image tags/digests, runtime configuration, original OAuth keys, farm secret key, any externally managed databases such as incidents. Raw `scripts/farm backup` is a lower-level command: stop all writers first and separately preserve signing secrets. Cache files are derived and regenerated.

Restore into a separate Compose project with empty volumes. Supply the matching original runtime secrets first; restore requires empty target databases, uploads, dumps, and restored queue/counter/certificate volumes:

```sh
COMPOSE_PROJECT_NAME=cloudwiki-rehearsal docker compose up -d database memcached
COMPOSE_PROJECT_NAME=cloudwiki-rehearsal scripts/farm restore /backups/pre-upgrade
COMPOSE_PROJECT_NAME=cloudwiki-rehearsal docker compose up -d redis
COMPOSE_PROJECT_NAME=cloudwiki-rehearsal scripts/farm update
```

The uploads archive and SQL files must be trusted operator backups. When moving an existing deployment, export its current data into this manifest layout. A fresh deployment uses explicit initialization instead. Preserve historical/custom database names and their grants.

For Moe-Counter, stop the counter, copy its SQLite database into the counter volume as `count.db`, set ownership to UID 1000, then restart. Copy any SQLite journal/WAL state consistently using SQLite's backup facility or a clean shutdown. Do not copy an actively written raw database.

## Production rehearsal and cutover

For a **new farm**, start production with distinct credentials and empty volumes. For an existing farm upgrade, follow [the coordinated upgrade and recovery runbook](CREATEWIKI-MANAGEWIKI-UPGRADE-RUNBOOK.md) and preserve its existing data and credentials. Choose a domain you control and review the generated environment file before starting the public proxy:

```sh
scripts/farm setup-production --domain YOUR-DOMAIN --env-file .env.production
scripts/farm --env-file .env.production build
scripts/farm --env-file .env.production init-production
# Review public hostnames, ACME contact, DNS, image references and integration settings first.
scripts/farm --env-file .env.production up
```

Setup writes separate `docker/secrets-production` credentials and does not deploy anything. Keep `.env.production` separate from the local `.env`; pass it to every production helper invocation. `init-production` refuses a target with existing databases, uploads or dumps, supports resuming an interrupted initialization with matching settings, and records completion so repeating it preserves accounts and data. Ordinary startup never installs or migrates schemas. Production email starts disabled (`MW_EMAIL_ENABLED=0`); configure SMTP before enabling it. Legacy branding and RequestSSL host provisioning are off for this fresh configuration; Caddy manages the declared public hosts.

For an **existing** deployment, obtain the server version/grants, all database and uploads backups, signing/OAuth keys, web/private-file rules, domain inventory, maintenance schedules and integration settings. Restore those matching credentials and data instead of using `init-production`. Confirm MariaDB compatibility in an isolated restore before cutover. That migration procedure is retained for future moves and disaster recovery; absent historical backups do not block initializing this new farm.

Validate without starting services:

```sh
docker compose --env-file .env.production config --quiet
```

Caddy publishes public 80/443 and issues certificates for explicitly listed hosts. Add newly created wiki domains to the configured host list and recreate web; custom domains also need matching farm metadata and DNS. The implementation deliberately avoids unrestricted on-demand certificate issuance. Ensure hosts resolve to the deployment and inbound HTTP validation can reach port 80.

Rehearse restore, schema updates, cross-domain authentication, private uploads, wiki creation, jobs, dumps, search, and backup restoration before a write freeze. During cutover: stop writes/jobs, take a final coordinated snapshot, restore it, run explicit updates, validate, then switch traffic. Monitor HTTP errors, service health, job backlog, database connections, and free storage.

Rollback before accepting new writes: route traffic back to the original host or restore the exact previous image/configuration and coordinated database/files snapshot. After accepting writes, freeze again and reconcile data before rollback; simply running old code against migrated schemas is unsafe.

## Verification

`docker/tests/smoke.py` covers wiki API routing, missing wikis, sensitive paths, and counter increments. `docker/tests/check-images.sh` checks image exclusions and the counter user. Run the full counter test suite inside the Linux image by mounting `Moe-Counter/test` at `/app/test`. The new real-SQLite test requires a native module matching its runtime; the copied host `node_modules` may be incompatible and is never included in an image.

CI defines checkout completeness, immutable PHP syntax, Elastica, upstream CreateWiki/ManageWiki integration, full isolated catalogue/class-loading and skin asset checks, initialization twice, HTTP/browser and image checks, counter tests, and separate architecture builds. These configured gates still need to pass for the final candidate; workflow configuration is not executed evidence. Optional search testing is a manually selected job. The container shutdown check (`python3 docker/tests/counter-shutdown.py`) verifies that SIGTERM flushes a pending increment and that a replacement container can read it. The Playwright acceptance test (`npm ci --prefix docker/tests`, then `cd docker/tests && npx playwright install chromium && npm test`) checks browser login across all three local wikis, global logout, edits, Lua, uploads, thumbnails, newly created wikis, and anonymous private-file protection. Create fixtures first with `docker compose exec php php maintenance/run.php /opt/farm/test-fixtures.php --wiki=metawiki`. It scopes certificate bypass to the test browser; HTTP smoke tests independently validate the local CA. The job recovery check (`python3 docker/tests/jobs.py`) queues jobs on an existing and newly created wiki, restarts Redis, and verifies that the worker drains them. See [local verification results](VERIFICATION.md). For this new farm, rehearse fresh production initialization and backup/restore before public deployment. Migrating an existing farm additionally requires its actual backups and deployment inventory.

The candidate changes must be committed and pushed for the root GitHub workflow to run. MediaWiki, extensions, skins and bundled dependencies use ordinary tracked sources rather than unpublished embedded Git repositories. See [source preservation](VENDORED-SOURCES.md) and [clean-checkout/AMD64 evidence](UPGRADE-BUILD-VERIFICATION.md).

CI waits for all five local HTTPS hostnames with `python3 docker/tests/wait-https.py` before browser acceptance. Completely empty certificate storage can require an automatic issuance retry. The local SMTP acceptance check mounts `docker/tests/email.php` into the init container and verifies that MediaWiki's message appears in Mailpit; it refuses production or a different SMTP transport. `scripts/farm stop` stops every profile, including Mailpit and optional search.

Implementation references: [Caddy PHP routing](https://caddyserver.com/docs/caddyfile/directives/php_fastcgi), [Caddy local TLS](https://caddyserver.com/docs/caddyfile/directives/tls).

## Docker host storage failure

If Docker reports `read-only file system` or an extraction I/O error, check both the system drive and Docker's disk-image capacity. Source files on an external disk do not move Docker's image storage there. Free system-drive space or move the disk image using Docker Desktop settings, then restart Docker before retrying builds. Never reset Docker or delete volumes to repair this without a separate backup.
