# Performance audit — October 20, 2026

## Scope and evidence

Reviewed the custom MediaWiki farm bootstrap/configuration, Docker/FPM/Caddy setup, job scheduling, and Moe-Counter request/storage/render paths. This is a focused audit of the integration and custom hot paths, not an exhaustive review of MediaWiki core and all 333 vendored extensions.

No application source or configuration was changed. Isolated checks used networking-disabled containers without farm data mounts. All 13 counter tests passed against the current source using the existing Linux counter image’s matching dependencies. Host dependencies initially failed to load the native SQLite binary; that was an environment mismatch, not a failing application assertion.

The default project initially had only database/cache services running. Following the request to start it again, its existing PHP, worker, scheduler, counter and Mailpit containers were started. Its web container could not start because `cloudwiki-upgrade-restore1462-web-1` already owns loopback ports 80/443. That existing upgrade-restore site responds at `https://test.localhost/wiki/Main_Page`: HTTP 200, 282 ms TTFB and 283 ms total in one local request. This is an availability check, not a load benchmark; that stack uses the AMD64 `upgrade-clean-20261018-amd64` image and does not establish current-checkout performance.

## Findings

### 1. P1: Redis job lifecycle service is missing — remediated locally

**References:** `docker/mediawiki/runtime.php:81`, `compose.yaml`, `docker/mediawiki/worker.php:22`, `mediawiki/includes/JobQueue/JobQueueRedis.php:51–53,90–104,279–283,351–389`.

**Remediation, October 20:** added a separate `jobchron` Compose service using the unmodified upstream chron subset pinned at `09f30782077b6101871fa95715f7181391d20486`, the farm's locked IPUtils dependency, validated per-type retry/claim policies and a successful-pass heartbeat. Snapshot tooling now stops/restarts chron with the other writers. Compose explicitly sends SIGTERM; the lifecycle test caught the FPM image's inherited SIGQUIT default, which the daemon does not handle.

Built `cloudwiki-php:jobchron-20261020` (local image ID `sha256:bd35efd79c21c32a2ecc948f1e0b3ab80a23cbff55416699a03d2ebd037294e4`). The isolated real-MediaWiki suite `python3 docker/tests/jobchron.py --image cloudwiki-php:jobchron-20261020` passed delayed NullJob execution, recovery after killing a claimant, attempt exhaustion, Redis restart, stale-heartbeat detection during a Redis outage/reconnection, and clean SIGTERM exit. Full image syntax checks passed for 33,847 files with one existing, hash-verified version guard. Checkout/provenance and Compose validation also passed. CI now includes the lifecycle suite; a remote CI run and additional architecture builds have not been performed here.

