# Automatic production deployment

Every push to `main` starts `.github/workflows/docker.yml`. Pull requests run CI
without deployment. The integration job publishes its tested Linux/AMD64 PHP,
web and counter images to `ghcr.io/kitserelaz/mediawiki-www-{php,web,counter}`,
tagged with the complete commit SHA. Deployment also waits for both architecture
checks to pass. Registry digests, rather than mutable tags, select production code.

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
   Tracked local changes cause a refusal instead of being overwritten.
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

Expect a maintenance interruption while writers are stopped, snapshots are taken,
and schemas are updated. Docker remains enabled at boot, and normal service
startup uses the existing restart policies without running migrations.

The installed entry point updates itself from the successful release. The workflow
does not cancel an in-progress deployment when a newer push arrives. A server lock
also prevents overlap if a workflow is manually retried.

## Operations and recovery

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

If migration or health checks fail, the workflow fails and leaves application
writers stopped. It does not run old code against potentially migrated schemas.
The log identifies the recovery snapshot. Inspect the failure, then fix forward
or restore the matching previous environment, commit, database and files using
the coordinated recovery runbook. Automatic deployment refuses to start if less
than 5 GiB is free.

To temporarily disable automatic deployment, disable the **Docker stack** workflow
in GitHub Actions. To revoke only production access, remove the key marked
`cloudwiki-github-actions-production` from the server's authorized keys. Rotating
the key requires replacing both that public key and `PRODUCTION_SSH_KEY`.
