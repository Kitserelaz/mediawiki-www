# Clean-checkout and AMD64 build verification

This validation builds the current upgrade candidate from an independent clean
Git clone. It does not deploy the candidate or establish full catalogue/skin
acceptance. MediaWiki is pinned to 1.46.2 and PHP to 8.4.26; exact extension
revisions and base-image digests are recorded in `upgrade-targets.json`.

## Reproduce the source snapshot

From the working checkout:

```sh
python3 scripts/export-clean-checkout.py \
  docker/private-backup/createwiki-managewiki-upgrade/clean-checkout-20261018-v3
```

Use a new output path for each run. The exporter includes current nonignored
candidate changes without changing the original index or committing to its
history. It uses a parentless temporary commit and a `git clone --no-local`
transfer. The resulting checkout must pass `scripts/verify-checkout.py` and have
an empty `git status --porcelain`. Local environment files, generated credentials,
runtime databases and private backups are excluded.

This is a clean clone of the uncommitted candidate, rather than a claim that the
current remote branch already contains the upgrade. The final committed release
must contain the same verified build inputs and pass CI.

## Verified source identity

The accepted candidate tree is `a5d376c130e8d52d62d911ad2c997ed43a365b54`,
with 127,567 tracked files. Its build-input subtree identities are recorded in
`upgrade-evidence/clean-checkout-amd64/checkout.json`. Documentation/evidence
written after the snapshot does not change those build inputs.

The first export exposed 3,486 duplicate case spellings in the index. Each removed
spelling had identical Git blob content to its retained counterpart; no working
source files were deleted. All 3,485 affected core paths match the official 1.46.2
archive's capitalization. The remaining pair was an OATHAuth maintenance
filename. `upgrade-checkout-case-fix.json` records the correction, and the checkout
guard now rejects case collisions.

The second export built all images but its runtime check found required supplied
files hidden by component ignore rules. Added 5,973 required dependency, lock,
schema and compiled-asset files, including OATHAuth/WebAuthn dependencies and
MobileFrontend browser assets. Their exact hashes are recorded in
`upgrade-vendored-build-inputs.json`; the checkout guard now requires them to be
tracked and intact. It also rejects untracked supplied component autoloaders.
Failed/intermediate attempts are preserved privately and are not final evidence.

## Build all AMD64 artifacts

Run these commands inside the exported clone. Docker Desktop's `desktop-linux`
builder provides AMD64 emulation on the ARM64 development host. On an AMD64 build
host, use its configured builder.

```sh
docker buildx build --builder desktop-linux --platform linux/amd64 --load \
  --target php -t cloudwiki-php:upgrade-clean-20261018-amd64 \
  -f docker/mediawiki/Dockerfile .
docker buildx build --builder desktop-linux --platform linux/amd64 --load \
  --target web -t cloudwiki-web:upgrade-clean-20261018-amd64 \
  -f docker/mediawiki/Dockerfile .
docker buildx build --builder desktop-linux --platform linux/amd64 --load \
  -t cloudwiki-counter:upgrade-clean-20261018-amd64 \
  -f Moe-Counter/Dockerfile Moe-Counter
```

The PHP and web Dockerfiles use the clone's vendored dependencies and browser
assets. The counter installs production dependencies with its frozen pnpm lock.
BuildKit caches may be reused; no source directory from the original working
checkout is mounted into a build. This checks build-input completeness, not
independence from the package registries or a byte-for-byte no-cache rebuild.

## Verify the built artifacts

Run the PHP architecture probe and counter tests using the built AMD64 images:

```sh
docker run --rm --platform linux/amd64 --entrypoint sh \
  -v "$PWD/docker/tests/architecture.sh:/opt/architecture.sh:ro" \
  cloudwiki-php:upgrade-clean-20261018-amd64 /opt/architecture.sh
docker run --rm --platform linux/amd64 \
  -v "$PWD/Moe-Counter/test:/app/test:ro" \
  cloudwiki-counter:upgrade-clean-20261018-amd64 node --test
DOCKER_DEFAULT_PLATFORM=linux/amd64 \
  MW_IMAGE=cloudwiki-php:upgrade-clean-20261018-amd64 sh scripts/lint-php-image.sh
DOCKER_DEFAULT_PLATFORM=linux/amd64 \
  COUNTER_IMAGE=cloudwiki-counter:upgrade-clean-20261018-amd64 \
  python3 docker/tests/counter-shutdown.py
```

Record inspected Linux/AMD64 architecture and immutable image IDs, not only local
tags. The evidence directory `upgrade-evidence/clean-checkout-amd64/` contains
build logs, BuildKit metadata, the candidate tree identity and runtime results.

## Results — October 18, 2026

All three images built successfully from the clean candidate clone and inspect as
Linux/AMD64. They are loaded locally; no registry publication or production
rollout was performed. Full image identities and BuildKit metadata are retained
in `upgrade-evidence/clean-checkout-amd64/builds.json`.

| Artifact | Local tag | Immutable image ID |
| --- | --- | --- |
| php | `cloudwiki-php:upgrade-clean-20261018-amd64` | `sha256:9a34a313317dff3c59fd24069c16976e6b29dd6e7962e68ce466db6a4e80e994` |
| web | `cloudwiki-web:upgrade-clean-20261018-amd64` | `sha256:041fa67c731a881fc2d5152f83f7558275711f0cdade11e4fcd5d4d650364a4d` |
| counter | `cloudwiki-counter:upgrade-clean-20261018-amd64` | `sha256:d19bfbc915762b70cc0e53b4901960adfa5b3f3df939a362e81c08bbd59f8ec0` |

| Runtime check | Result |
| --- | --- |
| OATHAuth WebAuthn/QR dependency autoload | Passed |
| Standalone WebAuthn dependency autoload | Passed; existing vendor deprecation notices recorded below |
| PHP native runtime | PHP 8.4.26; Unicode/ICU, GD, ZIP, database/cache/worker modules passed |
| Media tools | Lua 5.1, ImageMagick, FFmpeg, ZIP/unzip and diff passed |
| Elastica legacy alias | Dynamic construction and query serialization passed |
| Complete eligible PHP syntax | 33,836 files passed; one hash-verified, PHP 8.5-only UUID bootstrap excluded on PHP 8.4 |
| Web runtime/assets | Caddy and exported MobileFrontend, CodeMirror and Femiwiki assets passed |
| Counter unit tests | 13 passed, zero failures |
| Counter shutdown/persistence | SIGTERM flushed a delayed increment; replacement container read the SQLite value |
| Checkout guard regressions | Both retained bad indices rejected; corrected candidate passed |

The WebAuthn vendor package `thecodingmachine/safe` emits PHP 8.4
implicit-nullability deprecation notices while loading. Loading succeeds, and
the notices are retained in `webauthn-dependencies.log`; dependency cleanup and
full feature acceptance remain follow-up work. This result is not a claim of a
warning-free catalogue.

`verification-summary.json` combines the accepted source/image identities and
check outcomes. `runtime.json` records the exact executed commands and image IDs;
individual logs and the compact `php-lint-summary.txt` retain the output. AMD64
runtime execution used emulation on the ARM64 development host. Final ARM64
artifacts and remote CI execution are not asserted by this AMD64 batch.

## Acceptance boundaries

Builds and isolated runtime probes do not prove every extension feature works.
The strengthened full catalogue/class-loading gate, all skin render/assets,
remaining legacy query checks and final farm regression coverage remain separate
release requirements. Earlier candidate2/candidate3 evidence predates the latest
bundled extension/provider changes. Do not use it as final acceptance of these
new artifacts. Follow `CREATEWIKI-MANAGEWIKI-UPGRADE-RUNBOOK.md` only after the
remaining release gates pass. No production deployment is part of this check.