Started chron in both local `cloudwiki` and `cloudwiki-upgrade-restore1462` stacks using persisted `JOBCHRON_IMAGE` overrides, without replacing their existing PHP/web/worker images. Both old claims were DataDump jobs with missing database records; the running job implementations explicitly return success in that case. After normal recovery/execution, both stacks reported zero ready, claimed, delayed or abandoned jobs. The local wiki still returned HTTP 200. This is a local rollout, not a production deployment. See [operations and policy](DOCKER.md#redis-queue-maintenance).

The following describes the original finding before remediation.

The farm selects `JobQueueRedis` with `daemonized=true`, but Compose starts only the per-wiki `runJobs` loop. There is no redisJobRunnerService or equivalent queue-maintenance service in the supplied stack. This version of the queue requires daemonized mode and delegates delayed-job release, claimed-job recycling and queue-state pruning to that external service. Its pop operation reads only the ready list; it does not release due items from `z-delayed` or reclaim entries in `z-claimed`.

**Impact:** delayed jobs can remain pending indefinitely, while jobs interrupted after claim can remain stranded instead of being retried. This affects eventual work completion, beyond the queue latency issue below. Simply setting `daemonized=false` is not a fix: the constructor explicitly rejects it in this MediaWiki version.

**Evidence:** exact `pushBlobs` and `popAndAcquireBlob` Lua from the checkout was executed against a disposable, network-isolated Redis container. After a delayed job became due, pop returned no job and the overdue sorted-set entry remained. An immediate control job was claimable, but without acknowledgement a later pop left its claimed entry in place. This tests the storage operations rather than the full MediaWiki lifecycle; repository inspection establishes the absent maintenance component. See [queue evidence](performance-audit-queue-sample.json).

Read-only inspection of the live restore Redis instance found zero ready/delayed jobs and one claimed entry; a subsequent age check put its claim at 261,668 seconds old (about 72.7 hours). Its payload and origin were not inspected, so it could be restored fixture state; the count alone does not prove a current worker crash. No live queue entries were changed. Existing `docker/tests/jobs.php` / `jobs.py` cover immediate NullJobs and Redis durability, not delayed release or claim recovery.

**Action:** deploy and configure the compatible queue-maintenance/jobrunner service, or select a supported backend with a complete lifecycle implementation. Choose claim timeout/retry behavior explicitly and avoid duplicate execution assumptions. Add isolated acceptance checks for a delayed NullJob and a worker terminated after claim, including eventual recovery and cleanup.

### 2. P2: Docker requests bypass the derived configuration cache

**References:** `mediawiki/SkyWikiFunctions.php:582–587`, `mediawiki/LocalSettings.php:204–208`, `docker/mediawiki/runtime.php:42–49`.

Whenever `MW_DOCKER` is set, including production, `getConfigGlobals()` returns before the mtime-validated configuration-cache path. It transforms the ManageWiki cache and runs `SiteConfiguration::getAll()` on every PHP request. `farmSiteConfiguration()` is called both immediately before `getConfigGlobals()` and again inside it; each call reads the database list and builds the entire farm’s server map. API, ResourceLoader and authenticated file requests also execute configuration setup.

**Impact:** repeated CPU and allocations before useful request work, growing with farm size and settings count. OPcache can cache bytecode, but does not cache these computed results across requests. No end-to-end speedup is claimed without profiling.

**Action:** introduce a Docker-aware derived configuration cache with keys/invalidation covering the selected wiki, ManageWiki metadata, farm database list, configuration code and environment-dependent values. Consolidate the duplicate setup only after accounting for its ordering around merged settings. Verify changes to wiki domains/settings and private-wiki state become visible correctly.

### 3. P2: Every job queue competes for one sequential worker

**References:** `docker/mediawiki/worker.php:12–27`, `docker/mediawiki/runtime.php:23`, `compose.yaml` worker service.

One worker selects every live wiki in name order, starts a separate PHP/MediaWiki process per wiki, and waits for each process to finish. Each batch allows only 20 jobs or nominally 10 seconds, then the farm waits another five seconds after the full sweep. Empty queues still incur process/bootstrap work. Provisioning is also performed inline before queue processing.

**Impact:** heavy work on one wiki delays all later wikis, and latency increases with wiki count. For example, 100 batches each consuming 10 seconds imply about 1,005 seconds between sweeps, before startup overhead. This is illustrative arithmetic, not a measured workload or a strict upper bound: an individual job can run longer than the batch time check. With HTTP job execution disabled, background freshness depends on this runner.

**Action:** use bounded concurrency and wiki sharding or queue-aware dispatch, skip known-empty queues, and isolate long provisioning/dump work from ordinary jobs. Preserve signal handling and prevent accidental duplicate provisioning when scaling workers. Measure queue age as well as jobs per second.

### 4. P2: Counter requests serialize globally and synchronously commit each visit

**References:** `Moe-Counter/utils/counter-store.js:6–10,25–35`, `Moe-Counter/db/sqlite.js:22–66`, `compose.yaml:147`.

One promise chain serializes every counter name, including read-only record requests. Compose sets `DB_INTERVAL=0`, so each increment performs a read followed by an immediate flush. SQLite prepares statements repeatedly and uses synchronous calls; wrapping those calls in promises does not move their work off the Node event loop. A slow database operation delays unrelated counter names too.

**Evidence:** a synthetic run of 100 concurrent visits to one counter, with an artificial 2 ms delay per database read/write, took 469 ms with immediate persistence (100 reads + 100 writes). With the existing batching option it took 5 ms including final flush (one read + one write). These numbers demonstrate operation serialization and batching behavior, not real SQLite throughput or a production speedup prediction.

**Action:** cache prepared SQLite statements, benchmark actual transaction latency, and consider an atomic increment operation. For asynchronous backends, serialize per counter rather than across all names. Batching is available but changes crash durability; do not simply enable it without choosing an acceptable loss window. Keep the existing concurrency and shutdown tests.

### 5. P2, MongoDB deployments: Counter lookup field has no declared index

**References:** `Moe-Counter/db/mongodb.js:5–11,19–20,35–47`.

Both reads and upserts filter by `name`, but the schema declares no index on that field. A fresh deployment therefore has no application-defined lookup index. Existing externally managed indexes were not inspected; MongoDB is not the default backend.

**Impact:** without an external index, lookup/update work grows with collection size and compounds the global counter queue bottleneck.

**Action:** inspect existing indexes and duplicate names, then add a unique `name` index through a controlled migration. Confirm the query plan uses it. Multi-process atomic-increment correctness should be addressed separately before scaling this service horizontally.

### 6. P2: WikiDiscover descriptions cause one extra query per result

**References:** `mediawiki/extensions/WikiDiscover/includes/Api/ApiQueryWikiDiscover.php:179–181`, `mediawiki/extensions/WikiDiscover/includes/WikiDiscoverWikisPager.php:103–106`, `mediawiki/extensions/CreateWiki/includes/Services/RemoteWikiFactory.php:20–29`, `mediawiki/extensions/CreateWiki/includes/Helpers/RemoteWiki.php:84–108`.

The API and pager already fetch a list from `cw_wikis`, but descriptions construct a new `RemoteWiki` for each result. Each constructor performs another `SELECT * FROM cw_wikis WHERE wiki_dbname=...` and decodes `wiki_extra`. The factory does not memoize instances. An N-row description response therefore adds N point queries to its listing work, even though the description can be selected from the same row initially. The web pager path is conditional on `WikiDiscoverUseDescriptions`; API callers can explicitly request `wdprop=description`.

**Evidence:** source hashes for these paths match the active restore image. Small serial samples returned median TTFB of 32.17 ms for the default listing and 40.61 ms with `sitename|description`. These requests select different properties and cover only 14 wikis, so that timing difference is descriptive, not an isolated measurement of N+1 overhead.

**Action:** select `wiki_extra` only when descriptions are requested, and decode its description in the existing result loop, or batch-load descriptions. Verify null/empty-description behavior and response permissions. Request-local factory caching alone will not eliminate queries for distinct wikis.

### 7. P2 at farm scale: WikiDiscover sorts without supporting indexes

**References:** `mediawiki/extensions/WikiDiscover/includes/WikiDiscoverWikisPager.php:124–203,207–208`, `mediawiki/extensions/WikiDiscover/includes/Specials/SpecialRandomWiki.php:118–131`, `mediawiki/extensions/CreateWiki/sql/cw_wikis.sql:23–26`.

The pager defaults to sorting on `wiki_creation`, but the supplied table indexes cover only `wiki_dbname` and `wiki_dbcluster`. Filtered creation-order listings have no matching language/category/creation index either. RandomWiki uses `ORDER BY RAND() LIMIT 1`, which still evaluates the eligible population rather than seeking directly to one indexed result.

**Evidence:** live `SHOW INDEX` confirms those columns. Equivalent default and language-filtered listing queries explain as `type=ALL` with `Using filesort`; the random query additionally reports `Using temporary`. The live table contains only 14 rows (the optimizer estimates 12), so this is a growth risk, not evidence of a currently slow SQL query. A full scan can be optimal for a table this small.

**Action:** test realistic farm-size fixtures and common filters before adding indexes. Start by evaluating an index on `(wiki_creation, wiki_dbname)` and targeted filter/order indexes; verify the actual pager continuation/order with `EXPLAIN`. Random selection needs a sampling strategy or maintained eligible-ID structure, not just another ordinary index. Preserve eligibility rules and assess sampling bias.

### 8. P2 on uncached parsing: Wiki statistics functions scan all settings for each call

**References:** `mediawiki/extensions/WikiDiscover/includes/HookHandlers/Main.php:269–309`.

`numberofwikisbysetting` reads every row's `s_settings` or `s_extensions` from `mw_settings`, with no WHERE restriction or limit. The settings path decodes every JSON document; the extension path concatenates the full payload. This work repeats for each function invocation, with no local memoization, shared result cache, or expensive-parser-function budget in this handler. Templates can therefore multiply farm-wide database reads and PHP allocations during a single parse.

**Impact:** proportional to total stored settings bytes times invocation count, affecting cache misses, preview and reparsing. Parser-cache hits can avoid this work, so it should not be described as occurring on every page view. No large-data parse benchmark was run.

**Action:** precompute/cache aggregate counts with ManageWiki-change invalidation, memoize identical arguments within one parse, and apply an expensive-function budget. For many distinct statistics, load/decode once per parse or build an aggregate index rather than scanning separately. Preserve existing matching semantics when replacing the implementation.

## Capacity observations requiring workload measurements

- An isolated `cloudwiki-php:upgrade-1462-candidate3` container reports FPM `pm=dynamic`, `pm.max_children=5`, `pm.start_servers=2`, `pm.max_spare_servers=3`. The Dockerfile provides no pool override, and production Compose supplies none. Five busy PHP requests occupy that image’s pool; determine an appropriate size from worker RSS, available memory, CPU, database capacity and queueing latency. Raising the count blindly can worsen contention. The active restore FPM pool was subsequently checked and also has `pm.max_children=5`; no FPM listen-queue instrumentation was enabled, so request queueing is not proven by the latency sample.
- That PHP image reports OPcache enabled, 128 MiB memory and 10,000 accelerated files. Inspect saturation, misses and restarts under representative extension combinations before changing limits.
- Caddy sends public wiki images through authenticated PHP handling. This protects private uploads but shares scarce FPM capacity with page/API traffic. Consider an authorization-preserving file-offload design if file traffic occupies the pool; never bypass private-wiki authorization to improve throughput.
- Counter SVGs embed base64 image data. Across 55 themes, the largest 10-digit sample was 263,790 bytes raw / 196,615 bytes gzip (`yousa-ling`). This is payload-size evidence, not proof rendering is CPU-bound. Dynamic visit images deliberately disable caching; avoid caching away visits. The homepage also compiles Less in the browser (`Moe-Counter/views/index.pug:9–10`); compiling CSS during the build would remove that client work.

## Live observations from the continued audit

The sample used anonymous GETs against the existing AMD64 restore stack, one warm-up and eight serial requests per endpoint, plus 15 Main_Page requests with concurrency capped at five. Each curl used a separate connection. All responses were HTTP 200. These are local observations with other stacks running, not sustained capacity tests or reliable tail-percentile estimates. Raw aggregates are in [HTTP sample](performance-audit-http-sample.json); [source hashes](performance-audit-source-check.json) confirm six audited WikiDiscover/CreateWiki/queue files match that image.

| Endpoint | Serial median TTFB | Slowest of eight |
| --- | ---: | ---: |
| Main_Page | 58.04 ms | 215.03 ms |
| API siteinfo | 27.30 ms | 48.38 ms |
| WikiDiscover default | 32.17 ms | 48.19 ms |
| WikiDiscover sitename + description | 40.61 ms | 53.93 ms |
| Static logo | 5.59 ms | 6.75 ms |

Main_Page at concurrency five returned median 161.69 ms and maximum 263.36 ms TTFB; 15 requests completed in 0.650 seconds. This indicates latency rises under concurrent local requests, but does not isolate FPM, CPU, emulation or database contention as the cause. No heavier load was applied.

Aggregate cache observations: Memcached held about 248 KiB of its 128 MiB allocation, with 8,899 hits / 1,327 misses and zero evictions over its current uptime. That is about an 87% cumulative hit rate, not an endpoint-specific measurement. Redis held about 1.05 MiB, with zero evictions and no configured maxmemory limit. Frequent Redis misses can be normal empty-queue polling; they are not evidence of broken caching by themselves. MariaDB had a 128 MiB buffer pool, slow-query logging disabled, and `long_query_time=10`; these settings do not establish a memory shortage. The zero slow-query counter does not exclude application-relevant queries below that threshold.

A later resource snapshot showed restore PHP at 204.8 MiB / 4.17% CPU, worker at 51.7 MiB / 0.02%, and database at 234.1 MiB / 0.26%. This was after the latency sample and is not peak RSS or a resource-capacity recommendation.

Reviewed but not promoted to findings: the all-key cache deletion helpers in SkyWikiMagic have no callers in that class, and configured DataDump limits cap normal XML/ZIP retention at one each. These should not be presented as proven hot paths or an unbounded dump history in this configuration. Likewise, `fetchRowCount()` delegates counting to the database; it does not fetch every result row into PHP merely because the builder selects `*`.

## Follow-up measurement plan

1. Use a selected isolated stack with a known source/image revision and representative wiki data. Record architecture; do not compare emulated and native runs as equivalent.
2. Profile warm and cold anonymous pages, authenticated pages, API reads, ResourceLoader, image delivery and template-heavy rendering. Collect p50/p95/p99 latency, PHP CPU/RSS and database query time.
3. Inspect FPM active/idle workers and listen queue, OPcache hit/saturation statistics, Memcached hit/eviction rates and Redis queue age.
4. Load counter traffic with both repeated and distinct names, measuring real SQLite commit time, event-loop delay and memory. Compare optimizations while retaining persistence and counting guarantees.
5. Re-run after each targeted fix. Current evidence puts the missing Redis lifecycle service first, followed by the configuration path, worker scheduling, counter persistence and WikiDiscover query work. No application configuration, queue entries, indexes or source code were changed during this continuation.
