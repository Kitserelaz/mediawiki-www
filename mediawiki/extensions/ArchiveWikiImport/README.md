# ArchiveWikiImport

A MediaWiki 1.46+ extension that restores archived wiki pages, all revisions contained in a dump, and an optional image bundle from an Internet Archive item URL.

Visit **Special:ArchiveWikiImport**, paste an item URL, review the available dump and image files, and start a background import. The resulting report URL shows progress, notices, failures and a resume button. Only administrators with both `archivewikiimport` and `importupload` can start imports. The initiating user must retain those rights when the worker runs.

## Install

Place this directory at `extensions/ArchiveWikiImport`. Install `7z` and `zstd` on job workers (Debian: `p7zip-full zstd`). The farm Dockerfile includes these dependencies for the next image build.

Create a private storage directory **outside the web root**, writable by both PHP and the job worker. It must be the same shared filesystem on every server that serves the wiki or runs its jobs. Example:

```php
wfLoadExtension( 'ArchiveWikiImport' );
$wgArchiveWikiImportDirectory = '/var/www/dumps/archive-wiki-import';
```

This repository enables the extension farm-wide in `GlobalExtensions.php`. Docker runtime settings place private import storage at `MW_DUMP_DIRECTORY/archive-wiki-import` (by default `/var/www/dumps/archive-wiki-import`) on the existing shared dumps volume. Rebuild the PHP and web images, recreate the PHP and worker services, and open `Special:ArchiveWikiImport` on the destination wiki. No database migrations are required. For a separate MediaWiki installation, use the configuration above.

Configure a reliable background job runner. For a standalone wiki:

```sh
php maintenance/run.php runJobs --type archiveWikiImport --maxjobs 1
```

Imports may take hours. Use a CLI worker with sufficient execution time, storage and memory; a single page's revision history can be large. Requests only discover metadata and enqueue work. The farm's existing queue dispatcher discovers registered job types.

## Import queue

Visit **Special:ArchiveWikiImportQueue**, or follow **View import queue** from the import form or a progress report. The page lists this wiki's saved runs, newest update first, with requester, archive item, status, phase, page/revision/image counts and links to the full reports.

The default filter includes queued, running, paused and failed imports. Select **All statuses** or **Complete** to see completed history. Filter by requester and archive item text; results are paginated at 50 per page. **Start a new archive import** returns to the existing import form. A queue subpage containing a run ID redirects to that run's canonical progress report.

Viewing the queue requires `archivewikiimport`, just like the import page. Records remain scoped to the current wiki; administrators do not gain access to other wikis' records. Resume actions stay on the existing report and retain their initiating-user, import permission and CSRF checks. This is a view of import runs, not an approval-request workflow. No database migration is required; existing saved reports appear automatically.

## Per-wiki active import limit

Each wiki may have at most **three queued or running imports**. Starting a fourth import or resuming an inactive run while all three slots are occupied is rejected with a clear message. Paused (`needs-attention`), failed and completed runs consume no slots. Resuming a run reserves its slot immediately; retrying a run already queued or running reuses its existing slot.

Admissions use a lock on the shared private filesystem, scoped to the wiki's database and table prefix, so simultaneous submissions or resumes cannot exceed the limit across PHP servers. Workers also check admission when activating an inactive run, covering maintenance/direct invocations. The import page and queue show current usage out of three. A queued or running run whose worker stopped retains its slot until it is resumed and finishes, fails or pauses.

## Per-wiki daily import limit

Each wiki may start **five new imports per calendar day**, resetting at midnight in its configured `$wgLocaltimezone` (UTC if unset). Failed and paused imports still count. Resuming an existing run does not consume another daily allowance; the three-active-import limit still applies. The import page and queue display daily usage, the next reset time and timezone.

New admissions share the per-wiki filesystem lock, so concurrent submissions cannot exceed five. A separate `daily-admissions.json` ledger retains creation timestamps even after reports are deleted. Preserve this file when cleaning up run directories. Existing reports are counted using their creation timestamp, or their first observed update timestamp for older reports without one.

## Supported archives

