# Automatic production deployment

Every push to `main` starts `.github/workflows/docker.yml`. Pull requests run CI
without deployment. The integration job publishes its tested Linux/AMD64 PHP,
web and counter images to `ghcr.io/kitserelaz/mediawiki-www-{php,web,counter}`,
tagged with the complete commit SHA. Deployment also waits for both architecture
checks to pass. Registry digests, rather than mutable tags, select production code.

Catalogue registration and skin asset checks are advisory: failures produce a
workflow warning and summary, with full reports uploaded as
`catalogue-failure-evidence`, but do not block image publication or deployment.
Both checks run independently, so a registration failure does not skip skin
checks. Preparing and cleaning up the isolated catalogue farm remain required.
All other integration checks and both architecture checks still gate deployment.

The existing browser checks run only in GitHub's disposable CI environment. This
pipeline installs no browser automation on the VPS or an operator's computer.

## Credentials and server setup

The repository's `production` environment contains:

- `PRODUCTION_SSH_KEY`: a dedicated Ed25519 private key used only for deployment.
- `PRODUCTION_KNOWN_HOSTS`: the server's verified SSH host key.

The matching public key in `/home/ubuntu/.ssh/authorized_keys` uses `restrict` and
a forced `/usr/local/sbin/cloudwiki-deploy-ssh` command. That wrapper invokes
`/usr/local/libexec/cloudwiki-deploy-production.py` through sudo. The entry point
accepts only `deploy` with a JSON request containing an exact commit and three
allowed image digests. It cannot open an interactive shell or forward ports.

The workflow uses its short-lived `GITHUB_TOKEN` for registry access. The server
discards its private temporary Docker login configuration immediately after
pulling the images. No personal GitHub token is stored on the server.

## What happens during an update

1. A server lock serializes deployments. A commit superseded on `main` is skipped.
   Tracked local changes cause a refusal instead of being overwritten. A separate
   fingerprint baseline protects `.env.production`, Compose files and overrides,
   service environment files, local credential files, mounted configuration files,
   and PHP settings in an active bind release.
   Missing baselines or unacknowledged local edits block deployment.
2. All images are downloaded before stopping traffic. Failed checks or pulls leave
   the current release running.
3. `scripts/snapshot --keep-stopped` stops writers and creates a coordinated
   database, uploads, Redis, counter and certificate snapshot. The previous
   production environment and checkout commit are recorded alongside it.
4. The checkout moves to the tested commit. Only image settings in
   `/var/www/.env.production` change in image mode; production credentials and
   volumes persist. With `MW_CODE_MODE=bind`, the deployer first exports the tested
   source into `/var/lib/cloudwiki/releases/COMMIT` and also updates
   `MW_SOURCE_DIRECTORY`. All PHP services and Caddy select that read-only source
   through the shared Compose wrapper. Image mode remains the default.
5. `scripts/farm update` explicitly migrates existing schemas and refreshes farm
   metadata. Fresh initialization is never part of deployment.
6. Compose starts the release without building. Container health and HTTPS API
   checks against this origin must pass before the release is recorded as healthy.
   Only then are the deployer's expected configuration changes recorded in the
   fingerprint baseline. Operator edits made during deployment are not accepted.

Expect a maintenance interruption while writers are stopped, snapshots are taken,
and schemas are updated. Docker remains enabled at boot, and normal service
startup uses the existing restart policies without running migrations.

The installed entry point updates itself from the successful release. The workflow
does not cancel an in-progress deployment when a newer push arrives. A server lock
also prevents overlap if a workflow is manually retried.

## Operations and recovery

The accepted configuration fingerprints are stored in
`/var/lib/cloudwiki/accepted-local-config.json`, readable only by root. They contain
file paths and hashes, without secret values. Checks run before deployment, after
image downloads, after the snapshot, and after health verification. Errors list
changed filenames without showing their contents. Tracked Git files are also
checked again before switching releases.

After intentionally editing local production settings or rotating credentials,
review the edits and explicitly acknowledge them on the server:

```sh
sudo python3 /usr/local/libexec/cloudwiki-deploy-production.py --accept-config
sudo python3 /usr/local/libexec/cloudwiki-deploy-production.py --check-config
```

The first installation establishes the initial baseline from the current server
configuration. Subsequent installs do not reset an existing baseline. The forced
SSH wrapper permits only `deploy`, so the CI deployment key cannot acknowledge
configuration edits or bypass this check. Tracked local changes must still be
committed or restored; acknowledging local configuration does not override Git's
clean-checkout requirement. If an edit is detected during the maintenance phase,
writers remain stopped as for any failed migration; the edit is preserved.

Inspect workflow runs in GitHub Actions and use these commands on the server:

```sh
cd /var/www
sudo docker compose --env-file .env.production ps
sudo docker compose --env-file .env.production logs --tail=100
sudo cat /var/lib/cloudwiki/last-success.json
```

Release history is stored in `/var/lib/cloudwiki/history/`. Each record identifies
the commit, image digests, previous commit, and recovery snapshot. The latest two
image/source releases and three successful deployment snapshots are retained. Unrelated
operator backups and failed deployment snapshots are not deleted. Copy backups
off the server independently; local snapshots do not cover loss of the VPS.

If a deployment fails after traffic is stopped but before database services or
migrations are started, the deployer automatically restores the previous source
commit, image settings and bind-source path. It starts the existing data-service
containers in place, then starts the previous application using locally available
images, without rebuilding or pulling. Origin health checks must pass before
recovery is marked `rolled_back` and the live editor is reopened. The deployment
job still fails, so a recovered outage does not hide a broken release.

