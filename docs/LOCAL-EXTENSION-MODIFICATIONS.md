# Local extension modifications

This is an inventory of **known** local changes that need review when updating the vendored extensions. It is based on the source-port reports, patch files, migration records and the working-tree catalog repairs present on 2026-10-26. It is not a complete upstream comparison of every extension: absence from this list does not prove an extension is unmodified. A source-port report records differences against its stated baseline, including translations and packaging omissions; those differences are not all intentional product customizations.

## Behavioral and compatibility changes

| Extension | Local behavior or compatibility change | Evidence / update notes |
| --- | --- | --- |
| CodeEditor | Live autocompletion remains disabled and the status area's top border is retained. | [Source port](upgrade-codeeditor-source.json); [JavaScript patch](../patches/codeeditor-jquery.codeEditor.js.patch); [style patch](../patches/codeeditor-jquery.codeEditor.less.patch). Reconcile these with upstream changes rather than blindly reapplying them. |
| MultimediaViewer | Keep navigation hidden for a single image while using the updated `currentIndex`/`imageCount` interface. Existing author-metadata escaping is retained. | [Source port](upgrade-multimediaviewer-source.json); [metadata-panel patch](../patches/multimediaviewer-mmv.ui.metadataPanel.js.patch). Update caller and UI together. |
| OATHAuth | Preserve the custom virtual-domain schema migration logic in `src/Hook/UpdateTables.php` and five recorded translations. | [Source port](upgrade-oathauth-source.json); [migration history](UPGRADE-PROGRESS.md). Confirm dependency state independently; the source-port report's dependency field is historical. |
| Elastica | Its bundled `ruflin/elastica` PHP 7 `Match` class is adapted for PHP 8 using a string class alias. | [Patch and provenance](upgrade-elastica-source.json); [patch](../patches/elastica-php8-match.patch). The checkout verifier checks the patched hash. |
| EventLogging | Preserve the stylelint-cache preference and load its isolated Composer autoloader after core. | [Target record](upgrade-targets.json). Review lock and vendor contents when regenerating. |
| CreateWiki | Local upstream-test ports support isolated Docker tests with a password-protected administrator; see the individual entries for their other purposes. | [Test-port record](upgrade-upstream-test-ports.json). These are test changes, not evidence of changed production behavior. |
| Comments | A local patch updates maintenance method visibility for the 1.46 API. Its Echo mention formatter also has an uncommitted compatibility port to namespaced Echo classes. | [Maintenance patch](../patches/comments-mediawiki-146-maintenance-visibility.patch); current working-tree changes below. |
| DataTransfer, HighlightLinksInCategory, ImageRating, SemanticMediaWiki, Video, VoteNY | Legacy queries use the 1.46 linktarget/name-table and connection APIs while retaining query output conventions. | [Patch](../patches/mediawiki-146-linktarget-queries.patch); [per-file hashes and recorded test status](upgrade-legacy-schema-source.json). Do not infer a live-query pass for entries marked pending. |
| Maps | The target record documents retained 10.3.0 source, production dependency repairs, toolchain changes and loading its component autoloader after core. Recent uncommitted edits adapt its Semantic MediaWiki query classes. | [Dependency patch record](upgrade-targets.json); current working-tree changes below. |

## Working-tree compatibility edits from the catalog investigation

These edits were uncommitted when this inventory was written. Their presence in this list does not establish that a complete feature or release acceptance test passed. The first isolated registration sweep on 2026-10-26 passed 37 of the 40 previously unresolved entries for available dependencies. CentralNotice, GrowthExperiments, SimpleBlogPage and StandardDialogs reported absent optional providers. After the subsequent Wikibase source and dependency port, the three remaining Wikibase entries passed isolated registration; see [migration evidence](upgrade-wikibase-migration.json). A separate Maps coordinate-query check passed. The broader catalog sweep was stopped after 38 passing entries; it is not a full-catalog pass.

