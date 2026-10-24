# Redis Streams job dispatch

The farm uses Redis Streams to wake workers as ready work arrives. `worker`
executes ordinary MediaWiki jobs; `provisioner` initializes new wikis independently.
Both block in `XREADGROUP`. They do not scan the wiki database or launch empty
`runJobs` processes while idle. Each delivery performs a targeted registration
lookup, and ordinary jobs wait while their wiki is being provisioned.

## Delivery and recovery

MediaWiki continues to own job payloads, claims, retry counts and deduplication.
The opt-in `streamDispatch` queue setting adds a signal in the **same Lua operation**
as enqueueing ready work. The local jobchron patch does the same when releasing
delayed jobs or recovering expired claims. Neither path has an enqueue/notify gap.
The upstream defaults remain unchanged outside this farm.

Signals are coalesced per `(job type, wiki)`. A worker executes up to 20 jobs or
10 seconds per batch; completion atomically acknowledges/deletes the delivery and
publishes a continuation when ready jobs remain. This also covers jobs arriving
while the current batch runs. No stream trimming removes unprocessed deliveries.

The consumer group is `farm-dispatch-v1`. Deliveries have a 30-second lease,
renewed every 500 ms during child execution. `XAUTOCLAIM` recovers abandoned
signals. Processing is at least once: interrupted work can repeat, so job side
effects must remain safe to retry. Redis persistence determines durability, just
as it does for the existing job queue; Streams do not turn Redis into SQL storage.

The publisher is registered at extension initialization using the runtime hook
API, after configured and extension-manifest creation handlers. This ensures
ManageWiki has seeded its defaults even when an idle CLI database invokes a
commit callback immediately.

After SQL commit, CreateWiki publishes a durable provisioning event while holding
a per-wiki metadata handoff lock, then refreshes the shared lists and target
ManageWiki metadata using its already-initialized services. An atomic receipt
records the target metadata, its list entries and relevant configuration source
hashes. The consumer waits on that lock, validates the receipt and runs the
enabled-extension updater once with the target's full settings. Normal creation
avoids a separate metawiki PHP bootstrap. A missing, changed or incomplete
receipt takes the existing fresh-process metadata repair path before updating.
Timestamp-only rewrites and unrelated wiki creations do not invalidate receipts.
The receipt is removed after successful provisioning.

If the creator exits during preparation, the OS releases the lock and the
durable event remains recoverable through the repair path. Shutdown interrupts
the lock wait and preserves pending work. The lock uses kernel notification,
without polling. The existing pending marker keeps HTTP traffic fail-closed
until schema updates finish.
An event arriving before SQL registration becomes visible stays pending and is
retried. Persistent inactive/missing targets remain pending for operator review.
SQL registration and Redis publication are separate operations; a failed publish
surfaces as a creation error and requires inspection rather than a claim of a
cross-database transaction.

`python3 docker/tests/provision-acceptance.py --image IMAGE` checks the bundled
application in an isolated production-mode farm with fresh credentials and data.
Build `IMAGE` from the current Dockerfile first. The harness pins its image ID
and rejects application source mounts. It runs four simultaneous creators, a
second group of four synchronized at metadata publication, and a private creator
killed with SIGKILL while holding its metadata lock. It checks shared lists,
schemas, CheckUser, private API/file denial, HTTP 503 during preparation, one
updater per wiki and empty queues after recovery. PHP-FPM serves the access
checks over FastCGI inside the isolated network; no host ports are published.
Concurrent cache writes can invalidate receipts and take the repair path.
All temporary containers, credentials and volumes are removed after the run.
The harness records HTTP readiness separately from provisioning completion:
PHP-FPM may briefly serve an old cached wiki list after a CLI repair. It allows
up to 15 seconds for 404/503 responses to clear and immediately rejects any
successful anonymous response that exposes private site information.

At connection/startup, consumers reconcile pending mappings and existing Redis
ready queues or legacy provisioning markers. This is migration/restore recovery,
not a repeated farm-wide scan. A one-second blocking timeout services shutdown,
heartbeats and delivery recovery; new work wakes the blocking read immediately.

`jobchron` still performs timed maintenance of active Redis queues every second.
Delayed jobs, claim expiry and scheduled maintenance inherently require clocks.
The hourly scheduler remains responsible for configured maintenance commands.
These timers are separate from ready-job dispatch.

