# CheckUser shared-index routing

Current provisioning implementation: see `SINGLE-PASS-PROVISIONING.md`. The
dated rollout and prototype sections below retain the historical two-pass state
and the measurements that led to promotion of the one-pass path.

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

## Controlled provisioning speed comparison, 2026-10-21

`python3 docker/tests/provisioning-speed.py` benchmarks fresh wikis in a disposable
farm. Each configuration gets a discarded warm-up pair, followed by four measured
pairs with alternating order. No builds or browser suites are run alongside it.
The existing main stack remains running. The runner pins the PHP image for future
runs; this run observed `e49af3300521a828095da7375344a160b973602bc8d1cd41910d1d87bb542692`
on ARM64, MediaWiki 1.46.2 / PHP 8.4.27.

Times use the worker's Redis `finished_ms - started_ms`. They include coordinator
and updater execution, and exclude container startup, queue wait, fixture creation,
metadata preparation and subsequent validation. They are not end-to-end browser
creation times. All 20 fresh fixtures, including four warm-ups, matched normalized
schemas and loaded extensions and passed effective settings and real CheckUser
index-write/global-lookup checks. The disposable containers and volumes were removed.

| Configuration | Two-pass median | One-pass median | Median saving | Two-pass mean | One-pass mean |
| --- | ---: | ---: | ---: | ---: | ---: |
| Public defaults | 1.372 s | 1.012 s | 0.360 s (26.2%) | 1.444 s | 2.326 s |
| Cargo + Linter, uploads disabled | 2.769 s | 2.092 s | 0.677 s (24.4%) | 2.967 s | 2.163 s |

Public samples in measured order were two-pass `[1417, 1316, 1326, 1717]` ms
and one-pass `[912, 1014, 6366, 1010]` ms. Cargo/Linter samples were two-pass
`[2460, 3887, 3077, 2443]` ms and one-pass `[2247, 1937, 1917, 2551]` ms.
The unusually slow public one-pass run is retained: its updater reported 4.4 s
inside a 6.366 s provisioning interval. Its cause was not established. Consequently,
one pass was typically faster but had a worse public-wiki mean in this small sample.
These measurements do not establish a consistent latency improvement.

The two known OAuth update-log marker differences persisted. Main provisioning
continues to use two passes. Resolve the configuration-dependent updater behavior
and investigate the slow sample before promoting the prototype. Raw samples,
validation outcomes and the slow sample's log tail are in
`upgrade-evidence/provisioning-speed.json`. Set `PROVISION_SPEED_SAMPLES` and
`PROVISION_SPEED_VARIANTS` to change sample counts/configurations; the supported
speed configurations are `public` and `schema`.

### Repeat run

A second fresh disposable farm used the same image, warm-up exclusions and four
alternating measured pairs per configuration. All schemas/settings/CheckUser
checks passed again, and containers/volumes were removed after completion.

| Configuration | Two-pass median | One-pass median | Two-pass mean | One-pass mean |
| --- | ---: | ---: | ---: | ---: |
| Public defaults | 1.371 s | 0.862 s | 2.479 s | 0.866 s |
| Cargo + Linter, uploads disabled | 2.331 s | 2.041 s | 3.857 s | 2.086 s |

The public one-pass outlier did not recur: measured samples were
`[932, 914, 809, 808]` ms. However, two-pass samples now had spikes: public
`[1321, 1217, 1421, 5955]` ms and Cargo/Linter `[2333, 2328, 8527, 2239]` ms.
One-pass Cargo/Linter samples were `[2044, 2038, 1929, 2333]` ms. Its discarded
public warm-up took 2.919 s; no measured one-pass run exceeded 2.333 s.

Slow execution is therefore observed with both approaches across these two runs;
its cause remains undetermined. The slow public two-pass updater log reports
4.0 s for bootstrap and 0.3 s for the second pass within the 5.955 s worker
interval. The runner now retains slow-sample log tails before cleanup. See
`upgrade-evidence/provisioning-speed-repeat.json` for every result and diagnostics.
The one-pass prototype remains undeployed.

## OAuth marker investigation

The two OAuth log differences are explained and regression-tested by
`python3 docker/tests/oauth-provisioning.py`. This uses a disposable farm and
does not enable one-pass provisioning on the main stack.

Bootstrap loads `ConfigSettings.php` to populate site configuration, but does not
extract per-wiki globals as normal LocalSettings does. Consequently, bootstrap
keeps `MWOAuthCentralWiki=false` and has no explicit `virtual-oauth` mapping.
OAuth therefore resolves that domain to the new local wiki. Normal settings
extract `MWOAuthCentralWiki=metawiki`; OAuth's services hook maps `virtual-oauth`
to `metawiki`. Its actual persistence code uses that virtual domain.

OAuth registers both timestamp patches using `modifyField` on `virtual-oauth`.
The core updater skips shared virtual domains unless `--doshared` is supplied.
For a local domain, `modifyField` checks the update-log marker but does not check
whether the existing type already matches: it runs the ALTER and records the
marker. CreateWiki already imports OAuth's generated schema, which defines
`oaac_accepted`, `oarc_email_authenticated`, `oarc_registration`, and
`oarc_stage_timestamp` as `BINARY(14)` with the intended nullability.

Thus the first pass applies redundant timestamp ALTERs to local OAuth tables
that normal runtime does not use. The second pass and the one-pass prototype
both leave the shared OAuth database alone during new-wiki creation. The
missing local markers do not represent a missing required schema change for
these fresh wikis.

The regression test confirms:

- Bootstrap resolves OAuth locally; normal runtime resolves it to `metawiki`.
- All three local OAuth table schemas match before and after provisioning in
  both modes; the four timestamp columns have identical types/nullability.
- Two passes add exactly the two markers; one pass adds neither.
- A normal updater rerun leaves the one-pass OAuth state unchanged. Adding only
  bootstrap then adds precisely the two markers, with no schema or central-state
  change. This isolates the cause rather than merely observing a correlation.
- Both modes round-trip OAuth 1 and OAuth 2 consumer and acceptance objects
  through the extension's persistence classes using the runtime-selected central
  connection. Registration, stage, email-authentication and acceptance timestamps
  survive insert/read/update, including a null email-authentication timestamp.
- Central OAuth schemas, update markers and row counts remain unchanged after
  the fixture records are removed. Normalized schema hashes exclude auto-increment
  counters, which can advance during persistence tests.

A read-only check of the main stack confirmed `metawiki` routing, both central
patch markers, and all four columns as `BINARY(14)` with only
`oarc_email_authenticated` nullable. Existing shared-schema upgrades still use
the explicit maintenance path: `manage.php` invokes the updater with
`--doshared`. New-wiki provisioning should not substitute for that operation.

This clears the identified OAuth update-log difference as a reason to retain
two passes for fresh wiki creation. It is not a complete browser authorization
or token-exchange test, nor a claim about every possible extension configuration.
Main provisioning remains two-pass. Evidence is in
`upgrade-evidence/oauth-provisioning.json`.