- Page dumps: `.xml`, `.xml.zst`, `.xml.7z`, `.xml.zip`, `.xml.gz`, `.xml.bz2`.
- Compressed dumps other than Zstandard must contain exactly one XML file.
- Image bundles: filenames containing `images`, `uploads` or `media`, ending in `.7z`, `.zip` or `.tar`.
- Selection requires an Internet Archive file size and SHA-1 checksum. Private files and Archive-generated metadata XML are excluded.

For example, `https://archive.org/details/wiki-mijopedia.skywiki.org-20250511` contains `mijopedia.skywiki.org-20250511-history.xml.zst` and `mijopedia.skywiki.org-20250511-images.7z`, both supported.

The initial preview lists filenames and compressed sizes. After downloading, the background job scans the entire XML before importing any pages and reports page/revision counts, content models and extension dependencies. It does **not** verify the completeness of the source backup or its licensing. History can only be restored when present in the dump. Review the source before importing.

## Automatic dependency detection

Before importing pages, the job scans all revisions in the dump, including historical content models. It compares those models with the destination wiki's registered handlers and identifies providers from locally installed extension manifests. For example, missing `sanitized-css` identifies TemplateStyles, and Lua models identify Scribunto. Unknown providers are reported without guessing.

Missing handlers put the run in `needs-attention` with zero new page writes. The progress report lists the missing models and extension providers and links to this wiki's extension settings when ManageWiki is available. Enable the required extension, then resume the same run; dependency checks run again using the updated wiki configuration. Extensions are not enabled or installed by the scanner.

Common markup also produces advisory suggestions: `<templatestyles>`, `<ref>`/`<references>`, `<syntaxhighlight>`/`<source>`, `<math>`, `<poem>`, `<score>`, `<inputbox>`, `#invoke`, and common ParserFunctions. The report shows whether each suggested extension is currently enabled and the evidence that matched. Markup in examples, comments or old revisions can produce false positives. These suggestions do not pause imports, and the checks cannot detect every extension, extension version, namespace or site-configuration dependency. They do not copy the archived site's entire extension list.

## Import behavior

- Core `WikiImporter` imports revision text, timestamps, comments and contributor attribution. Source contributors are prefixed with `archive-ITEM>` and are not assigned to similarly named local accounts. Source revision IDs are not retained as destination IDs.
- Existing page histories are merged according to MediaWiki's import behavior. Import into a fresh wiki when possible. There is no automatic undo.
- The checked-by-default **Replace the untouched default main page** option makes the newest archived main-page content current when the destination still displays the installer's `MediaWiki default` revision. It adds an ordinary restoration edit attributed to the importing administrator and keeps both the installer revision and all source revisions with their original timestamps. An edited destination main page is preserved, and the current revision is rechecked under the update lock before restoring. Uncheck this option to retain the default main page. The source must contain the destination's configured main-page title; this option does not change which title is configured as the main page.
- Source namespace information is passed to MediaWiki's normal namespace mapper. Configure required custom namespaces and content-model extensions before importing. Review notices for pages that core cannot map or edit.
- Image bundles are streamed one entry at a time and validated through MediaWiki's upload checks, including the initiating user's upload permissions. The source file-description page imported from XML is retained. New file uploads are attributed to the importing administrator, with the Archive item URL in the upload summary.
- Existing image names, thumbnails, archived/deleted image directories, empty files and disallowed file extensions are skipped. The report counts those skipped entries. Historical image versions and upload attribution are not reconstructed.
- Source logs, embedded remote upload URLs, accounts/passwords, site settings, skins and extensions are not imported.

## Recovery and limits

Each run has a random ID in a directory scoped to the destination database and table prefix. Filesystem locks prevent concurrent workers for a run. State writes use atomic replacement. Completed page/image checkpoints are saved only after committing the corresponding database writes.

On failure, the report retains the error and verified downloads. Resume uses the same file manifest and checksum, replays the XML, and skips completed pages. XML site information is still processed on resume so namespace mapping is retained. MediaWiki's hash/timestamp duplicate detection handles revisions committed just before a crash. Very old dumps without revision SHA-1 values do not have that duplicate guarantee for a partially imported page. Resume requires the original administrator. Duplicate queued jobs for an already active or completed run are harmless.

