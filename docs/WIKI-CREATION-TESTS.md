# Wiki creation tests

Run on 2026-10-21 against the local MediaWiki 1.46.2 / PHP 8.4.27 / APCu 5.1.28
stack. Fresh wiki names were used so creation was exercised instead of skipped
because smoke fixtures already existed. No production environment was used.

## Failure found and fixed

The first run created public/private wiki records and submitted browser-form
creation, but both readiness tests failed. The worker repeatedly reported
`Invalid wiki identifier`. A valid existing wiki, `26oct21testwiki`, began with a
digit. CreateWiki accepts such names, while the farm's creation hook, worker,
provisioner, and management identifier helper required a letter first. The
worker's exception also aborted the entire sweep, blocking unrelated new wikis.

All four farm guards now accept an initial lowercase letter or digit, retaining
the existing length/character restrictions and quoted SQL identifiers. The
worker logs and skips any malformed metadata row instead of abandoning the
whole sweep. Fixture prefixes now also permit digits. The PHP and web images
were rebuilt; PHP services and web were recreated. No schema migration was
needed for this fix.

## Passing results after the fix

The repeatable regression runner is `docker/tests/creation.py`. It generates a
fresh prefix beginning with a digit and creates three wikis: public and private
through CreateWiki's service, and a third through `Special:CreateWiki` in the
browser. The browser checks passed in 37.6 seconds (2 tests). They cover login,
cross-wiki sessions/logout, creation-form success, public API readiness,
private API/file access protection, and completed worker provisioning. The
runner additionally verifies all three pending provisioning markers disappear.

Five additional browser validation/access checks passed:

- Duplicate database name rejected.
- Missing database suffix rejected.
- Non-alphanumeric name rejected.
- Uppercase name rejected.
- Anonymous visitors have no creation form or `createwiki` right.

Queue durability/discovery passed for `testwiki` and the fresh digit-prefixed
public wiki: Redis restart retained enqueued jobs and the worker drained both
queues. Management preflight accepted all current identifiers. HTTPS routing,
private-path/counter smoke checks and the cross-request APCu probe passed.
All five changed PHP files passed syntax checks.

The initial prefix `creationfd085e4e` and regression prefix `9creation384630b6`
each created three fixture wikis. All six are now registered, active, and fully
provisioned, including those originally blocked by the worker. They remain in
the local farm for inspection. Exact names, privacy flags, final image IDs, and
results are recorded in `upgrade-evidence/wiki-creation.json`.

## Repeat

With the local stack running and Playwright Chromium installed:

```sh
python3 docker/tests/creation.py
```

If using the temporary browser installation from this session:

```sh
PLAYWRIGHT_BROWSERS_PATH=/tmp/php-8427-playwright python3 docker/tests/creation.py
```

Each invocation creates a new set of inspectable test wikis. The runner refuses
non-localhost or production configurations. The five form-validation checks in
this report were additional controlled Playwright checks; the repeatable runner
covers fresh creation and provisioning through the existing browser suite.

## Creation timing

Three fresh wikis were created sequentially through the administrator form on
2026-10-21. Timing starts at submit; login and form preparation are excluded.
Completion requires the worker's pending provisioning marker to disappear and
the new wiki's API to return public siteinfo or expected private-wiki denial.

| Sample | Form success | Worker queue wait | Worker provisioning | Verified ready from submit |
| --- | ---: | ---: | ---: | ---: |
| Public 1 | 1.29 s | 7.88 s | 1.54 s | 11.07 s |
| Private | 0.93 s | 6.34 s | 1.16 s | 8.75 s |
| Public 2 | 1.28 s | 6.08 s | 1.29 s | 8.98 s |

Mean verified readiness was 9.60 seconds. Queue wait starts when the creation
hook writes the pending marker, so these columns are not all additive. Worker
provisioning includes observation delay: checks run every 500 ms, plus a Docker
probe round trip. The final API request adds verification time after completion.

In this baseline, most delay came from the prior worker scanning registered
wikis and sleeping between sweeps. The observed updater/provisioning phase took
about 1.2–1.5 seconds. The Streams deployment below replaces that dispatch path.

This is three samples under current local conditions, not a concurrency or
capacity test. The new fixtures use prefix `9timingdcda345f` and remain inspectable.
Exact samples and methodology are in `upgrade-evidence/wiki-creation-timing.json`.
Repeat with:

```sh
PLAYWRIGHT_BROWSERS_PATH=/tmp/php-8427-playwright node docker/tests/creation-timing.cjs
```


## Redis Streams deployment, 2026-10-21

The main local stack now has separate blocking consumers for ordinary jobs and
wiki provisioning. Fresh digit-prefixed public, private and UI creation passed;
all provisioning signals and markers cleared. The main queue also drained after
a Redis restart. See [STREAM-DISPATCH.md](STREAM-DISPATCH.md) for delivery semantics,
the 13 isolated lifecycle checks, timed maintenance and rollback instructions.

Three fresh form submissions with prefix `9timing386c7493` measured:

| Sample | Form success | Provisioning queue wait | Verified ready from submit |
| --- | ---: | ---: | ---: |
| Public 1 | 0.850 s | 2 ms | 4.079 s |
| Private | 0.795 s | 3 ms | 3.138 s |
| Public 2 | 1.226 s | 2 ms | 4.085 s |

Mean verified readiness fell from 9.60 s to 3.77 s (60.8% less elapsed time).
Queue wait uses Redis event and consumer-start timestamps. Provisioning can begin
before the form response finishes. Completion is observed every 500 ms through
a Docker probe, then checked through the new wiki's API; these totals include
observation and API overhead. This is three sequential local samples, not a
capacity benchmark. The fixtures remain inspectable. Exact results are in
`upgrade-evidence/stream-dispatch-creation-timing.json`, with complete validation
in `upgrade-evidence/stream-dispatch.json`.


## Individual provisioning stages, 2026-10-21

A later three-sample run used temporary monotonic timers around the real updater
subprocesses. The normal provisioner configuration was restored afterward.

| Stage | Mean |
| --- | ---: |
| Submit to queued provisioning | 735 ms |
| Stream queue wait | 2.7 ms |
| Bootstrap updater pass | 1,006 ms |
| Enabled-extension updater pass | 338 ms |
| Coordinator startup/lock and worker launch/completion overhead | 106 ms |
| Actual provisioning completed after submit | 2,188 ms |
| Completion observation plus API verification | 715 ms |
| Verified ready after submit | 2,902 ms |

No extra metadata-preparation process was needed in these samples. Per-wiki
actual completion was 2.00–2.28 seconds; verified readiness was 2.83–2.98 seconds.
The updater durations include child PHP startup and the existing 100 ms
process-status completion detection; they do not isolate SQL execution. This
run reflects its own cache/load conditions, not another performance change.
The fresh prefix was `9timingf8143acb`. Full measurements and method are in
`upgrade-evidence/wiki-creation-stage-timing.json`. The timing runner now records
actual Redis completion timestamps separately from observation/API overhead.
