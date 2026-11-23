# Native production and Docker development

Production uses the runtime inventory in `/etc/cloudwiki/runtime.json`. The complete farm, counter, editor, tunnel, and monitoring stack runs as `cloudwiki-*.service` systemd units. Development continues through `scripts/farm` and Docker Compose.

The native runtime uses Ubuntu 26.04 PHP 8.5, MariaDB 11.8, Redis 8, and Node 22 packages. Caddy comes from its signed upstream apt repository. Installed package versions are recorded in `/etc/cloudwiki/package-versions.json`. Runtime package updates are deliberate infrastructure operations, separate from application deployments.

## Deployment

Sign in to `https://admin.cloudwiki.us`, open Deployments, and click Deploy. No separate update check, confirmation dialog, CI success, image publication, or GitHub Actions run is required. The authenticated worker resolves main once, records the commit, fetches source using a short-lived App token, and prepares the release locally. Dependency builds run without that token under the resource-limited build account. Concurrent operations are serialized by the production lock.

Native releases live under `/srv/cloudwiki/releases/<commit>`; `/srv/cloudwiki/current` selects the active release. Application data lives under `/var/lib/cloudwiki-native`, with secrets and service configuration under `/etc/cloudwiki`. The operator checkout and editor remain at `/var/www`. Editing source does not change the active release: publish the selected edits, then Deploy. Configuration drift still requires review.

Deployment preparation leaves the running application untouched. Activation serves maintenance responses, stops writers, snapshots data, changes the release link, runs the updater, and restarts the application. If database work has begun, a failure retains maintenance for a forward fix rather than switching application code over potentially incompatible data. The deployment worker and panel remain independent of the application.

## Migration

The initial migration is explicit and has three phases:

```sh
sudo python3 deploy/native/migrate.py cleanup
sudo python3 deploy/native/install.py --packages --source /var/www
sudo python3 deploy/native/migrate.py stage --commit EXACT_MAIN_COMMIT
sudo python3 deploy/native/migrate.py cutover
```

Cleanup selects only the historical numeric rehearsal volume prefixes after checking every container mount, plus pushed publication clones already ancestral to production HEAD. It does not prune general dangling volumes. The live database is identified from actual mounts, not its name; the chronological database and localization volumes are production data.

Staging creates an isolated MariaDB instance on loopback port 3307. The existing host database on 3306 is untouched. Import application databases from a logical 11.4 export into 11.8, preserving current credentials and creating application grants in the dedicated instance. Do not copy the old mysql system database into the new server.

Cutover places the public wiki in maintenance while admin remains reachable, stops Docker writers and monitoring, transfers final database/Redis/SQLite/filesystem state, and changes HAProxy to the native Caddy listeners. S3 bucket paths and objects are unchanged. Docker images, volumes, and the original data remain intact for seven days; never start both writer stacks.

Before native writes resume, the original Docker stack can be restored from its frozen state. After native writes resume, use forward recovery: starting old database data would lose new writes, and major database/Redis downgrades are not automatic.

The retention timer retires only recorded, stopped migration containers and their unreferenced recorded volumes after seven days and successful health checks. It disables Docker only when no unrelated containers are running. It retains the last two native releases and three successful native snapshots.

## Operations

```sh
sudo scripts/farm preflight
sudo scripts/farm list
sudo scripts/farm snapshot maintenance-backup
sudo journalctl -fu cloudwiki-php
sudo systemctl status cloudwiki-mariadb cloudwiki-redis cloudwiki-worker
```

The native application units use loopback networking. HAProxy retains public ports 80/443; Caddy uses 18088/18443. Counter uses 3001, creation events 8091, and the editor 8085. Grafana and Prometheus retain their existing loopback ports.

Code-server stays available with a 768 MiB ceiling. Builds have a 768 MiB memory ceiling and 80% of one CPU. The editor may restart if an extension exceeds its memory budget. Native service state and application heartbeat health are reported separately in the admin panel. The existing 4 GiB swap remains enabled.