Rollback never imports SQL, restores a data snapshot, removes volumes, or runs
the previous release's database updater. Current database contents, uploads,
queue state and counter data are retained. A private `rollback-deploy-*.json`
record in `/var/lib/cloudwiki` identifies the failed and restored commits and
the recovery snapshot; the previous successful release record is retained.

Once database services or migrations have been started, application rollback is
allowed only for an explicitly verified release pair, as described below.
Otherwise writers remain stopped for a forward fix. Failed rollback health or
preflight checks also leave writers stopped. Concurrent operator configuration
or source edits are preserved and block recovery rather than being overwritten.
The log identifies the recovery snapshot. Automatic deployment refuses to start
if less than 5 GiB is free. Application deployments also reject changes to the
Compose project identity or persistent database, Redis and memcached service
definitions; infrastructure upgrades require a separate planned procedure.

### Rollback after database work

Database compatibility is not inferred from a successful build, a schema version,
or a matching table layout: migrations can change data semantics as well as DDL.
The default is no permission to roll back after database work begins.

An operator may approve an exact previous/candidate commit pair only after
rehearsing the candidate on an isolated production copy and verifying the old
application against both completed and interrupted migrations. Check old-release
startup, HTTP/authentication, writes, extensions and background jobs, including
data-only changes. Retain the rehearsal/audit report. A successful forward-only
migration test is insufficient to approve partial-migration recovery.

Record that review in `/var/lib/cloudwiki/rollback-compatibility.json`, owned by
root with mode `0600`, under the production deployment lock. For example:

```json
{
  "schema_version": 1,
  "approvals": [{
    "previous_commit": "PREVIOUS_FULL_COMMIT_SHA",
    "candidate_commit": "CANDIDATE_FULL_COMMIT_SHA",
    "database_backward_compatible": true,
    "partial_migrations_compatible": true,
    "evidence": "Location of the retained rehearsal and compatibility review"
  }]
}
```

This is a trusted local operator attestation, not an automated compatibility
proof. Approval is read only from this private local policy, never from the
candidate checkout or a field in the CI SSH request.
Missing, malformed, symlinked or writable-by-others policy files fail closed;
approvals for other commit pairs have no effect. No pairs are approved by default.
The policy is read before the write freeze. Keep it private and edit it only
while holding `/var/lib/cloudwiki/deploy.lock`.

For an approved pair, rollback additionally runs the previous runtime's
read-only preflight in a one-off container with `--no-deps` before reopening
traffic. The previous runtime must then pass the same origin health checks as a
new deployment. Even approved rollback never restores database contents.

To temporarily disable automatic deployment, disable the **Docker stack** workflow
in GitHub Actions. To revoke only production access, remove the key marked
`cloudwiki-github-actions-production` from the server's authorized keys. Rotating
the key requires replacing both that public key and `PRODUCTION_SSH_KEY`.

## Public change dashboard

`https://diff.cloudwiki.us` shows the last successful production commit, the
comparison from that commit to `main`, the previous successful release comparison
when available, CI status, and local configuration drift. Pending changes are
explicitly distinguished from deployed changes. Expanded patches come from the
public GitHub repository; config contents, hashes, image credentials, and secret
values are never written to the public report.

A root systemd oneshot (`cloudwiki-diff.service`) generates a static JSON report
every minute via `cloudwiki-diff.timer`. Public GitHub comparisons are cached for
five minutes. Outages retain the previous matching report with a stale warning.
The generator shares the deployment lock while inspecting config, so it does not
read a partially switched release. Its public output is mounted read-only into
Caddy. There is no privileged HTTP application or public deployment action.

The initial release anchor is recorded at installation, before later checkouts;
subsequent successful deployments use `last-success.json`. Successful deployments
also refresh dashboard HTML/CSS/JS and the report generator from the tested
checkout using `scripts/install-diff-dashboard.py`. Installation does not change
which wiki application images are running.

The server's reviewed operator overlay is
`/var/lib/cloudwiki/diff/compose.yaml`, appended to `COMPOSE_FILE` in
`.env.production`. It wraps the checkout's production Caddyfile and adds the
`diff` hostname and static public directory. The overlay and both proxy configs
are covered by the local configuration guard. Infrastructure changes to the
wrapper or systemd units are installed explicitly with
`sudo python3 scripts/install-diff-dashboard.py --infrastructure`, validated,
and acknowledged with `--accept-config` only after operator review; routine
asset refreshes do not accept configuration changes.

Useful server checks:

```sh
sudo systemctl status cloudwiki-diff.timer cloudwiki-diff.service
sudo journalctl -u cloudwiki-diff.service -n 30
sudo python3 /usr/local/libexec/cloudwiki-deploy-production.py --check-config
```

The dashboard is public. It reveals changed config filenames and deployment
commit IDs, but never the private contents. Reports are read-only; intentional
config changes must still be reviewed and accepted locally on the server.

Live source editing is an explicit `MW_BIND_STRATEGY=live` opt-in. See the live
editing section of `docs/DOCKER.md`. Both tracked changes and new untracked
MediaWiki files block deployment. In this mode the editor is stopped before the
final cleanliness check and restarted only after a healthy release, preventing
editor saves from racing with Git checkout. Failures before the write freeze
resume the editor without stopping the application; migration failures keep both
stopped. The dashboard's Live edits tab shows filenames only and marks dirty
source as different from its displayed base commit.
