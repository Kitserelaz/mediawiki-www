# Numeric wiki IDs

CloudWiki uses one positive decimal number followed by `wiki` as both the MediaWiki wiki ID and the MariaDB schema name. For example, ID 42 uses `42wiki`. A wiki's public hostname, canonical URL, realm, and upload storage key are separate from this identifier.

## Production cutover record

The migration ran on November 21, 2026 Pacific time, November 22 UTC. Production moved from `c867c8d0e` to `3b602439f9854eeceecc447ac75dbb39c94e40ad` for the migration and then to `d28c4686b08dd8130fc7f91abf179045c7b650da` for the deployment compatibility check. Both commits were made in the live-bind checkout on the production server.

- Migrated 48 registered wikis in the default realm. Each registry entry was verified to have its corresponding numeric MariaDB schema. The legacy schemas were retained for recovery.
- Kept the original public hostnames, canonical URLs, and upload storage keys. The origin API verified `meta.cloudwiki.us` → `24wiki`, `login.cloudwiki.us` → `21wiki`, and `test.cloudwiki.us` → `38wiki`. The production custom-domain check found one active claim and verified that its wiki and canonical URL still matched. Meta's S3 prefix remained `/production/metawiki`.
- Added `MW_NUMERIC_WIKI_IDS=1` to production's `/var/www/.env.production`. The maintenance invocation used `MW_FARM_READ_ONLY=1` and `MW_NUMERIC_WIKI_IDS=0` while importing schemas. The production write freeze used MediaWiki's per-wiki lock files.
- Rebuilt farm metadata in bootstrap mode and again with full runtime configuration. Verified the shared virtual databases, restarted web and PHP, and checked the origin while the write locks were active. Then restarted and checked the worker, provisioner, job maintenance, scheduler, and editor. Removed the 48 migration-created locks and verified that the original public endpoints reported writable again. The production configuration guard accepted the revised local settings and matched its baseline on the final check.
- Installed the compatibility check in `/usr/local/libexec/cloudwiki-deploy-production.py` and committed the same change to the checkout. The check runs before the deployer downloads images or stops traffic. It rejects a candidate missing the numeric identity helper when production has numeric mode enabled.

### Backups and verification

| Item | Record |
| --- | --- |
| Server snapshot | `/var/www/backups/pre-numeric-wiki-ids-20261122T012646Z` |
| Verified off-host copy | `backups/pre-numeric-wiki-ids-20261122T012646Z` on the authorized Mac |
| Exact migration plan on the server | `/var/www/backups/pre-numeric-wiki-ids-20261122T012646Z-input-plan.json` |
| Private inventory on the Mac | `backups/numeric-wiki-ids-production-record-20261122T012646Z` contains the exact JSON plan and the readable mapping |
| Private production maintenance record | `/var/lib/cloudwiki/pre-numeric-wiki-ids-20261122T012646Z` |
| Isolated rehearsal state on the server | `/var/lib/cloudwiki/numeric-id-rehearsal-20261122T012646Z` |
| Development rehearsal artifacts on the Mac | `backups/numeric-id-rehearsal-1121` and `backups/numeric-id-rehearsal-1121.json` |