Child completion is also event-driven. The shared `farmWaitProcess()` helper
blocks `SIGCHLD` before checking the child, then waits with `pcntl_sigwaitinfo()`
or `pcntl_sigtimedwait()`. Exits wake the parent immediately; timed waits serve
the 500 ms lease-heartbeat deadline. There is no fixed process-status polling
interval. SIGTERM/SIGINT remain unblocked so existing shutdown handlers can
forward them. The helper restores its signal-mask change on completion or
heartbeat failure, and leaves reaping/exit-status handling to `proc_open`'s
process resource. This applies to job batches, provisioning children and
scheduled commands, using the image's existing PCNTL extension.

Run the focused process-lifecycle checks against the PHP runtime with:

```sh
scripts/farm compose exec -T worker php /dev/stdin /opt/farm/process.php < docker/tests/process-wait.php
```

## Operations

| Key | Purpose |
| --- | --- |
| `global:jobqueue:dispatch` | Ready-job stream |
| `global:jobqueue:dispatch-pending` | Queue to outstanding signal mapping |
| `global:farm:provision` | Provisioning stream |
| `global:farm:provision-pending` | Wiki to outstanding provisioning signal |
| `global:farm:provision-status:<wiki>` | Queue/start/finish times, expires after seven days |
| `global:farm:dispatch-dead` | Invalid-event diagnostics, approximately capped at 1,000 entries |

Inspect `XINFO GROUPS`, `XPENDING`, pending mappings, service health and logs.
Acknowledged events are deleted, so stream length measures outstanding signals,
not historical job throughput. Malformed deliveries are quarantined. Child
failures keep the signal pending; Redis failures reconnect without clearing work.
Both consumers require a recent heartbeat and a successful Redis ping for health.
Compose explicitly sends SIGTERM because the FPM image otherwise sends SIGQUIT.

Run `python3 docker/tests/streams.py --image YOUR_PHP_IMAGE` for a disposable
farm exercising coalescing, bounded continuation, crash reclaim, delayed release,
expired claims, concurrent consumers, enqueue/completion races, Redis restart,
idle blocking, wrong key types, invalid events, marker-free provisioning and
shutdown. Also run `docker/tests/jobchron.py` for the underlying queue lifecycle.
The stream suite mounts current source into its isolated farm; verify final image
hashes and run normal farm acceptance tests before rollout.

## Rollout and rollback

Take a coordinated `scripts/snapshot --keep-stopped NAME` snapshot before replacing
images. Start Redis, PHP, worker, provisioner, jobchron, scheduler and web using the
same new image/configuration. No queue payload migration or SQL schema change is
required. Keep both local queue patches recorded in the source provenance files.

For rollback to the previous polling worker, stop all writers and consumers. Using
the new PHP image, run `php /opt/farm/dispatch-migrate.php --legacy-markers` in the
farm environment to materialize any pending provisioning signals as legacy
markers. Then select the preserved previous images and start the old services
without `provisioner`. Ordinary queued payloads are compatible with the old worker;
stream records can remain for a later forward rollout. The bridge does not delete
signals. Restoring a snapshot instead requires matching images/configuration and
a coordinated restore of SQL, Redis and files; account for writes since capture.


## Local validation

The 2026-10-21 ARM64 local rollout passed all 13 stream lifecycle checks, the
separate jobchron lifecycle suite, four browser acceptance tests, fresh
public/private/UI creation, main Redis restart recovery, FPM APCu checks, HTTP
smoke checks and read-only preflight. Both consumers finished healthy and blocked
in Redis with no pending dispatch backlog. The final PHP image matched all 24
checked runtime/core/chron source files. No production or AMD64 rollout was done.

Provisioning queue wait in three fresh form submissions was 2–3 ms, versus
6–8 seconds in the polling baseline. Mean verified readiness was 3.77 seconds,
versus 9.60 seconds. Timing includes API verification and observation overhead;
see [WIKI-CREATION-TESTS.md](WIKI-CREATION-TESTS.md) and the machine-readable
`upgrade-evidence/stream-dispatch.json`. The coordinated snapshot is retained at
`backups/pre-stream-dispatch-20261021`, with private runtime configuration, image
identities and checksums. Backup contents were structurally verified; this change
did not include a restore rehearsal.
