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

Pushes to `main` automatically deploy tested images to cloudwiki.us after CI and architecture checks pass. See [automatic deployment and recovery](docs/AUTOMATIC-DEPLOYMENT.md) for the release procedure, credentials, and snapshot retention.

The requested-plan implementation and outstanding verification are tracked in [docs/PLAN-AUDIT.md](docs/PLAN-AUDIT.md).

The CreateWiki/ManageWiki upgrade is tracked in [docs/UPGRADE-PROGRESS.md](docs/UPGRADE-PROGRESS.md), with [clean-checkout and AMD64 build evidence](docs/UPGRADE-BUILD-VERIFICATION.md) and a [cutover/recovery runbook](docs/CREATEWIKI-MANAGEWIKI-UPGRADE-RUNBOOK.md). The upgrade has not been deployed to production; outstanding release gates are recorded separately from successful builds.

## Audit MediaWiki extension updates

Run the audit on demand from the workspace with Bun 1.3.14 or newer, Git, a PHP CLI with tokenizer support, and Composer. The command reads the working checkout and checks public upstream sources and package metadata. Composer dry runs use the farm's modeled PHP platform.

```sh
bun run scripts/audit-extension-updates.ts
bun run scripts/audit-extension-updates.ts --output docs/EXTENSION-UPDATE-AUDIT.md
bun run scripts/audit-extension-updates.ts --format json --output /tmp/extension-updates.json
bun test ./scripts/tests/extension-audit.test.ts
```

Use `--php PATH` and `--composer PATH` to select tools outside `PATH`. The Composer path may point to a `composer.phar`. Set `EXTENSION_AUDIT_TEST_PHP` to use a non-default PHP executable in the inventory tests. Review the [audit report](docs/EXTENSION-UPDATE-AUDIT.md) for available updates, partial evidence, and required integration work. Maintain repository aliases, release policies, and documented prerequisites in [the audit rules](scripts/extension-audit/rules.json).
