# Wiki creation updater investigation

On 2026-10-21, temporary read-only profiling mounts measured three fresh local
ARM64 form-created wikis (`9timingef756733`, public/private/public). Both updater
passes, PHP entry/setup, updater sections, individual callbacks and SQL round
trips were timed. All three creation/API privacy checks passed. The normal
provisioner was restored afterward, verified healthy without profiling mounts,
and its pending provisioning backlog was zero. No optimization was deployed.

## Measured work

| Measurement | Bootstrap pass | Normal enabled-extension pass |
| --- | ---: | ---: |
| PHP entry to updater execute | 149 ms | 77 ms |
| Core updater section | 179 ms | 29 ms |
| Extension schema-update section | 383 ms | 98 ms |
| Total child PHP time to shutdown | 788 ms | 238 ms |
| Total observed by coordinator | 837 ms | 304 ms |
| SQL statements | 629 | 370–371 |
| SQL round-trip time, included in sections above | 499 ms | 71 ms |
| CREATE statements | 20 | 4 |
| ALTER statements | 25 | 0 |
| Loaded extensions/skins | 37 | 50 |
| Registered extension updates | 166 | 168 |

SQL timing includes client/server round trips, not just server CPU. The updater
section timings include their SQL time. Other time includes config validation,
Composer checks, hook registration, post-update maintenance and cache cleanup.
The coordinator uses a 100 ms process-status wait, so its wall time includes
completion-detection delay. The first sample's PHP setup was colder than the
later two; these are local samples, not a capacity benchmark.

The first pass does substantial DDL. CentralNotice table installation was one
large contributor (roughly 92–117 ms in these samples); field changes and schema
introspection also contribute. The second pass repeats core and extension checks.
About 360 statements match SQL text from the first pass, but matching text alone
does not prove identical target databases. The evidence stores hashed SQL text,
counts, callers and timings, not raw SQL values.

The second pass is not dispensable: it loads 13 additional extensions/skins and
creates `urlshortcodes` plus three CheckUser `cuci_*` tables in the new wiki.
UrlShortener's schema hook adds two update registrations absent from bootstrap.
The CheckUser behavior exposes the routing mismatch below.

## Configuration mismatch

Read-only maintenance probes on the fresh public fixture resolved
`virtual-checkuser-global` differently:

- Bootstrap: `wikidb`.
- Normal configuration: the individual wiki database.

`docker/mediawiki/bootstrap.php` explicitly supplies the shared mapping.
`mediawiki/LocalSettings.php` attempts to add it via
`$wgConf->settings += [ '+wgVirtualDomainsMapping' => ... ]` after
`ConfigSettings.php` already supplies that top-level key. PHP's array union keeps
the existing value; it does not merge the nested mapping. Consequently the normal
configuration lacks the intended CheckUser global mapping and falls back to the
local wiki. This should be resolved as a separate correctness change before
trying to make the two provisioning configurations interchangeable. No routing
change or table/data migration was performed during this investigation.

## Optimization assessment

Simply deleting the second pass would omit configured schemas. A possible future
implementation is a single provisioning bootstrap that reads the actual per-wiki
extension set and settings, uses consistent virtual-domain routing, and retains
the early setup-hook suppression required before schemas exist. It needs schema
comparison and public/private/configuration-variant acceptance tests. The likely
saving is some of the second process's startup and repeated checks—on the order
of a few tenths of a second in these samples. Required DDL remains.

The built-in `update --initial` option is not a general shortcut for this work:
`MysqlUpdater::getInitialUpdateKeys()` contains six legacy keys, which do not
cover the current core ALTER patches observed here. Composer validation itself
was only a few milliseconds per pass. An AbuseFilter no-op callback also spends
time resolving its localized system-user name before checking its update log;
that is a smaller, separate opportunity rather than the main bottleneck.

Full timestamps, query counts/times, per-callback aggregates, extension lists,
configuration probes, methods and limitations are in
[the evidence JSON](upgrade-evidence/wiki-creation-updater-investigation.json).


The subsequent [CheckUser routing fix](CHECKUSER-ROUTING.md) consolidated existing
indexes, deployed consistent routing and a CLI failure guard, and compared an
isolated one-pass prototype. Production retains two updater passes.
