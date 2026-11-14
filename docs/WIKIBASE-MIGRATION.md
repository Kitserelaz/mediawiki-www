# Wikibase 1.46 migration

The local candidate ports Wikibase and WikibaseLexeme to their pinned MediaWiki 1.46 branches. It restores the upstream frontend source trees as ordinary files, adds the two components' Composer requirements to the farm lock, and removes the obsolete `wbt_type` installer entry and the old global-disable flags. No existing Wikibase data was migrated. The old Wikibase client-hook edits are superseded by the 1.46 implementation. See [migration provenance and test evidence](upgrade-wikibase-migration.json), [Wikibase source port](upgrade-wikibase-source.json), [Lexeme source port](upgrade-wikibaselexeme-source.json), and [translation contract resolutions](upgrade-wikibase-translation-contracts.json).

## Verification

Run the repeatable source-overlay checks with:

```sh
python3 scripts/test-wikibase-migration.py --source
```

The helper passed in source mode on 2026-10-26. It creates a uniquely named disposable Compose project with local storage, enables Repository, Client, Lexeme and UniversalLanguageSelector through ManageWiki on its fresh `testwiki`, runs the schema updater twice, checks component registration and runtime class loading, exercises item, property and Lexeme creation/read-back plus item updates, builds Wikibase ResourceLoader modules, and removes its own containers and volumes. Its write fixture refuses to run without an explicit development-only opt-in and `testwiki` target. The test is not a substitute for browser acceptance or a production rollout.

After building the final images, run `python3 scripts/test-wikibase-migration.py` without `--source` to check the immutable PHP image. A source-overlay pass does not verify that the image contains the same files. Build and test the web image and browser workflows separately. The attempted full image build on 2026-10-26 was stopped during context transfer due to prolonged Docker contention; it is not a passing build.

## Data bridge dependency audit

On 2026-10-26, `npm audit --package-lock-only --omit=dev` reported six advisories in the upstream data-bridge lockfile: five high and one moderate. The affected packages were `@vue/server-renderer`, `vue`, `nanoid`, `postcss`, `source-map-js`, and `uuid`. Audit results are time-sensitive. The 1.46 client defaults `dataBridgeEnabled` to false, but that default is not a permanent security control: investigate and remediate the affected dependencies and rebuild/test the shipped bridge bundle before enabling it or considering a production rollout. The candidate root Composer lock audit reported no advisories at that time. Development-dependency findings and full counts are also recorded in the migration evidence.