| Extension | Files and change to review on update |
| --- | --- |
| ArticlePlaceholder, Capiunto, SemanticScribunto, VariablesLua | Lua library classes import Scribunto's namespaced `LibraryBase`. |
| ExternalData | Its Lua library imports that base and returns the result of `registerInterface`. |
| UnlinkedWikibase | Its Lua library imports the namespaced Scribunto engine and base class. |
| Comments | `includes/notifications/EchoMentionCommentPresentationModel.php` uses namespaced Echo classes and the current formatter interface. |
| DynamicPageList3 | `maintenance/CreateTemplate.php` and `CreateView.php` expose `updateSkippedMessage` publicly to match core. |
| Maps | `src/SemanticMW/{AreaDescription,CoordinateDescription,CoordinateValue,KmlPrinter}.php` adapt Semantic MediaWiki return types and preserve coordinate-query parsing through a separate helper. |
| MobileFrontend | `includes/specials/SpecialMobileEditWatchlist.php` declares the `outputSubtitle` return type expected by core. |
| PageProperties | `includes/aliases/Html.php` uses the exact `MediaWiki` namespace casing. |
| Translate | `src/MessageBundleTranslation/MalformedBundle.php` implements the current message-interface return types. |
| WikimediaIncubator | Its manifest and handler use `GetMagicVariableIDs` in place of the removed magic-word hook. |
| Wikibase, WikibaseLexeme | Ported to pinned 1.46 sources with local translations reconciled and missing frontend source trees restored. Their root Composer dependencies were regenerated; the earlier partial client-hook edits are replaced by the corresponding upstream implementation. The data-bridge dependency audit and remaining release gates are recorded in [migration evidence](upgrade-wikibase-migration.json). |

The corresponding fixture and runtime-class scanner changes are in `docker/tests/catalogue-settings.php` and `docker/tests/catalogue-classes.php`. The scanner records absent optional integrations rather than treating them as exercised. These are test infrastructure changes, not extension source modifications.

## Other recorded differences from upstream

The following source-port reports record preserved differences for the named extension. The counts describe report entries, **not necessarily distinct intentional features**. Translation entries include any path containing an `i18n` directory. Other edits exclude translations and deletions. Deletions can overlap translation counts and often reflect the original distribution's packaging. The reports contain exact paths, baseline and target identifiers, and any recorded manual resolutions; use them for a file-by-file review.

