# CreateWiki and ManageWiki upgrade audit

Audited October 17, 2026 (America/Vancouver). This document records the original, read-only source audit against public upstream repositories. Its installed versions and findings describe the pre-upgrade baseline. Subsequent implementation and verification are recorded in [UPGRADE-PROGRESS.md](UPGRADE-PROGRESS.md); deployment requirements are in the [cutover and recovery runbook](CREATEWIKI-MANAGEWIKI-UPGRADE-RUNBOOK.md). Production has not been deployed.

## Conclusion

Updating to current upstream `main` is a coordinated MediaWiki/PHP and farm-integration upgrade, not a drop-in replacement of two directories. Both extensions require MediaWiki **1.46.0 or newer**; ManageWiki additionally requires **PHP 8.4 or newer**. This workspace contains MediaWiki **1.45.1** and builds PHP **8.3.30**. Even after those prerequisites are met, the farm's metadata refresh will fail until the removed CreateWiki cache service is replaced.

The extension directories themselves are pristine upstream snapshots. Preserve and migrate the surrounding farm integrations rather than treating these directories as locally patched forks.

## Exact comparison points

Neither repository exposed any Git tags in the fetched repository. Neither installed manifest declares a version. “Latest” here means the following pinned `main` commits, rather than a numbered release:

