# Single-pass wiki provisioning

New-wiki provisioning runs the MediaWiki updater once with the target wiki's
effective configuration and enabled extensions. The coordinator remains
`docker/mediawiki/provision.php`. Its explicit `--conf` entry point is
`docker/mediawiki/provision-settings.php`.

The settings entry point is CLI-only, rejects bootstrap configuration, and
suppresses `SetupAfterCache` hooks that may query extension tables before those
tables exist. It loads ordinary LocalSettings exactly once. Schema-update hooks
remain enabled. Ordinary HTTP requests do not use this entry point.

CreateWiki still imports initial schemas. Its stream event is published after
the registration transaction commits. The coordinator refreshes the shared wiki
list and full per-wiki metadata in a fresh process using normal metawiki settings
before launching the updater. Pending markers, per-wiki locking, signal forwarding, updater
exit-status handling and Redis acknowledgement remain in place. The marker is
removed only after the updater succeeds.

This replaces the bootstrap updater followed by a normal updater. It does not
change the separate existing-farm upgrade path in `manage.php`, including its
`--doshared` updates. Fresh wiki creation intentionally does not upgrade shared
databases. No migration of existing local wikis is required for this change.

## Validation

`python3 docker/tests/provisioning.py` compares the actual production coordinator
against a frozen historical two-pass control in a disposable farm. It checks
public defaults, private/German settings, Comments/custom settings, and
Cargo/Linter schemas. It compares all normalized table schemas and loaded
extensions, checks effective settings and real CheckUser writes/lookups, and
asserts one updater invocation per dispatch attempt for the new path versus up
to two for the control. Retried attempts remain visible in the report and logs.
The one-pass cases must complete in exactly one dispatch attempt.

Only two local OAuth timestamp-patch markers may differ. OAuth's generated
schema already contains the target types, and normal runtime uses the central
OAuth database. `python3 docker/tests/oauth-provisioning.py` tests that routing,
OAuth 1/2 consumer/acceptance persistence and timestamps, unchanged shared state,
and the exact bootstrap-only cause of those markers. See
`CHECKUSER-ROUTING.md` for the investigation.

After rebuilding the PHP image, the local main-stack check is:

```sh
PLAYWRIGHT_BROWSERS_PATH=/tmp/php-8427-playwright python3 docker/tests/creation.py
```

It creates fresh public/private/browser-form fixtures and verifies readiness,
access controls, session behavior, cleared pending markers and exactly one
updater invocation per wiki. Fixtures remain available for inspection.

The speed benchmarks found lower median provisioning times with one pass, but
occasional slow samples occurred with both approaches. This change does not
claim to eliminate those spikes or establish coverage of every optional
extension in the catalogue.
Those timing comparisons pre-populated metadata. The promoted coordinator also
refreshes metadata and the wiki list; the earlier numbers are not a new
end-to-end benchmark of this final implementation.

## Comments compatibility fix found during rollout

The stricter invocation check exposed a pre-existing Comments failure: fresh
installs created tables, then failed while loading actor-migration classes.
Their protected `updateSkippedMessage()` methods narrowed the parent class's
public method in MediaWiki 1.46. Both the historical two-pass coordinator and
the one-pass coordinator retried; on retry, the new actor columns existed and
the updater no longer loaded those classes, masking the failure.

The three Comments actor migration classes now declare that method public.
Their migration logic is unchanged. The retained local patch is
`patches/comments-mediawiki-146-maintenance-visibility.patch`. The Comments
fixture requires all three migration completion keys and a successful first
attempt in the one-pass path. The pre-fix error and counts are retained in
`upgrade-evidence/one-pass-comments-before-fix.json`.

## Metadata and creation handoff regressions

Main-stack browser testing exposed an incomplete cache containing only `mtime`
and `database`. The old bootstrap metadata fallback could write this record
without privacy, extensions or settings. CLI checks alone did not expose the
HTTP behavior: a fresh private fixture returned anonymous siteinfo. Its metadata
was repaired immediately, and the remaining existing cache records were audited.

`farmWikiMetadataIsComplete()` now checks the required cache fields. Normal HTTP
requests to a registered wiki return HTTP 503 with `Retry-After: 1` until that
metadata is complete. The coordinator's preparation helper uses normal farm
settings, validates its output and discards obsolete derived extension caches.

A second main-stack check caught an updater startup before the deferred shared
wiki list contained the new browser-created wiki. Dispatch is now registered as
a post-commit callback on the registration database, and the coordinator always
refreshes both the wiki list and metadata before its updater. The creation test
requires one dispatch attempt as well as one updater invocation for each fixture.

The disposable comparison deliberately deletes public metadata and injects the
incomplete two-field private record before starting the production coordinator.
It requires complete repaired metadata, correct privacy and a successful first
updater attempt. The HTTP repair test can be repeated on a private fixture from
`creation.py`:

```sh
python3 docker/tests/metadata.py 9creationPREFIXprivatewiki
```

That localhost-only test pauses the provisioner, injects partial metadata into
the test fixture, checks HTTP 503, and then checks that stream processing repairs
it with one updater while anonymous API and private file access remain denied.
It restores valid metadata and starts the provisioner on failure as well.

## Local rollout result

The final local main stack uses image
`sha256:dc700e88eb3e6ae50e6942c58be1540531eee6a89d47a73c8a4ea78f16a9feed`
across PHP, worker, provisioner, jobchron and scheduler. All services are healthy.
The previous image is retained as `cloudwiki-php:pre-one-pass`.

All eight disposable comparison fixtures passed on their first dispatch. Each
new-path fixture ran exactly one updater; each control ran two. Fresh main-stack
fixtures with prefix `9creationcd0bdf09` also completed with one dispatch and one
updater each. The two browser acceptance checks passed in 11.2 s, including
private API/file protection and cross-wiki login/logout. The permanent metadata
regression returned HTTP 503 for an injected partial private cache, then verified
one-updater repair and continued private API/file denial.

OAuth persistence/routing checks, HTTP smoke, read-only preflight and source
verification passed. Twelve baked/installed provisioning, configuration and
Comments file copies match the working source. Provisioning backlog is zero and
disposable test containers/volumes were removed. Main-stack test wikis remain
inspectable. This was a local ARM64 rollout, with no production deployment.

See `upgrade-evidence/one-pass-rollout.json`, `one-pass-equivalence.json` and
`one-pass-oauth.json` for evidence, including the initial failures and the fixes
that were validated before completion.
