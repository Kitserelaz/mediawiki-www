# Blue-green application deployments

Production can keep its public Caddy container, database, Redis, sessions,
counter, and workers running while a public-asset release switches between two
application slots. Each slot has matching PHP and web assets. The edge validates
its generated configuration and changes upstreams with `caddy reload`, using an
admin Unix socket inside the edge container. No admin port is published.

This path currently covers **public assets only**. PHP/backend changes, MediaWiki
core changes outside public resources, extension registration, SQL, dependency
manifests, container images/build inputs, configuration, unknown paths, deletions,
and symlinks select maintenance. Absence of a changed SQL file is insufficient
evidence that a backend update is safe. The online path never runs the updater to
discover whether it needs migrations. The comparison is against the actual
deployed commit, including changes accumulated across skipped CI runs.

## Enable on an existing farm

1. Rehearse against the built images:

   ```sh
   python3 -m unittest discover -s scripts/tests
   python3 docker/tests/blue-green.py
   ```

   The second command requires Docker and the `cloudwiki-php:local` and
   `cloudwiki-web:local` images (or `MW_IMAGE`/`WEB_IMAGE`). It creates an isolated
   network and containers, uses fake PHP responses and private certificates, and
   removes its own containers afterward. It checks continuous HTTPS requests,
   requests spanning cutover, static assets, custom domains, numeric redirects,
   shared-auth routes, forwarded client identity, invalid reloads and switching
   back. CI runs it as a required native-runtime check. It does not replace a
   production-mode farm/authentication rehearsal.

2. Deploy the new tooling through the existing maintenance deployment. This
   installs `blue_green.py` alongside the restricted deployment entry point.
   Ensure there is memory for the original runtime plus two PHP/web slots during
   release and rollback. Images remain pinned and revision-checked as before.

3. Set these values in the operator-owned `.env.production`:

   ```dotenv
   MW_ZERO_DOWNTIME=1
   MW_CODE_MODE=image
   ```

   Alternatively use `MW_CODE_MODE=bind` with `MW_BIND_STRATEGY=versioned` and the
   existing prepared-release mechanism. Live editing of the shared checkout is
   incompatible with isolated release slots and is rejected before any service
   stops. The default state directory is `/var/lib/cloudwiki/blue-green`; an
   operator may set `MW_BLUE_GREEN_DIRECTORY` to another absolute directory.

4. Review the configuration and accept its fingerprints locally:

   ```sh
   sudo python3 /usr/local/libexec/cloudwiki-deploy-production.py --accept-config
   ```

5. Run a successful **maintenance** deployment. This performs the existing backup
   and updates, then starts the first slot and converts the public edge into the
   permanent gateway. **This initial conversion has a maintenance window.** It
   preserves the diff/editor/wildcard wrappers, certificates, Cloudflare trust
   policy, custom-domain permission checks and numeric routing. Rehearse those
   actual production overlays before enabling publicly.

The feature is off by default. It is not enabled merely by installing the source.

## Release selection and cutover

The Production deployment workflow accepts `deployment_mode`:

| Mode | Behavior |
| --- | --- |
| `auto` (default for automatic CI releases) | Online only when the gateway is initialized and every changed file qualifies; otherwise the existing maintenance procedure. |
| `online` | Require an initialized gateway and an eligible asset-only diff; otherwise fail before stopping services. This does not override the safety classifier. |
| `maintenance` | Coordinated backup, database updater and normal service replacement, then rebuild the online gateway. |

The root deployer independently checks the actual Git diff, accepted local
configuration, clean source, trusted repository, full SHA and revision-labelled
registry digests. A workflow input cannot declare arbitrary backend changes safe.

The online sequence is:

1. Pull and verify images; export a separate source release when using bind mode.
2. Refuse to reuse the inactive slot while it still has incoming HTTP connections.
3. Start only the candidate PHP/web services on the existing farm network and data
   volumes. Slots have no host ports and cannot access edge certificate volumes.
4. Check the candidate's meta/login/test APIs and startup ResourceLoader response
   through its HTTP server before touching the live route.
5. Save a private recovery transaction, validate the gateway configuration, select
   the source/environment and gracefully reload Caddy to the candidate.
6. Check the public origin directly, bypassing CDN caches, then record success.

The previous slot stays running with its original files. Ordinary HTTP requests
can finish; the next deployment refuses to replace that slot until its incoming
connections drain. Streaming connections have a five-minute close delay on
gateway reload; clients with persistent streams must support reconnecting.
Maintenance deployments and infrastructure failures still have downtime.

Workers, provisioning, schedulers, Redis, and the counter are deliberately not
restarted for asset-only releases: their backend code has not changed. Backend
changes require maintenance until a separately reviewed migration/worker handover
protocol is implemented. In particular the SQLite counter is never duplicated
as two independent writers.

## Recovery and backups

A candidate failure before cutover leaves the active route and source untouched.
Failure during/after cutover reloads the previous route and restores the prior
environment, checkout and success record. It never restores or overwrites SQL,
uploads, queues, or sessions. Both slots are retained if recovery needs attention.

An interrupted process leaves `pending.json`; new deployments fail closed. Under
the same deployment lock, the operator can recover locally:

```sh
sudo python3 /usr/local/libexec/cloudwiki-deploy-production.py --recover-online
```

Recovery refuses unexpected source or configuration edits. Inspect the private
state instead of deleting `pending.json` to bypass it. Manual historical rollback
continues to require the existing schema-compatibility approval and maintenance
procedure; it is not inferred safe from the release name.

Online release records explicitly contain `backup: null`; their release ID is
not a database snapshot. Continue the farm's backup policy independently. The
existing coordinated `scripts/snapshot` now also stops/restarts running slots,
and `--keep-stopped` keeps them frozen through maintenance. Do not remove those
stops or archive live Redis/SQLite files to manufacture an "online backup".
Manual snapshots and other maintenance must be serialized with the production
deployment lock. This feature does not introduce an online backup service.

Online releases retain their source directories and images; cleanup waits until
a maintenance release, so mounts used by the original runtime are not deleted.
Monitor disk usage. The existing free-space guard remains in force.

## Verification status

Unit/fault-injection tests and Compose configuration checks can run without a
Docker daemon. The real-Caddy rehearsal above requires Docker and is part of CI.
Passing Python tests alone is not evidence of production zero-downtime behavior.

See Caddy's [graceful reload documentation](https://caddyserver.com/docs/command-line#caddy-reload)
and [streaming proxy behavior](https://caddyserver.com/docs/caddyfile/directives/reverse_proxy#streaming).