| Component | Installed snapshot, exact whole-directory match | Latest upstream examined | Changes after installed snapshot |
| --- | --- | --- | --- |
| CreateWiki | [`d101e9e47c8b5a087afd7d6380b42b2abc53b736`](https://github.com/miraheze/CreateWiki/commit/d101e9e47c8b5a087afd7d6380b42b2abc53b736), August 11, 2025 | [`85b031594eb9465483f47c3b6521781c3827c2c0`](https://github.com/miraheze/CreateWiki/commit/85b031594eb9465483f47c3b6521781c3827c2c0), October 14, 2026 | 175 commits |
| ManageWiki | [`3059f4cf8cbd11739f12eb5e0dc9eb74b4c0e271`](https://github.com/miraheze/ManageWiki/commit/3059f4cf8cbd11739f12eb5e0dc9eb74b4c0e271), August 11, 2025 | [`76da3dbc969673c27e8529f5c0ea25287e9f836f`](https://github.com/miraheze/ManageWiki/commit/76da3dbc969673c27e8529f5c0ea25287e9f836f), October 16, 2026 | 170 commits |

All 174 local CreateWiki files and all 134 local ManageWiki files match their respective baseline Git blobs, with no missing or additional files. These are vendored directories in the parent repository, without their own `.git` directories.

Requirement sources: [CreateWiki manifest](https://github.com/miraheze/CreateWiki/blob/85b031594eb9465483f47c3b6521781c3827c2c0/extension.json), [ManageWiki manifest](https://github.com/miraheze/ManageWiki/blob/76da3dbc969673c27e8529f5c0ea25287e9f836f/extension.json). Local runtime evidence: `mediawiki/includes/Defines.php:23`, `docker/mediawiki/Dockerfile:3`.

## Required changes

### 1. Upgrade the platform and its dependency set

- Move core to a supported 1.46+ release and PHP to 8.4+. Do not merely relax the extension manifests: newer code uses newer core APIs and ManageWiki's latest commit explicitly declares PHP 8.4.
- Coordinate compatible versions of the farm's loaded extensions and skins with the core upgrade. This audit establishes the two requested extensions' requirements; it does not certify the hundreds of other installed extensions for 1.46/PHP 8.4.
- Update the pinned PHP image/digest in `docker/mediawiki/Dockerfile`, verify its PECL Redis/memcached builds and PHP modules, and update the hard-coded `RELEASE-NOTES-1.45` COPY source. Review `compose.yaml`'s `MIRAHEZE_WIKI_VERSION: '1.45'` and the farm's version metadata/configuration together.
- Rebuild Composer dependencies for the selected core and extension set, and regenerate `docs/dependency-inventory.json` using `scripts/inventory.py`. Both requested extensions retain their production Composer requirements: CreateWiki still requires `composer/installers` and `php-ai/php-ml`; ManageWiki still requires `composer/installers`. They introduce no additional production Composer package names in this comparison.
- Build and verify ARM64 and AMD64 images, matching the existing delivery process.

### 2. Migrate cache services and hooks — immediate runtime blocker

Upstream split database-list storage from per-wiki ManageWiki configuration storage in August 2025:

| Existing integration | Replacement |
| --- | --- |
| `CreateWikiDataFactory->resetDatabaseLists(true)` | `CreateWikiDataStore->resetDatabaseLists(true)` |
| `CreateWikiDataFactory->newInstance($wiki)->resetWikiData(true)` | `ManageWikiDataStoreFactory->newInstance($wiki)->resetWikiData(true)` |
| `CreateWikiDataFactoryBuilder(string $wiki, IReadableDatabase $dbr, array &$cacheArray)` | `ManageWikiDataStoreBuilder(ModuleFactory $moduleFactory, string $dbname, array &$cacheArray)`, if a custom cache hook remains necessary |

Required local work:

- Rewrite `docker/mediawiki/refresh.php:7` to use the two separate services. This script is called by initialization, explicit upgrades, and the hourly metadata scheduler, so leaving it unchanged breaks all three paths.
- Review registrations in `docker/mediawiki/bootstrap.php:43` and `mediawiki/GlobalSettings.php:6`, and `MirahezeFunctions::onCreateWikiDataFactoryBuilder()` in `mediawiki/SkyWikiFunctions.php:1135`. The old hook is gone. Its present purpose is adding `states.deleted` and `states.locked`; the new ManageWiki datastore already supplies those when the core provider enables the corresponding actions. Confirm equivalence before removing the callback, or migrate its behavior to the new hook signature.
- Explicitly set `wgManageWikiCacheDirectory` to the same shared `/var/www/mediawiki/cw_cache` directory used by CreateWiki and `MirahezeFunctions`, in both runtime configuration and schema/bootstrap configuration. Upstream's new setting falls back to `wgCacheDirectory`, not `wgCreateWikiCacheDirectory`. The current farm only sets the latter. Splitting the paths would leave the farm reading stale per-wiki settings.
- Decide whether to configure `wgManageWikiCacheType` and `wgManageWikiServers`. Retain the current shared-volume/cache topology unless the deployment actually needs server-to-server refresh requests.
- Wire `wgManageWikiSiteConfiguration` to the appropriate farm `SiteConfiguration` object at the correct initialization stage. Upstream now uses it for remote configuration lookups; it defaults to null, and the farm constructs `$wgConf` itself. Test operations on a wiki other than the current request's wiki, especially namespace defaults.
- Rebuild database lists, per-wiki metadata, and extension-list caches with the new implementation. Verify PHP OPcache invalidation and fresh reads in both FPM and CLI workers.

Sources: [CreateWiki datastore](https://github.com/miraheze/CreateWiki/blob/85b031594eb9465483f47c3b6521781c3827c2c0/includes/Services/CreateWikiDataStore.php), [ManageWiki datastore factory](https://github.com/miraheze/ManageWiki/blob/76da3dbc969673c27e8529f5c0ea25287e9f836f/includes/Helpers/Factories/DataStoreFactory.php), [replacement hook](https://github.com/miraheze/ManageWiki/blob/76da3dbc969673c27e8529f5c0ea25287e9f836f/includes/Hooks/ManageWikiDataStoreBuilderHook.php).

### 3. Apply and verify shared database migrations

CreateWiki adds two nullable columns to `cw_wikis`:

- `wiki_closed_reason TEXT NULL`.
- `wiki_inactive_exempt_expiry VARBINARY(14) NULL`.

These support closure reasons and expiry of inactivity exemptions. The latest ManageWiki SQL directory adds no schema files relative to the installed snapshot; it removes seven old patch files and corresponding obsolete updater registrations. Confirm existing databases already have the historical namespace/permission fields and indexes before relying on the newer updater.

Use the existing explicit `scripts/farm update` workflow only after adapting it for the new runtime and cache services. It already invokes `update --quick --doshared` per registered wiki, using bootstrap configuration, then refreshes metadata and updates with full runtime configuration. Verify the virtual domains still resolve as intended: `virtual-createwiki` and `virtual-managewiki` to `wikidb`, and the central request domain to `metawiki`.

CreateWiki's former `maintenance/PopulateCentralWiki.php` is now an installer task. Check fresh installation ordering in `docker/mediawiki/manage.php`, because it currently inserts the seed wiki records explicitly. The new task skips an already registered central wiki, but first-time installation still needs verification for cluster, URL, and central-wiki metadata.

Sources: [CreateWiki updater](https://github.com/miraheze/CreateWiki/blob/85b031594eb9465483f47c3b6521781c3827c2c0/includes/Hooks/Handlers/Installer.php), [ManageWiki updater](https://github.com/miraheze/ManageWiki/blob/76da3dbc969673c27e8529f5c0ea25287e9f836f/includes/Hooks/Handlers/Installer.php), [central-wiki installer task](https://github.com/miraheze/CreateWiki/blob/85b031594eb9465483f47c3b6521781c3827c2c0/includes/Installer/PopulateCentralWikiTask.php).

### 4. Review privacy permissions and preserve custom behavior

- ManageWiki introduces `managewiki-privacy`. Changing another wiki's privacy through the form now requires both `managewiki-restricted` and `managewiki-privacy`. Assign it deliberately to the intended farm-management roles.
- Add that right to the farm's custom disallowed-rights configuration as appropriate. `mediawiki/ConfigSettings.php:621` supplies its own list, so upstream's new default exclusion will not automatically protect it from local delegation.
- Privacy enforcement moves from CreateWiki into ManageWiki. ManageWiki's setup hook explicitly sets anonymous read access according to the wiki's private state. Test private pages, API access, and authenticated image delivery, including public/private transitions and member permissions.
- Review the registered custom special pages in `mediawiki/extensions/SkyWikiMagic/extension.json`: `SpecialCreateNewWiki` and `SpecialChangeDomain`. They use legacy constructor permission arguments and global class names such as `ErrorPageError`/`ManualLogEntry`; upstream has moved its corresponding pages to `getRestriction()` and namespaced classes. Port these integrations as part of the core upgrade and verify that their permission checks still run.
- `SpecialCreateWikiCustom.php` also duplicates the upstream class and contains a confirmed-email requirement. No active registration/reference for that file was found in the inspected configuration, so do not assume it currently overrides `Special:CreateWiki`. Establish whether it is intended to be active before preserving it as a policy requirement.
- Keep the existing `CreateWikiCreation` provisioning marker in `docker/mediawiki/runtime.php` working. Exercise actual creation through the custom page and upstream admin page: successful metadata insertion alone does not prove the worker installed enabled-extension schemas.
- Retain and verify custom domain, article-path, deleted/locked-state and version metadata behavior in `mediawiki/SkyWikiFunctions.php`. A core upgrade does not make historical per-wiki version metadata select a second runtime automatically.

Source for the new privacy check: [ManageWiki form builder](https://github.com/miraheze/ManageWiki/blob/76da3dbc969673c27e8529f5c0ea25287e9f836f/includes/FormFactory/FormFactoryBuilder.php).

### 5. Update maintenance integrations and optional configuration

- `GenerateMissingCache.php` and `RebuildExtensionListCache.php` move from CreateWiki to ManageWiki. ManageWiki adds `ResetWikiCaches.php`; `ModifyGroupPermission.php` is renamed `ModifyGroupPermissions.php`. Update any external operational runbooks or schedules using old paths. The checked-in `docker/maintenance.json` only schedules the farm refresh wrapper, so that wrapper is the immediate scheduled dependency.
- Five bundled `MirahezeMagic` maintenance scripts still call `CreateWikiDataFactory`: `ResetWikiCaches.php`, `ResetWiki.php`, `FixProjectNS.php`, `RestoreManageWikiBackup.php`, and `ChangeMediaWikiVersion.php`. Migrate them before reuse. `MirahezeMagic` is commented out of the current global extension list; these are dormant compatibility debts, not evidence that normal requests execute them.
- If OpenAI-based request processing is enabled, migrate `CreateWikiOpenAIConfig` from `assistantid` to `model` and `instructions`; upstream uses the Responses API. The default remains dry-run. No AI service setup is required merely to update disabled functionality.
- Review new `CreateWikiClosedReasons` and `CreateWikiInactiveExemptExpiryOptions` settings. New request-abandonment and stale-request maintenance functionality is available. Keep automatic inactivity/deletion schedules an explicit operational choice.
- Check ManageWiki form rendering and catalogue descriptions against the latest upstream change from HTMLForm descriptor `help` to `help-raw`, including local `ManageWikiSettings.php` and `ManageWikiExtensions.php`. Catalogue `help` inputs remain supported; do not mechanically rename those configuration keys. Also test extensions with direction-specific enable/disable permission requirements.

## Suggested implementation sequence

1. Pin the upstream commits above, select core 1.46+ and PHP 8.4+ versions, and establish a compatible baseline extension/skin set. Use an isolated staging checkout/environment.
2. Port the cache integration, shared cache directory, remote `SiteConfiguration`, custom special pages and privacy permissions. Update Docker inputs, Composer artifacts and inventory together.
3. Restore a farm backup into isolated staging; preserve the current application image, database snapshot, uploads and configuration for rollback. Drain/pause writers and jobs during the eventual cutover. A schema-upgrade rollback should restore the matching database snapshot and old image together.
4. Run the explicit shared/per-wiki migrations, rebuild caches, and verify all registered wikis can bootstrap. Also run a separate fresh-install scenario to exercise the new installer task.
5. Run the acceptance checks below. Deploy the tested pinned versions and migrations together, then check queue health and metadata freshness before reopening writes.

## Acceptance checks before deployment

- Core/extension registration and PHP lint under the selected PHP 8.4+ runtime; relevant upstream PHPUnit suites in the upgraded core environment.
- Existing `scripts/farm smoke` and relevant `docker/tests` configuration, authentication, email, jobs, dump and production checks; rebuild both supported architectures.
- Create a public and a private wiki through the real UI; verify central records, requester permissions, database grants, worker provisioning, baseline/enabled-extension schemas, uploads and first requests.
- Save settings, enable/disable an extension, change namespaces and groups, refresh/restart workers, and confirm persistence across FPM/CLI. Include management of a remote wiki from meta.
- Test private/public transitions, unauthorized privacy changes, anonymous page/API/image access, and authorized members. Confirm intended roles can perform the new privacy action and local admins cannot delegate restricted farm rights.
- Exercise close/reopen, lock/unlock, deletion-state routing and custom domains. Verify closure reasons and existing/new exemption values survive cache regeneration.
- Run metadata refresh twice and confirm the hourly scheduler succeeds. Clear/rebuild extension-list caches, then restart FPM and verify no stale configuration returns.
- Test backup/restore with the new image and rehearse rollback to the matching old image/database snapshot.

These are required future validation steps, not tests claimed to have passed during this audit. No migrations or runtime upgrade tests were run.

## Option if core 1.45 must stay temporarily

The last first-parent snapshots whose manifests still declare compatibility with the current core/PHP are CreateWiki [`aa5048bd224a85eb36d5eb566b0f2e496b3593b4`](https://github.com/miraheze/CreateWiki/commit/aa5048bd224a85eb36d5eb566b0f2e496b3593b4) and ManageWiki [`a727c02b1bad736b0380e6fcd228a4d736b699e8`](https://github.com/miraheze/ManageWiki/commit/a727c02b1bad736b0380e6fcd228a4d736b699e8), both September 28, 2026. These are candidates for a separate compatibility evaluation, not tested recommendations or maintained release branches. They still include the datastore split and need the farm-integration migration. They do not satisfy a requirement to use the latest upstream versions.

## Scope and confidence

Confirmed by direct source/blob comparison: installed upstream identities, target commits, declared platform requirements, removed services/hooks, cache-directory fallback, local call sites, SQL deltas and the new privacy check. Deployment state and actual database schemas were not queried. Compatibility of the broader extension catalogue and external integrations remains to be established in staging. Existing verification documents describe the old stack and do not certify this upgrade.
