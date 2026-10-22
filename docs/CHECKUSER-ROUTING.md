# CheckUser shared-index routing

The farm explicitly routes `virtual-checkuser-global` to `wikidb` in Docker
runtime configuration, matching bootstrap. LocalSettings also merges its nested
site-configuration entry directly; array union cannot add an entry below an
already-present `+wgVirtualDomainsMapping` key.

Previously normal runtime fell back to a local wiki database while bootstrap
used `wikidb`. The local result tables (`cu_changes`, etc.) remain local. Only
the central `cuci_user`, `cuci_temp_edit` and `cuci_wiki_map` index belongs in the
shared database.

## Existing data

`php /opt/farm/checkuser-consolidate.php` plans consolidation without writing.
It resolves source map IDs through the source's own `cuci_wiki_map`. For legacy
jobs that wrote a shared ID into a local index, it accepts the shared mapping
only when that name exactly matches the source wiki. Any unresolved reference
aborts the whole plan before writes. This preserves identity when different
local maps reuse the same number.

For an actual cutover:

1. Stop web/PHP, scheduler and provisioner so no new actions/jobs are produced.
2. Let the old worker drain CheckUser jobs. Verify no ready, claimed or delayed
   `checkuserUpdateUserCentralIndexJob` records remain; jobs contain map IDs.
3. Stop the remaining writers and take a coordinated snapshot, retaining the
   previous image and runtime configuration.
4. With writers stopped, run the new image's
   `php /opt/farm/checkuser-consolidate.php --apply --writers-stopped` in the farm
   environment. The tool expects the existing InnoDB index schemas. It writes
   shared map entries and index rows in one transaction, remaps foreign IDs,
   keeps the newest timestamp on conflicts, and verifies coverage before commit.
5. Start all PHP services with the fixed image, then validate shared routing,
   actual CheckUser writes and global lookups, public/private creation and normal
   browser behavior.

The tool does not delete or rewrite source tables. Repeated consolidation is
idempotent and cannot replace a newer shared timestamp with an older source one.
Keep originals and the coordinated backup. After accepting new writes under the
new routing, simply switching back to the old image would redirect indexing to
stale local copies; freeze and reconcile data or restore a matching coordinated
snapshot when rolling back.

The disposable integration check is `python3 docker/tests/provisioning.py`.
It tests dry-run behavior, ID collisions, recoverable orphan IDs, both user and IP
indexes, latest-timestamp preservation, refusal of unknown IDs, source retention
and idempotence. It then creates fresh configuration variants and exercises the
real index job plus the global activity lookup against the shared database.

## One-pass prototype

Production retains both updater passes. `docker/tests/provision-single.php` and
`provision-single-settings.php` are an isolated prototype only. It uses normal
per-wiki configuration through a separate `--conf` entry point, suppresses early
setup hooks while schemas are installed, and invokes the complete updater once.
It is never enabled in the ordinary compose configuration or baked as the
production coordinator.

The equivalence check compares complete normalized SHOW CREATE TABLE hashes,
update-log keys, loaded extensions/skins and effective language/privacy/upload
settings against two-pass controls. It covers public defaults, German/private
configuration, extra extension metadata/settings, and Cargo/Linter schema hooks.
CheckUser writes and global lookup are also verified for each fixture.


## Local rollout results, 2026-10-21

The cutover drained jobs containing legacy map IDs and structurally verified a
coordinated snapshot with 36 SQL dumps plus files/Redis archives. Consolidation
verified all seven existing source index rows in the shared destination. The
original tables remain unchanged, and a later read-only check confirmed complete
identity/timestamp coverage after real writes. The backup, runtime configuration,
image identities and checksums remain at `backups/pre-checkuser-routing-20261021`.
No restore rehearsal was performed for this change.

All five PHP services run the final image. Browser acceptance, HTTP smoke,
read-only preflight, fresh public/private/UI creation, and real central-index
writes/global lookups passed. Final-image fresh checks also verify the URL
shortener schema when that extension is enabled. Both bootstrap and normal
runtime resolve the CheckUser global database to `wikidb`.

An additional CLI guard now exits with status 1 when wiki metadata is missing.
Previously startup printed a missing-wiki message but exited successfully, which
could let a provisioning coordinator acknowledge an updater that never ran.
The guard is tested against missing metadata. Test fixture maintenance now runs
as the worker user, preventing root-owned, unreadable generated metadata.

The one-pass prototype matched complete normalized table schemas, extension sets,
effective settings, and CheckUser writes/lookups for all four configuration
variants. It did **not** have identical update logs: each candidate omitted the
two local OAuth timestamp-patch markers present in the two-pass controls. Both
pipelines produce the same final local schemas in these fixtures. This reflects
additional configuration-dependent updater behavior that must be reviewed before
claiming complete equivalence. The prototype was not promoted; ordinary
provisioning still runs both passes. Timing was affected by local resource load
and is not used to claim a speed improvement.

See `upgrade-evidence/checkuser-routing.json` and
`upgrade-evidence/provisioning-equivalence.json` for the deployed checks and
prototype comparisons. Validation was local ARM64 only.
