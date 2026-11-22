# Numeric wiki IDs

Numeric mode uses identifiers such as `42wiki` for MediaWiki and MariaDB. The farm identity registry associates each ID with its public hostname, previous ID, upload storage key, and realm. The database registry lives in `wikidb.farm_wiki_identity`. The shared farm cache holds a generated `wiki-identities.json` file.

## New wiki requests

With `MW_NUMERIC_WIKI_IDS=1`, a requester enters the desired named subdomain in the ordinary request form. Before approval, run `scripts/farm wiki-id-reserve REQUEST_ID`. The resulting ID is the database name that stock CreateWiki provisions. The reservation changes the request's internal subdomain to the corresponding numeric label for CreateWiki validation. The registry retains the original public hostname. Reload the request queue entry before approving. If the database reservation succeeds but publishing the registry fails, repeat the same command, or run `scripts/farm wiki-id-publish` to republish it.

Automatic approval and direct creation through Special:CreateWiki are disabled in numeric mode. Keep provisioning through the ordinary request queue.

## Existing wikis

Production migration requires the explicit `--production` argument. Keep numeric routing disabled until the production inventory, reference audit, and cutover have been rehearsed on an isolated copy and the off-host database and upload backup has been verified. Hold the production deployment lock across the write freeze, migration, and validation.

1. Restore a snapshot into a separate Docker Compose project with its own volumes, no public services, and the source farm's domain settings. Block outbound services such as mail and use an isolated upload backend.
2. Drain jobs and provisioning. Set `MW_FARM_READ_ONLY=1` and keep `MW_NUMERIC_WIKI_IDS=0`. The read-only flag covers MediaWiki web writes. Stop web, PHP, and background writers before the coordinated snapshot.
3. Save the inventory with `scripts/farm wiki-id-plan > backups/ID_PLAN.json`. Review the exact database and column mappings, including private, closed, and deleted wikis. Take a fresh snapshot with `scripts/farm snapshot --keep-stopped SNAPSHOT_NAME`. Verify the manifest checksums and the off-host backup. For S3-backed uploads, verify a separate object-store backup.
4. Start Redis alone alongside MariaDB and memcached. Run `scripts/farm wiki-id-apply SNAPSHOT_NAME ID_PLAN.json`. It checks the snapshot hashes and plan, clones the wiki schemas, checks table counts, updates the listed database references, and publishes the identity registry. Keep the source schemas until acceptance. A failed import can leave target schemas behind. Reset the rehearsal from the snapshot before repeating it.
5. Set `MW_NUMERIC_WIKI_IDS=1` with read-only still enabled, run `scripts/farm refresh`, and regenerate and verify custom-domain routes. Verify canonical links, SUL login, cross-wiki permissions, private visibility, uploads, and a stock request approval on the copy. Recreate the web services with the selected flags. Reopen writes only after these checks pass.

The plan reports exact matches in candidate database columns. Before a production cutover, audit structured and serialized values, generated interwiki records, search indexes, and any external integrations that store wiki IDs. The command does not rewrite those automatically. A rollback after the central references change requires restoring the coordinated snapshot into clean volumes and recreating services with numeric mode disabled. Merely flipping the flag back leaves the central references pointing at the migrated schemas.
