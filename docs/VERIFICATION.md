# Local verification — October 17, 2026

These checks ran locally on Docker Desktop. ARM64 images run natively; AMD64 checks use emulation on the same ARM64 host. The operator confirmed that production is a new farm with no historical data. Production rehearsal uses reserved `farm.example.test` hostnames, loopback ports, private TLS and separate credentials. Public deployment remains separate.

| Check | Result |
| --- | --- |
| Compose configuration | Development and production validation passed |
| Final MediaWiki/public-assets proxy | ARM64 and AMD64 builds passed; runtime probes passed on both architectures |
| Pinned counter images | ARM64 and AMD64 builds passed, including signed snapshot installation and native SQLite dependencies |
| Fresh development | Final packaged initialization into empty volumes and repeat passed |
| Fresh development browser | All four tests passed: shared login/logout, secure cookies, administrator form-based wiki creation, automatic provisioning, editing, Lua, uploads, thumbnails, public/private files and DataDump jobs |
| Fresh production | Final packaged initialization with separate credentials and custom storage paths passed; repeat preserved data |
| Production configuration | All three seed wikis passed canonical URLs, administrator attachment/editing and disabled email checks; meta steward membership and inherited production two-factor requirement verified |
| Production browser | Final fresh farm passed cross-wiki login/logout, Secure/HttpOnly cookies and rejection of external requests |
| Jobs and Redis recovery | Final worker drained seeded and newly created wiki queues after Redis restart preserved queued jobs |
| Graceful provisioning stop | Final packaged helper terminated its child promptly, released the lock and retained its unfinished marker |
| Counter tests | All 13 Linux tests passed on both architectures; separate SIGTERM and replacement-container persistence checks also passed on both |
| Restore protection | Nonempty dumps sentinel caused refusal before import; removing only that sentinel allowed empty-volume restore |
| Restore/update/data | Final image restored `backups/local-final`, completed explicit updates, and preserved account permissions, Lua content, uploads and the stored counter value |
| Restored browser | Editing/Lua/uploads/thumbnails, public/private files and real XML/ZIP dump jobs passed; existing administrator roles were preserved |
| Coordinated snapshot | Earlier full rehearsal also matched upload SHA-256 and preserved private fixtures, generated dumps and restored browser authentication |
| Optional search | Elasticsearch 7.10.2 under AMD64 emulation passed index setup, two-pass backfill, queued writes and a real CirrusSearch query |
| Configuration/boundaries | Final default integration/custom storage checks, secret/data image exclusions, sensitive HTTP paths and helper syntax passed; MediaWiki SMTP delivery was captured by local Mailpit |

Fresh seeding persists ManageWiki defaults and rebuilds metadata with the complete runtime configuration. Ordinary startup does not install databases or run migrations. The snapshot helper stops writers; restore imports Redis data before starting Redis.

Dump acceptance uses actual DataDump XML and upload ZIP jobs, API download and deletion. It caught and corrected the PHP CLI path and two bundled DataDump API defects. Original patched files remain in the excluded private backup directory.

Caddy briefly retried certificate issuance for one hostname on a completely empty production certificate volume. The readiness helper now waits for all five HTTPS hostnames before CI browser acceptance. Production browser acceptance passed after issuance completed. TLS exceptions are scoped to local tests; the host trust store was not modified.

## Docker storage recovery

System-drive exhaustion interrupted image export. A stopped disk backup was copied with sparse extents and content hashes verified. A corrupt BuildKit solver index was reversibly quarantined using Docker's bundled maintenance VM; its original file was retained. No factory reset was performed.

The operator approved moving Docker storage to the external SSD. The original disk matched the verified copy before switching. Docker's VM was confirmed to use `/Volumes/1TB SSD mew/www/.docker-data/Docker.raw`, and all 27 preexisting named volumes were present. The redundant internal disk was then removed, restoring about 30 GiB of system-drive space. Keep the SSD connected while Docker runs. The earlier full backup and previous Desktop settings remain private under `backups/docker-recovery-20261017/`.

Verification logs are private under `backups/docker-recovery-20261017/verification-logs/`. The coordinated application snapshot is `backups/local-final/` and contains credentials. Completed disposable rehearsal projects and fixture volumes were removed. The primary `cloudwiki` volumes and recovery backups remain intact.

## Remaining actions and limits

- GitHub Actions is configured but has not run remotely: the workspace root is not a published Git repository.
- Trust the exported local CA once in the browser/OS, following [the operating guide](DOCKER.md). Per-host certificates support browser validation instead of a literal `*.localhost` leaf.
- Real production DNS, public certificates and traffic switching have not been performed. Use the separate fresh-production deployment and rollback procedure in [the operating guide](DOCKER.md).
- Optional integrations/renderers and every ManageWiki extension combination are outside baseline acceptance; see [extension dependencies](EXTENSION-DEPENDENCIES.md).