1. Wrote MediaWiki's `lock_yBgMBwiR` file in each registered wiki's existing local upload directory. Recorded the paths created by this operation in private `locks.json` so reopening would remove only those markers. Stopped the editor and background writers, verified the read-only signal from the Meta API, and stopped web, PHP, the creation-events service, the counter, and Redis for the coordinated snapshot.
2. Used the existing snapshot command to dump the databases and archive local uploads, dumps, Redis, counter data, and Caddy data. The snapshot utility also saved the private farm configuration and OAuth material. Redis, the counter, the creation-events service, PHP, and web came back for public reads while background writers stayed stopped.
3. Backed up S3 separately from the production upload prefix using the bundled AWS SDK. The host AWS CLI failed before it could list the objects. The SDK inventoried and copied 1,210 objects totaling 308,661,933 bytes. Downloads were conditional on the listed ETag, checked against listed sizes, and tested against MD5 where the ETag represented a single-part MD5. The backup recorded a SHA-256 digest for each local copy and required the source inventory to match before and after the copy.
4. Created and verified `SHA256SUMS` covering 1,270 files across the snapshot and S3 backup. Automatic review initially declined the export because the destination had yet to be authorized. After authorization, copied the snapshot over SSH into a private directory on the Mac and verified the same manifest there. The original server manifest was preserved. A separate migration input added the verified digests to a derived manifest and reused the on-server archive and SQL files.
5. Compared 5,566 live tables with the clean restored source copy using MariaDB extended checksums. There were zero differences. Repeated the comparison after stopping PHP for the live cutover and required an exact match before applying the migration. Checked that Redis had no pending dispatch or provisioning entries or nonempty job queue keys.
6. Held `/var/lib/cloudwiki/deploy.lock` across the live schema change and validation. An initial lock attempt exited before applying anything. Retried with a bounded wait and preserved the pre-cutover production environment file, source patch, checksums, and marker list in the private maintenance record. The previous installed deployer was saved there before the compatibility check was installed.

The private plan contains all 48 legacy-to-numeric mappings, original and canonical hostnames, and 11 fully qualified reference locations. Its SHA-256 is `dacebeb56b197b5549273c5b759e6349e6bd46b14712191aaa8286ed0332c7a6`. Keep it with the private backup records.

### Rehearsal and cutover corrections

- On the Mac, reused the existing PHP image without rebuilding and restored a development snapshot in a separate Docker project. Migrated 28 registered wikis. Verified refresh, Meta's original hostname and storage key, and an idempotent reservation that let the stock CreateWiki job create `29wiki` directly while retaining its named hostname. The first development rehearsal exposed the request table location. The reservation and inventory now read `cw_requests` from the central Meta schema.
- On the server, restored the production snapshot in the separate `cloudwiki-numeric-rehearsal-1122` and `cloudwiki-numeric-retry-1122` Docker projects with internally scoped networks, generated rehearsal credentials, separate volumes, and no public web service. Supplied numeric and read-only flags explicitly because the running production Compose definition predated the new flags.
- The first production-copy import stopped on three legacy tables named `Comments_Vote`, `Comments_block`, and `Comments`. Corrected quoting to preserve the spelling of MariaDB catalog identifiers and reran against a fresh restore. The successful rehearsal verified all 48 mappings and upload keys and the active custom-domain claim.
- The production-copy shared-database check exposed a preconfigured CentralNotice route that retained the old Meta name. Added a final alias translation pass across the virtual database map. Verified the shared connections again and confirmed that Meta could boot through its legacy alias while read-only.
- During live startup, the source patch inherited the maintenance script's restrictive file permissions. PHP-FPM could not read the patched runtime. Restored the Git-recorded file modes on the 13 staged files, rechecked the live origins, and committed the migration while the write locks were still in place. Reopened writes only after the route and service checks passed.
- Stopped the isolated rehearsal containers after verification and retained their volumes. Production used the existing container images.

## Source change inventory