| Extension | Recorded entries | Translation paths | Other non-deletion edits | Deletions | Source-port report |
| --- | ---: | ---: | ---: | ---: | --- |
| AbuseFilter | 39 | 26 | 0 | 13 | [upgrade-abusefilter-source.json](upgrade-abusefilter-source.json) |
| AdminLinks | 6 | 5 | 1 | 0 | [upgrade-adminlinks-source.json](upgrade-adminlinks-source.json) |
| CategoryTree | 26 | 17 | 0 | 9 | [upgrade-categorytree-source.json](upgrade-categorytree-source.json) |
| Cite | 120 | 102 | 0 | 18 | [upgrade-cite-source.json](upgrade-cite-source.json) |
| CiteThisPage | 38 | 28 | 1 | 10 | [upgrade-citethispage-source.json](upgrade-citethispage-source.json) |
| CodeEditor | 14 | 1 | 1 | 12 | [upgrade-codeeditor-source.json](upgrade-codeeditor-source.json) |
| CodeMirror | 34 | 33 | 1 | 0 | [upgrade-codemirror-source.json](upgrade-codemirror-source.json) |
| ConfirmEdit | 24 | 7 | 2 | 15 | [upgrade-confirmedit-source.json](upgrade-confirmedit-source.json) |
| DiscussionTools | 115 | 101 | 0 | 14 | [upgrade-discussiontools-source.json](upgrade-discussiontools-source.json) |
| Echo | 42 | 42 | 0 | 0 | [upgrade-echo-source.json](upgrade-echo-source.json) |
| Gadgets | 37 | 29 | 0 | 8 | [upgrade-gadgets-source.json](upgrade-gadgets-source.json) |
| ImageMap | 12 | 1 | 0 | 11 | [upgrade-imagemap-source.json](upgrade-imagemap-source.json) |
| InputBox | 11 | 2 | 0 | 9 | [upgrade-inputbox-source.json](upgrade-inputbox-source.json) |
| IPInfo | 33 | 33 | 0 | 0 | [upgrade-ipinfo-source.json](upgrade-ipinfo-source.json) |
| Linter | 31 | 23 | 0 | 8 | [upgrade-linter-source.json](upgrade-linter-source.json) |
| LoginNotify | 13 | 5 | 0 | 8 | [upgrade-loginnotify-source.json](upgrade-loginnotify-source.json) |
| Math | 26 | 7 | 1 | 18 | [upgrade-math-source.json](upgrade-math-source.json) |
| MultimediaViewer | 40 | 22 | 5 | 14 | [upgrade-multimediaviewer-source.json](upgrade-multimediaviewer-source.json) |
| Nuke | 60 | 49 | 1 | 10 | [upgrade-nuke-source.json](upgrade-nuke-source.json) |
| OATHAuth | 6 | 5 | 1 | 0 | [upgrade-oathauth-source.json](upgrade-oathauth-source.json) |
| PageImages | 11 | 3 | 0 | 8 | [upgrade-pageimages-source.json](upgrade-pageimages-source.json) |
| ParserFunctions | 16 | 7 | 1 | 8 | [upgrade-parserfunctions-source.json](upgrade-parserfunctions-source.json) |
| PdfHandler | 10 | 1 | 1 | 8 | [upgrade-pdfhandler-source.json](upgrade-pdfhandler-source.json) |
| Poem | 9 | 0 | 0 | 9 | [upgrade-poem-source.json](upgrade-poem-source.json) |
| ReplaceText | 18 | 5 | 0 | 13 | [upgrade-replacetext-source.json](upgrade-replacetext-source.json) |
| Scribunto | 2 | 0 | 2 | 0 | [upgrade-scribunto-source.json](upgrade-scribunto-source.json) |
| SecureLinkFixer | 8 | 0 | 0 | 8 | [upgrade-securelinkfixer-source.json](upgrade-securelinkfixer-source.json) |
| SpamBlacklist | 2 | 2 | 0 | 0 | [upgrade-spamblacklist-source.json](upgrade-spamblacklist-source.json) |
| SyntaxHighlight_GeSHi | 21 | 8 | 1 | 12 | [upgrade-syntaxhighlight_geshi-source.json](upgrade-syntaxhighlight_geshi-source.json) |
| TemplateData | 65 | 49 | 2 | 14 | [upgrade-templatedata-source.json](upgrade-templatedata-source.json) |
| TemplateStyles | 9 | 6 | 3 | 0 | [upgrade-templatestyles-source.json](upgrade-templatestyles-source.json) |
| TextExtracts | 12 | 3 | 1 | 8 | [upgrade-textextracts-source.json](upgrade-textextracts-source.json) |
| Thanks | 40 | 26 | 1 | 15 | [upgrade-thanks-source.json](upgrade-thanks-source.json) |
| TitleBlacklist | 22 | 12 | 1 | 9 | [upgrade-titleblacklist-source.json](upgrade-titleblacklist-source.json) |
| VisualEditor | 2606 | 601 | 1984 | 21 | [upgrade-visualeditor-source.json](upgrade-visualeditor-source.json) |
| Wikibase | 394 | 176 | 218 | 23 | [upgrade-wikibase-source.json](upgrade-wikibase-source.json) |
| WikibaseLexeme | 34 | 32 | 2 | 6 | [upgrade-wikibaselexeme-source.json](upgrade-wikibaselexeme-source.json) |
| WikiEditor | 80 | 68 | 1 | 11 | [upgrade-wikieditor-source.json](upgrade-wikieditor-source.json) |

The translation placeholder changes made for Cite and DiscussionTools are separately recorded in [upgrade-translation-contract-migrations.json](upgrade-translation-contract-migrations.json). Source reports with no `preserved_changes` are not listed here; they may still have dependency patches, later edits or unrecorded customizations. In particular, a report count of zero is not proof that replacing an extension wholesale is safe.

## Updating an extension

1. Identify and verify its actual upstream baseline and target commit. Review the relevant report, patches, working-tree diff, local translations, lockfiles and tracked runtime assets; also inspect related farm configuration.
2. Use the three-way source porter described in [VENDORED-SOURCES.md](VENDORED-SOURCES.md). Resolve conflicts explicitly and preserve intentional local behavior; never assume a report's historical baseline describes all later edits.
3. Reconcile Composer/npm locks and bundled dependencies with the rest of the farm. Refresh provenance and checksums when build inputs change. Test both registration and the affected feature, then run the appropriate broader regression and image checks.
4. Update this inventory and the relevant provenance report with the final source identity, changes and actual validation results.
