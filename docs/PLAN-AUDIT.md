# Docker plan audit

This maps the requested plan to implementation and evidence. Consult `VERIFICATION.md` for executed checks; configuration alone is not acceptance evidence. The production target is a new farm, as confirmed by the operator, so fresh production initialization replaces a historical-data migration rehearsal. Public deployment remains separate.

| Requirement | Implementation | Evidence / remaining work |
| --- | --- | --- |
| Preserve installation and dependency versions | Original private settings retained outside builds; manifest, lockfile and installed Composer inventories | Inventory generated; supplied vendor trees retained |
| PHP 8.3 Debian tooling and optional extension dependencies | `docker/mediawiki/Dockerfile`, `EXTENSION-DEPENDENCIES.md` | ARM64 and AMD64 images built; PHP/Lua/image/video/archive runtime probes passed on both |
| Reproducible installation and safe context | Exact image/tool versions, lockfiles, allowlisted context and explicit COPY | Exclusion tests passed; immutable base digests and Debian snapshot implemented; pinned PHP and counter builds passed; slim Node CA bootstrapping verified |
| Shared image for web PHP, jobs, init and maintenance | Root Compose and production/development overrides | Compose validation passed |
| HTTPS wiki/auth/counter routes and production hostnames | Caddy configurations, explicit domain/realm and canonical URL settings | Final fresh local and production browser login/logout passed; CI waits for all local HTTPS hostnames |
| Local certificate trust | Persistent Caddy internal CA, public certificate export and documented trust command | Uses per-host certificates rather than a `*.localhost` leaf to support browser validation; host trust remains an explicit one-time step |
| Persistent storage and runtime credentials | Named volumes, mounted secret files, separate development/production setup | Snapshot/restore passed; custom upload/dump configuration checks passed |
| Explicit fresh/restore/update with bootstrap config | `manage.php`, bootstrap config, auxiliary schemas and persisted farm defaults | Development fresh/repeat/restore passed; packaged fresh production and repeat passed; final fresh management role, browser wiki creation and repeat initialization passed |
| CreateWiki privileges and new-wiki schemas | Scoped application database grants; worker provisioning markers | Public/private creation and schema completion passed |
| Fair bounded workers, graceful stop and single scheduler | Refresh per sweep, bounded job batches, failure backoff, shared-volume scheduler lock | Jobs and Redis recovery passed; final packaged provisioning SIGTERM check and fresh-farm Redis recovery passed |
| Optional search and local email | Internal ES profile, Mailpit; explicit integration configuration | Real Cirrus query passed; remote file repositories now require local opt-in, packaged local configuration checks and actual MediaWiki-to-Mailpit delivery passed |
| Moe-Counter paths, native dependency, process and persistence | Configurable SQLite path, pinned pnpm lockfile, non-root Node process | 13 Linux tests, SIGTERM flush and replacement-container persistence passed on ARM64 and AMD64 (emulated) |
| Real dump generation | Persistent DataDump XML/ZIP configuration and API/worker test | Core XML dump passed; actual DataDump test exposed PHP CLI path and bundled API bugs, generation, download and deletion tests passed |
| Backup/restore and rollback | Coordinated snapshot, empty-volume restore, explicit update; matching files/DB/images rollback procedure | Final empty-volume restore/update/data checks and nonempty dumps refusal passed; no historical production data exists |
| CI and operating documentation | Default fresh/repeat/browser/jobs/snapshot checks, architecture build/runtime matrix, optional search job; setup/operations guide | Workflow added; no remote CI run because workspace root is not a published Git repository |

The fresh production setup generates credentials and configuration without starting services. The isolated rehearsal uses the reserved `farm.example.test` domain, loopback ports and private TLS; it must never be interpreted as a public deployment or proof of real DNS/ACME configuration.