| File | Change |
| --- | --- |
| [compose.yaml](../compose.yaml) | Passes `MW_NUMERIC_WIKI_IDS` and `MW_FARM_READ_ONLY` into the shared farm service environment. Their default is `0`. |
| [docker/mediawiki/wiki-identity.php](../docker/mediawiki/wiki-identity.php) | Adds the runtime feature check and loads the shared JSON registry once per request or CLI run. Converts legacy aliases, returns built-in URLs and stable storage keys, and normalizes the request hostname for registry lookup. Numeric mode fails during configuration if the registry file is unavailable. |
| [docker/mediawiki/wiki-identity-admin.php](../docker/mediawiki/wiki-identity-admin.php) | Adds the registry schema, atomic JSON publication, hostname and collision validation, request reservation, deterministic migration planning, reference discovery, queue checks, dump import, and transactional registry and reference updates. Preserves mixed-case SQL table and column names when quoting catalog identifiers. |
| [docker/mediawiki/manage.php](../docker/mediawiki/manage.php) | Registers the four management operations, routes both metadata refresh passes and virtual-domain checks through the numeric Meta alias, and reuses the management advisory lock and elevated database connection for schema import. Production apply requires `--production`. Fresh backups store SHA-256 digests in `manifest.json`. |
| [docker/mediawiki/bootstrap.php](../docker/mediawiki/bootstrap.php) | Resolves `MW_BOOTSTRAP_DB` through the registry, uses the built-in public URL, maps the Meta/Login/Test bootstrap list, and points the Meta-hosted CreateWiki, ManageWiki, and ImportDump virtual connections at the numeric schema. |
| [docker/mediawiki/runtime.php](../docker/mediawiki/runtime.php) | Loads the identity helper, sets web read-only mode when requested, copies per-wiki configuration selectors to numeric aliases including `+` and `-` selectors, translates chosen central-auth, AbuseFilter, GlobalUserPage, OAuth, interwiki, virtual-database, and shared-upload settings, and resolves automatic login hosts and central virtual connections. Prefers current canonical URL metadata and uses the built-in registry URL as fallback. Uses the stable upload directory, disables automated approval, removes direct creation, and requires a matching reservation for the approval form. |
| [docker/mediawiki/verify-domains.php](../docker/mediawiki/verify-domains.php) | Validates virtual database connections against the resolved identity of the expected schema. |
| [mediawiki/SkyWikiFunctions.php](../mediawiki/SkyWikiFunctions.php) | Uses registry realms for numeric wikis, adds built-in registry URLs when wiki-list metadata lacks a URL, routes original hosts to numeric IDs, and resolves legacy CLI, custom-route, shared-auth path, and central database identifiers. |
| [mediawiki/S3Settings.php](../mediawiki/S3Settings.php) | Builds upload prefixes with the stable storage key, preserving existing object locations for MediaWiki files and the avatar backend. |
| [mediawiki/LocalSettings.php](../mediawiki/LocalSettings.php) | Points the development-only Test Wiki PortableInfobox condition at the resolved Test identity. |
| [mediawiki/extensions/SkyWikiMagic/includes/CustomDomainConfig.php](../mediawiki/extensions/SkyWikiMagic/includes/CustomDomainConfig.php) | Resolves named farm slugs to numeric IDs and retrieves the original farm slug from the registry when constructing custom-domain redirects or management targets. |
| [scripts/farm](../scripts/farm) | Exposes `wiki-id-plan`, `wiki-id-apply`, `wiki-id-reserve`, and `wiki-id-publish` as one-off management container commands. Apply checks for running services and permits MariaDB, Redis, memcached, and the Cloudflare tunnel. |
| [scripts/deploy-production.py](../scripts/deploy-production.py) | Checks whether the candidate commit contains the identity helper when production is in numeric mode. |
| [docs/NUMERIC-WIKI-IDS.md](NUMERIC-WIKI-IDS.md) | Adds the operator runbook, cutover record, and change inventory. |

Stock CreateWiki still creates the schema and provisions the wiki through its existing request workflow. The migration involved no CreateWiki source patch, replacement creation handler, Caddy configuration change, or runtime image rebuild.

## Database and registry changes

The new InnoDB table `wikidb.farm_wiki_identity` has an auto-increment unsigned numeric primary key, unique optional legacy and request IDs, a unique original hostname, an optional unique built-in farm hostname, a storage key, a realm, and a `reserved` or `active` state. Existing identities were inserted as `active` with the legacy ID as their storage key. A new reservation begins in `reserved` with a numeric storage key. Wiki availability continues to come from the farm's ordinary wiki registry and provisioning checks.

The generated `/var/www/mediawiki/cw_cache/wiki-identities.json` contains indexes by wiki ID, farm hostname, and legacy alias. The writer validates hostname conflicts and publishes it via a temporary file and rename. Farm hostname aliases include the original named address and the numeric label under the realm domain. Verified external custom domains continue to route through the existing custom-domain registry. Current wiki URL metadata takes precedence over the built-in fallback when the canonical address changes.

