# Production S3 migration — October 23, 2026

Production at `ubuntu@15.204.121.114` now stores uploaded files in
`s3://cloudwiki01/production/<wiki>/`, region `us-east-2`. Development uses the
separate `development/` prefix. Production reuses AWS credentials already
present on its server; no workstation AWS secret was transferred.

The migration copied 8 existing files (1,286,671 bytes), including originals,
thumbnails, avatars and deleted-file storage. Every copied object was downloaded
and its size and SHA-256 compared with the source. Original local files remain
in the production uploads volume for recovery.

Verification passed for live MediaWiki upload, read, copy and deletion; existing
South Park wiki image and thumbnail delivery; all three migrated avatar files;
anonymous denial of deleted files; and denial of anonymous direct S3 access.
Temporary test objects were deleted. PHP, job workers and the editor were healthy,
and the production configuration guard matched its accepted baseline.

Recovery snapshot on the server:

```text
/var/www/backups/pre-s3-production-20261023-1792778971
```

Compressed recovery archives were checked and their hashes saved in
`SHA256SUMS`. Migration metadata and original configuration copies are private
under `/var/lib/cloudwiki/s3-migration-20261023-retry/`.

The production deployment uses the live source checkout at `/var/www`.
S3 source changes and the live account-administration edits were preserved in
commit `844ddc2b8`. Development was reconciled with production, retaining the
S3 setup documentation, example environment settings and mocked configuration
test. The next immutable release includes the bundled AWS extension,
`S3Settings.php`, the settings includes, and the Compose S3 environment fields.

The current snapshot and DataDump ZIP scripts still archive local upload storage.
They do not back up or export new S3-only files. Arrange separate S3 backups and
an S3-aware ZIP export, as described in [S3.md](S3.md). After new S3 writes,
returning to local storage requires copying current S3 contents back while all
writers are stopped.
