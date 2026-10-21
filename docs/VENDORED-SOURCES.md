# Reproducible farm sources

The farm ships extensions, skins, PHP dependencies and required browser assets as
ordinary tracked files. Docker builds use that checked-out source tree. They do
not depend on embedded repositories, an unpublished submodule commit, or files
left behind in a developer's working directory.

The original repository contained 249 Git links without `.gitmodules`. Those
links could not be reconstructed by CI. The upgrade converted their source and
bundled dependencies to tracked files. Original Git metadata and the original
index remain in the private local upgrade backup; they are not build inputs.
Local environment files, runtime databases, caches and `node_modules` are excluded.

Component upstream ignore rules can hide supplied runtime dependencies, Composer
locks and generated production assets. `upgrade-vendored-build-inputs.json`
records 5,973 such required supplemental files, including OATHAuth/WebAuthn
dependencies and MobileFrontend's compiled resources. They are deliberately
tracked despite those nested ignore rules. The checkout verifier checks their
presence, index entries and hashes, and rejects untracked supplied component
autoloaders. When replacing these dependency/asset trees, update this provenance
list and its hashes together with the source and locks; check for new ignored
runtime files and explicitly add required ones with `git add -f`.

`vendored-source-provenance.json` records the former Git-link identities and the
source fingerprint observed during conversion. Subsequent upgrade targets and
preserved local changes are recorded separately in `upgrade-targets.json` and
the component source-port reports. Conversion fingerprints are historical
provenance, not assertions that later edited source still has the same hash.

For future component upgrades, clone upstream outside the source tree and use
`scripts/upgrade-component.py` with an identified baseline, an immutable target
commit and a source-port report. Review conflicts and preserve local changes.
Regenerate affected Composer locks and bundled production dependencies with the
pinned toolchain, then run platform checks, audit and relevant regression gates.
Commit source files, locks, required runtime dependencies and provenance together.

Elastica's 7.3.2 dependency retains a PHP 7 compatibility class named `Match`, a
reserved keyword on PHP 8. After reinstalling that component's dependencies,
apply `git apply patches/elastica-php8-match.patch` from the repository root.
The patch uses a string class alias to retain dynamic legacy callers and the
existing MatchQuery implementation. Its original and patched hashes are recorded
in `upgrade-elastica-source.json`; the checkout verifier rejects an overwritten
patch. The PHP 8.4 regression checks construction and query serialization.

Femiwiki's production icon and OOUI assets are under its `resources/vendor/`
directory because Docker excludes `node_modules`. Their source package identities
come from its npm lockfile and are recorded in `upgrade-femiwiki-assets.json`;
the bundled packages retain their licenses. Updating those dependencies requires
refreshing these assets and compiling their ResourceLoader modules again.

`python3 scripts/verify-checkout.py` checks for missing catalogue/build inputs,
unreconstructable Git links, tracked local credentials and incorrectly cased core
autoload paths. A successful local image build alone does not prove a clean
checkout is reproducible: export the final tracked tree and build/test it too.

For an uncommitted candidate, `python3 scripts/export-clean-checkout.py OUTPUT`
creates a parentless snapshot in a temporary repository and clones it with
`--no-local`. It includes current tracked files and nonignored additions and
deletions, uses exact-case paths, and leaves the working checkout's index and
history unchanged. The resulting clone contains its own Git objects and runs the
checkout verifier before reporting success. Choose an unused output directory
under the ignored private backup directory. This validates a candidate snapshot;
it does not imply that the candidate has been committed or pushed.

Build commands, recorded tree identity, image identities and the limits of this
validation are documented in `UPGRADE-BUILD-VERIFICATION.md`.
