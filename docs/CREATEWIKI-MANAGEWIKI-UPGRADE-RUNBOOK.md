# CreateWiki / ManageWiki upgrade and recovery

This runbook covers the coordinated MediaWiki 1.46.2 / PHP 8.4 upgrade. Exact
extension commits, package overrides and base-image digests are in
`upgrade-targets.json`. Production deployment has **not** been performed. Check
`UPGRADE-PROGRESS.md` and the final verification evidence before scheduling one.

## Prepare the release

1. Preserve the complete original source/configuration, current image identities,
   environment files and secrets. Keep private archives and snapshots off Git.
2. Use ordinary tracked sources and locked dependencies as described in
   `VENDORED-SOURCES.md`. Run `python3 scripts/verify-checkout.py`, then build and
   test an exported clean tree. Record its tree/hash and image identities.
   See `UPGRADE-BUILD-VERIFICATION.md` for the clean-clone procedure, AMD64
   commands and recorded build/runtime evidence. A build pass alone does not
   satisfy the catalogue, skin or farm acceptance gates.
3. Require the upstream suite, complete catalogue/skin gates, management/privacy
   checks, fresh/repeated development and production initialization, populated
   upgrade, recovery, browser, jobs, email, dump and architecture checks to pass.
   Keep external integrations disabled in local rehearsals.
4. Restore a recent production snapshot in an isolated environment and run this
   exact candidate against it. Preserve original credentials when restoring;
   fresh-install seeding is a separate operation.
5. Retain the old PHP/web/counter images and matching old configuration. The
   preserved local tags end in `:pre-createwiki-upgrade`. Record immutable IDs;
   a mutable tag alone is insufficient for a future rollback.

## Production maintenance window

Use the reviewed production environment and production Compose configuration.
The examples below assume `.env.production` and the repository's standard
`COMPOSE_FILE` arrangement for that deployment. Keep the selected configuration
consistent across every command.

1. Stop writers and take the coordinated snapshot:

   ```sh
   scripts/farm --env-file .env.production snapshot --keep-stopped pre-createwiki-cutover
   ```

   This stops web, PHP, workers, scheduler, counter and Redis before copying SQL,
   uploads, dumps, queue state, counter state, Caddy data and required secrets.
   Verify completion, snapshot checksums and that writers remain stopped. Copy
   the snapshot, old source/configuration and image identity record off-host.

2. Select the verified immutable candidate images in the production environment.
   Preserve the environment/secrets separately; the snapshot does not replace
   an operator's record of the deployed image/configuration selection.

3. Run the read-only prerequisites before any schema update:

   ```sh
   scripts/farm --env-file .env.production preflight
   ```

   Historical namespace/permission columns and indexes must already exist. If
   they do not, return to the old release and apply its updater before retrying.
   Check the shared metadata, central-authentication, OATH and import domains.
   Do not bypass the preflight or relax extension version requirements.

4. Run the explicit coordinated update:

   ```sh
   scripts/farm --env-file .env.production update
   scripts/farm --env-file .env.production list
   ```

   The updater runs prerequisites, shared/per-wiki migrations and cache refresh.
   Startup itself does not migrate databases. Existing farms use `update`, not
   `init-production`; the latter is guarded fresh-install initialization.

5. Verify the migrated data and regenerated metadata before admitting traffic:
   wiki counts/states, namespaces and groups, extension selections, domains and
   article paths, permissions/privacy, registered-user creation policy, uploads
   and dump paths. Check both public and private wikis and actual authorized administrator access.
   Confirm remote privacy controls require `managewiki-privacy` as well as
   `managewiki-restricted`.

6. Explicitly resume the selected release, then verify HTTPS/API routing,
   login/SSO/logout, editing/Lua/uploads/thumbnails, private API/file denial,
   new-wiki provisioning, Redis-backed jobs and XML/ZIP dump jobs. Monitor PHP,
   worker and database logs. Retain the production two-factor requirement and
   enable SMTP/external integrations only with their reviewed configuration.

## Recover the upgraded release

Restore only into empty destination volumes. Start database and memcached first;
Redis must not have initialized its destination before its snapshot is restored.

```sh
scripts/farm --env-file .env.recovery restore /backups/post-createwiki-upgrade
```

Start the restored Redis service, run `preflight`/`update` explicitly with the
same upgraded release, and verify data before starting application writers.
Rebuild metadata caches through the farm refresh/update command; copied source
directories or a successful SQL import alone do not establish a working farm.
Verify counter and queue state, uploads/dumps and Caddy/secrets in addition to SQL.

## Roll back to the old release

1. Stop upgraded writers and preserve their current state for investigation.
2. Select the preserved old images **and matching old source/configuration**.
   The old runtime expects `MIRAHEZE_WIKI_VERSION=1.45`.
3. Restore the coordinated **pre-upgrade** snapshot into empty recovery volumes.
   Never point old application images at upgraded schemas as a rollback method.
   Restore matching secrets, uploads, dumps, Redis, counter and Caddy state.
4. Start the restored queue and run the **old image's** documented update/cache
   refresh on its matching old data. Do not run the new updater in this project.
5. Verify the old core/PHP identity, metadata, permissions, representative module
   content, uploads, counter state and actual HTTP/authentication behavior.
   Resume writers only after these checks succeed.

The isolated local rollback uses `.env.upgrade-rollback` plus
`docker/tests/upgrade-rollback.compose.yaml`. The upgraded recovery uses its own
`.env.upgrade-recovery`. These private rehearsal environments and reserved-domain
TLS overrides are not production deployment configuration.
