# CloudWiki workspace

Docker support for the customized MediaWiki farm and Moe-Counter is documented in [docs/DOCKER.md](docs/DOCKER.md).

```sh
scripts/farm setup
scripts/farm build
docker compose --profile dev up -d database redis memcached mailpit
scripts/farm init
scripts/farm up
scripts/farm certificate
scripts/farm smoke
```

Local verification results are recorded in [docs/VERIFICATION.md](docs/VERIFICATION.md).

Use `https://test.localhost`; see the guide for local certificate trust, credentials, storage, migrations, and production deployment.

For a new production farm, use `scripts/farm setup-production --domain YOUR-DOMAIN` and the explicit `init-production` command with the generated environment file. Production credentials remain separate from development. See the guide before starting public services.

The requested-plan implementation and outstanding verification are tracked in [docs/PLAN-AUDIT.md](docs/PLAN-AUDIT.md).