Successful runs remove downloaded and expanded content and retain their report. Failed runs retain downloads for retries. Administrators may delete a run directory when no worker is running to remove its report and temporary files; imported content is unaffected.

## Automatic worker recovery

Workers publish their identity and a heartbeat in a separate atomic file, throttled to every 30 seconds while downloading, decompressing, scanning and importing. The lease lasts five minutes. Heartbeats do not advance page/image checkpoints. A long blocking operation can make the heartbeat overdue; the run lock remains authoritative.

The farm scheduler runs `maintenance/recoverImports.php` once per minute for each wiki. On a standalone installation, schedule:

```sh
php maintenance/run.php ArchiveWikiImport:recoverImports
```

A run with an expired lease is recovered only after its worker lock can be acquired. Legacy running reports and queued runs without a worker use their last update as the lease baseline, covering a crash between saving a new run and enqueueing its job. Recovery retains the same run ID, downloads, committed checkpoints and active slot; it does not consume another daily admission. Retries wait 60, 120 and 240 seconds, plus scheduler/queue latency. Three recoveries without committed page/image progress exhaust the budget; the run becomes failed, releases its slot and offers manual resume. Committed progress resets the budget at the next recovery check. Manual resume of a failed or paused run resets the budget too.

Queue publication failures retain the pending recovery intent for the next scheduler pass. A crash after publication can produce a duplicate job; the run lock prevents overlapping workers, and late jobs for failed, paused or completed runs are ignored. Ordinary import errors (invalid archives, missing permissions or content handlers) remain failed or paused and require manual intervention.

Reports display worker identity, last heartbeat, recovery attempts and scheduled retry time. The recovery command emits counts of scheduled, requeued, stalled and failed runs to scheduler logs. An expired heartbeat with a held lock is reported as stalled and never stolen. Investigate the worker and terminate it through the worker supervisor if needed. Decompressors inherit the worker lock, so a surviving subprocess must exit before recovery can begin. Preserve shared filesystem lock semantics across all servers.

Defaults (override in LocalSettings.php):

| Configuration | Default |
| --- | --- |
| `$wgArchiveWikiImportMaxDownloadBytes` | 10 GiB combined selected files |
| `$wgArchiveWikiImportMaxExpandedBytes` | 50 GiB per compressed bundle/dump |
| `$wgArchiveWikiImportMaxEntries` | 100,000 entries per bundle |
| `$wgArchiveWikiImportSevenZip` | `/usr/bin/7z` |
| `$wgArchiveWikiImportZstd` | `/usr/bin/zstd` |

Reserve space for the downloads plus expanded XML and the destination uploads/database. Metadata responses are limited to 8 MiB; decompressor listings to 16 MiB. Decompressors have a one-hour limit per invocation and their output size is monitored. Downloads stream to disk and are verified before import. HTTP targets and redirects are restricted to HTTPS `archive.org` and its subdomains. Archive entry names never become filesystem extraction paths. Start/resume actions require a POST and a CSRF token.

## Validation

Standalone validation:

```sh
php extensions/ArchiveWikiImport/tests/unit.php
```

Run an isolated acceptance test from this repository (requires Docker and the locally built `cloudwiki-php:local` image):

```sh
mediawiki/extensions/ArchiveWikiImport/tests/run-integration.sh
```

This creates disposable PHP and MariaDB containers with no farm credentials, ports or persistent volumes. It executes imports through MediaWiki's real JobRunner transaction lifecycle and tests history imports, contributor prefixes, timestamps, custom namespaces, upload validation, file descriptions, checkpoints, duplicate-safe recovery, worker leases, killed-worker and surviving-decompressor safety, concurrent recovery publication, retry exhaustion, zero-write dependency pauses, actionable dependency reports and resume after enabling TemplateStyles. Containers are removed on exit. Dependencies are installed in the temporary test container when absent. Set `AWI_PHP_IMAGE` to use another compatible PHP runtime image. Set `AWI_LIVE_ARCHIVE=1` to additionally test discovery, redirects, downloading and checksum verification against the example Archive item; its content is not imported.