The migration sorts the legacy registry, chooses unoccupied numeric IDs while considering existing schemas, hostnames, and pending requests, and rechecks the reviewed JSON plan at execution. It verifies the required snapshot digests, imports each source dump into a new schema with the source character set and collation, and verifies the table list and row counts. The planned identifier updates and identity rows commit in one transaction after schema imports. The production plan matched these columns.

| Area | Columns updated |
| --- | --- |
| Wiki registry | `cw_wikis.wiki_dbname` |
| ManageWiki metadata | `mw_namespaces.ns_dbname`, `mw_permissions.perm_dbname`, `mw_settings.s_dbname` |
| Custom domains | `cw_custom_domain_claims.cdc_wiki`, `cw_custom_domain_state.cd_wiki` |
| CentralAuth | `globaluser.gu_home_db`, `localnames.ln_wiki`, `localuser.lu_wiki` |
| Cross-wiki administration | `cuci_wiki_map.ciwm_wiki`, `globalblocks.gb_by_wiki` |

The audit found zero exact legacy IDs in structured data in `cw_wikis.wiki_extra`, `mw_settings.s_settings`, `mw_settings.s_extensions`, or JSON-valid `cw_requests.cw_extra` and `cw_requests.cw_comment`. CentralAuth's `wikiset` table contained zero sets. The migration scanner checks exact stored values in candidate columns and reports its scope in the plan. Structured or serialized fields with wiki IDs, generated interwiki records, search indexes, and integrations that persist internal IDs require their own review in future migrations.

## Provision a new wiki

Have the requester enter the desired named subdomain in the ordinary request form. Reserve an ID before staff approval.

```sh
cd /var/www
sudo scripts/farm --env-file .env.production wiki-id-reserve REQUEST_ID
```

The command locks the open request row in Meta's `cw_requests`, assigns an available numeric ID and hostname, updates `cw_dbname` and the internal `cw_url` used by CreateWiki validation, and republishes the identity registry. It prints the reserved wiki ID. The public named subdomain stays in the registry. Use this command on the primary realm for requests with a named farm subdomain. Reload the request queue entry and approve through the ordinary interface. A request without a matching reservation stays in the queue without an approval action.

If the reservation commits but publication fails, repeat the reservation for the same open request or republish the registry.

```sh
sudo scripts/farm --env-file .env.production wiki-id-publish
```

## Rehearse and recover

1. Restore a verified snapshot in an isolated project with its own volumes and the source farm's domain settings. Use an isolated upload backend and disable outbound effects. Run `scripts/farm wiki-id-plan > backups/PLAN.json` and review the wiki and reference lists.
2. Drain provisioning and jobs, put the farm in read-only mode, and stop web and background writers before taking a coordinated snapshot with `scripts/farm snapshot --keep-stopped SNAPSHOT_NAME`. Verify an off-host copy and separately back up S3 if enabled.
3. Start Redis alongside MariaDB and memcached with public traffic off. With `MW_FARM_READ_ONLY=1` and `MW_NUMERIC_WIKI_IDS=0`, run `scripts/farm wiki-id-apply SNAPSHOT_NAME PLAN.json`. In production use the appropriate environment file, hold the deployment lock, and add `--production`. The plan filename is relative to `/backups` inside the management container.
4. Enable numeric mode with writes held, run `scripts/farm refresh`, verify the shared databases and routes, and recreate the web runtime. Verify canonical links, custom-domain links, authentication, cross-wiki permissions, private visibility, uploads, and stock request approval in rehearsal. Reopen production writes after validating the running application.
5. If an import fails partway, temporary numeric schemas may exist even though the reference transaction did not commit. Restart rehearsal from a clean restore. For a production rollback after the reference transaction, freeze writes and restore the coordinated database and volume snapshot into clean volumes together with the appropriate application source and configuration. The old per-wiki schemas alone cannot restore shared registry references. If production has accepted new writes, reconcile them before restoring the earlier snapshot.
