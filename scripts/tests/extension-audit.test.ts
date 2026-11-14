import { afterEach, expect, test } from "bun:test";
import { mkdtempSync, mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { tmpdir } from "node:os";
import { markdown, publish } from "../audit-extension-updates";
import { Compatibility, mergeProject } from "../extension-audit/compatibility";
import { fileTree, fingerprint } from "../extension-audit/common";
import type { Component, FileEntry } from "../extension-audit/common";
import { inventory, registrations } from "../extension-audit/inventory";
import type { Inventory } from "../extension-audit/inventory";
import { compareTrees, refsFromRemote, revisions, Upstream } from "../extension-audit/upstream";

const scratch: string[] = [];
function temporary() {
  const path = mkdtempSync(join(tmpdir(), "test-extension-audit-"));
  scratch.push(path);
  return path;
}
afterEach(() => {
  for (const path of scratch.splice(0)) rmSync(path, { recursive: true, force: true });
});
const entry = (hash: string, mode = "100644"): FileEntry => ({ hash, mode });
const tree = (files: Record<string, string>) => new Map(Object.entries(files).map(([path, hash]) => [path, entry(hash)]));
const component = (extra: Partial<Component> = {}): Component => ({
  name: "Example", path: "mediawiki/extensions/Example", manifests: { "extension.json": { name: "Example" } },
  catalogKeys: [], loadTargets: [], rule: {}, ...extra,
});

test("selects stable tags, dereferences annotated tags and ignores prereleases", () => {
  const sha = (n: string) => n.repeat(40);
  const listing = [
    "ref: refs/heads/main\tHEAD", sha("a") + "\tHEAD", sha("b") + "\trefs/heads/REL1_46",
    sha("c") + "\trefs/tags/v2.2", sha("d") + "\trefs/tags/v2.2^{}",
    sha("e") + "\trefs/tags/v2.10", sha("f") + "\trefs/tags/v3.0-rc",
  ].join("\n");
  const remote = refsFromRemote(listing);
  expect(remote.defaultRef).toBe("refs/heads/main");
  expect(revisions(remote, "REL1_46", component()).map(revision => revision.commit)).toEqual([sha("b")]);
  expect(revisions(remote, "REL1_46", component({ rule: { channel: "tags" } }))).toEqual([
    { ref: "v2.10", commit: sha("e"), version: "2.10" }, { ref: "v2.2", commit: sha("d"), version: "2.2" },
  ]);
  expect(revisions(remote, "REL1_47", component())[0].ref).toBe("v2.10");
});

test("distinguishes missing files, backports, diverged code, and patch overlap", () => {
  const ancestor = tree({ "extension.json": "a", "code.php": "b", "backport.php": "e", "patch.php": "p" });
  const upstream = tree({ "extension.json": "a", "code.php": "c", "backport.php": "f", "patch.php": "q" });
  const branch = tree({ "extension.json": "a", "code.php": "b", "backport.php": "f", "patch.php": "p" });
  const local = tree({ "extension.json": "a", "code.php": "b", "backport.php": "f", "patch.php": "local" });
  expect(compareTrees(local, upstream, branch, ancestor)).toMatchObject({
    status: "new", missingChanges: ["code.php"], overlap: ["patch.php"],
  });
  expect(compareTrees(upstream, upstream, branch, ancestor).status).toBe("current");
  expect(compareTrees(local, upstream).status).toBe("unclear");
  const removed = tree({ "extension.json": "a" });
  expect(compareTrees(ancestor, removed, ancestor).missingChanges).toContain("code.php");
});

test("intersects Composer requirements with OR ranges", () => {
  const project = mergeProject({ require: { "lib/core": "^2 || ^3", "lib/shared": "^1" } }, [
    { require: { "lib/core": ">=2.5 || ~1.9", "lib/other": "^4" } }, { require: { "lib/shared": "*" } },
  ]);
  expect(project.require).toEqual({
    "lib/core": "^2, >=2.5 || ^2, ~1.9 || ^3, >=2.5 || ^3, ~1.9",
    "lib/shared": "^1", "lib/other": "^4",
  });
});

test("token reader keeps catalog scopes and recognizes literal conditional loads", () => {
  const t = (value: string) => ["", value] as [string, string];
  const s = (value: string) => ["T_CONSTANT_ENCAPSED_STRING", "'" + value + "'"] as [string, string];
  const parsed = registrations({
    fixture: [
      t("$wgManageWikiExtensions"), t("="), t("["), s("repo"), t("=>"), t("["),
      s("name"), t("=>"), s("WikibaseRepository"), t(","),
      s("requires"), t("=>"), t("["), s("name"), t("=>"), s("ignored"), t("]"), t("]"), t("]"), t(";"),
      t("$farmExtensions"), t("="), t("["), s("Extension"), t("]"), t(";"),
      ["T_STRING", "wfLoadExtension"], t("("), s("../extensions_staging/Example"), t(")"), t(";"),
    ],
  });
  expect(parsed.catalog.get("repo")).toEqual({ name: "WikibaseRepository", skin: false });
  expect([...parsed.loads]).toEqual(["Extension", "../extensions_staging/Example"]);
});

test("compares commits and manifests from an offline Git repository", async () => {
  const d = temporary();
  const upstream = join(d, "upstream");
  const work = join(d, "scratch");
  mkdirSync(upstream); mkdirSync(work);
  const git = (...args: string[]) => {
    const result = Bun.spawnSync(["git", ...args], { cwd: upstream });
    if (result.exitCode) throw new Error(result.stderr.toString());
    return result.stdout.toString().trim();
  };
  git("init", "-b", "REL1_46");
  git("config", "user.name", "Fixture");
  git("config", "user.email", "fixture@example.test");
  writeFileSync(join(upstream, "extension.json"), JSON.stringify({ name: "Example", requires: { MediaWiki: ">=1.46" } }));
  writeFileSync(join(upstream, "code.php"), "old");
  git("add", "."); git("commit", "-m", "baseline");
  const baseline = git("rev-parse", "HEAD");
  const local = fileTree(upstream);
  writeFileSync(join(upstream, "code.php"), "new");
  git("add", "."); git("commit", "-m", "update");
  const info = new Upstream(new URL("file://" + upstream).href, work, 0, true);
  await info.open("REL1_46", []);
  const fixture = component({ baseline, baselineKind: "test baseline" });
  const [target] = revisions(info.remote, "REL1_46", fixture);
  expect(await info.fetch(target.commit)).toBe(true);
  expect((await info.metadata(fixture, target))["extension.json"].requires).toEqual({ MediaWiki: ">=1.46" });
  expect(await info.evidence(fixture, local, target)).toMatchObject({ status: "new", missingChanges: ["code.php"] });
}, 15_000);

const php = process.env.EXTENSION_AUDIT_TEST_PHP ?? Bun.which("php");
test.skipIf(!php)("inventories split registrations, legacy entrypoints, duplicate copies and missing catalog keys", async () => {
  const d = temporary();
  const files: Record<string, string> = {
    "docs/upgrade-targets.json": '{"extensions":{}}',
    "docs/upgrade-catalogue-baseline.json": '{"components":[]}',
    "docs/upgrade-catalogue-nonstandard-baseline.json": '{"components":[]}',
    "docs/vendored-source-provenance.json": '{"components":[]}',
    "docker/mediawiki/Dockerfile": "ARG PHP_IMAGE=php:8.4.27-fpm-bookworm",
    "docker/mediawiki/bootstrap.php": "<?php",
    "mediawiki/includes/Defines.php": "<?php define( 'MW_VERSION', '1.46.2' );",
    "mediawiki/GlobalSettings.php": '<?php require_once "$IP/extensions/Legacy/Legacy.php";',
    "mediawiki/GlobalExtensions.php": "<?php wfLoadExtensions(['Wikibase']);",
    "mediawiki/ManageWikiExtensions.php": "<?php $wgManageWikiExtensions = ['repo'=>['name'=>'WikibaseRepository'],'missing'=>['name'=>'Missing']];",
    "mediawiki/LocalSettings.php": "<?php wfLoadExtension('../extensions_staging/Wikibase');",
    "mediawiki/composer.lock": '{"packages":[]}',
    "mediawiki/extensions/Wikibase/extension-client.json": '{"name":"WikibaseClient"}',
    "mediawiki/extensions/Wikibase/extension-repo.json": '{"name":"WikibaseRepository"}',
    "mediawiki/extensions/Legacy/Legacy.php": "<?php",
    "mediawiki/extensions_staging/Wikibase/extension.json": '{"name":"WikibaseRepository"}',
    "rules.json": '{"legacy":[],"components":{},"platform":{"imageFamily":"-fpm-bookworm","coreModules":[]}}',
  };
  for (const [name, text] of Object.entries(files)) {
    mkdirSync(join(d, name, ".."), { recursive: true });
    writeFileSync(join(d, name), text);
  }
  const result = await inventory(d, php!, join(d, "rules.json"));
  expect(result.components.map(item => item.path).sort()).toEqual([
    "mediawiki/extensions/Legacy", "mediawiki/extensions/Wikibase", "mediawiki/extensions_staging/Wikibase",
  ]);
  expect(result.components.find(item => item.path === "mediawiki/extensions/Wikibase")?.catalogKeys).toEqual(["repo"]);
  expect(result.components.find(item => item.path === "mediawiki/extensions/Wikibase")?.loadTargets).toEqual(["Wikibase"]);
  expect(result.manifestCount).toBe(3);
  expect(result.catalogCount).toBe(2);
  expect(result.missing.map(entry => entry.key)).toEqual(["missing"]);
  expect(result.fingerprint).toBe(fingerprint(result.trees, result.files));
  writeFileSync(join(d, "mediawiki/extensions/Legacy/Legacy.php"), "<?php // edit");
  expect((await inventory(d, php!, join(d, "rules.json"))).fingerprint).not.toBe(result.fingerprint);
}, 15_000);

test("Composer uses an isolated project, preserves the lock, and distinguishes conflict from timeout", async () => {
  const d = temporary();
  const directory = join(d, "mediawiki/extensions/Example");
  mkdirSync(directory, { recursive: true });
  const lock = '{"packages":[{"name":"vendor/old","version":"1"}]}';
  writeFileSync(join(directory, "composer.lock"), lock);
  const fake = join(d, "fake-composer");
  writeFileSync(fake, "#!/bin/sh\ncase \"$*\" in\n*vendor/conflict*) echo 'could not be resolved to an installable set' >&2; exit 2;;\n*vendor/network*) echo 'curl error: timed out' >&2; exit 2;;\nesac\ntest -f composer.json && grep -q 8.4.27 composer.json && echo '  - Locking vendor/new (2.0)'\n", { mode: 0o700 });
  const input = { root: d, platform: { php: "8.4.27" }, components: [], skins: new Map() } as unknown as Inventory;
  const compatibility = new Compatibility(input, "php", fake, d, [], {});
  const item = component();
  for (const [name, status] of [["vendor/new", "resolved"], ["vendor/conflict", "conflict"], ["vendor/network", "unverified"]] as const) {
    const checks = [{ type: "package" as const, name, constraint: "^2", result: false }];
    const result = await compatibility.resolve(item, { require: { [name]: "^2" } }, { ref: "REL1_46", commit: "a".repeat(40) }, checks);
    expect(result.status).toBe(status);
    if (status === "resolved") expect(result.changes).toEqual(["Locking vendor/new (2.0)"]);
  }
  expect(readFileSync(join(directory, "composer.lock"), "utf8")).toBe(lock);
});

test("interrupted or failed writes retain the prior report", () => {
  const dir = temporary();
  const output = join(dir, "report.md");
  writeFileSync(output, "prior");
  expect(() => publish(output, "replacement", () => { throw new Error("interrupted"); })).toThrow("interrupted");
  expect(readFileSync(output, "utf8")).toBe("prior");
  expect(readdirSync(dir)).toEqual(["report.md"]);
  publish(output, "complete");
  expect(readFileSync(output, "utf8")).toBe("complete");
});

test("picks the highest compatible update and renders the same counts as JSON", async () => {
  const { decide } = await import("../audit-extension-updates");
  const item = component({ version: "1.0.0" });
  const dev = { components: [item], missing: [] } as unknown as Inventory;
  const source = {
    status: "new" as const, matchedFiles: 2, comparableFiles: 2,
    missingChanges: ["code.php"], overlap: [], reason: "Older code found.",
  };
  const candidates = ["3.0.0", "2.0.0"].map((ref, index) => ({
    revision: { ref, version: ref, commit: String(index + 1).repeat(40) },
    url: "https://example.test/commit/" + String(index + 1).repeat(40), source,
    metadata: { "extension.json": { requires: { MediaWiki: index ? ">=1.46" : ">=1.47" } } }, checks: [],
  }));
  const checker = {
    checks: (_component: Component, metadata: typeof candidates[number]["metadata"]) => [{
      type: "core", name: "MediaWiki", constraint: metadata["extension.json"].requires.MediaWiki, actual: "1.46.2",
    }],
    evaluate: async (checks: { constraint: string; result?: boolean }[]) => {
      for (const check of checks) check.result = check.constraint === ">=1.46";
    },
  } as unknown as Compatibility;
  const entries = await decide(dev, new Map([[item.path, candidates]]), new Map(), checker, 1);
  expect(entries[0].candidate?.revision.ref).toBe("2.0.0");
  expect(entries[0].disposition).toBe("update available");
  const report = {
    schemaVersion: 1 as const, checkedAt: "2026-11-13T01:00:00.000Z", commit: "a".repeat(40), fingerprint: "b".repeat(64),
    target: { mediaWiki: "1.46.2", php: "8.4.27", releaseBranch: "REL1_46" },
    coverage: { packages: 1, manifests: 1, catalog: 0, skins: 0 }, diagnostics: [], entries,
    totals: { "update available": 1, "current on supported channel": 0, blocked: 0, "local-only": 0, "source missing": 0, unverified: 0 },
  };
  const raw = JSON.parse(JSON.stringify(report));
  expect(raw.totals["update available"]).toBe(1);
  expect(markdown(raw)).toContain("**1 update available**");
  expect(markdown(raw)).toContain(candidates[1].url);
});

test.skipIf(!php)("checks installed skin constraints and the core Composer pin", async () => {
  const d = temporary();
  mkdirSync(join(d, "mediawiki"), { recursive: true });
  writeFileSync(join(d, "mediawiki/composer.json"), '{"require":{"wikimedia/parsoid":"0.23.1"}}');
  const semver = join(import.meta.dir, "../../mediawiki/vendor/composer/semver");
  mkdirSync(join(d, "mediawiki/vendor/composer"), { recursive: true });
  const result = Bun.spawnSync(["ln", "-s", semver, join(d, "mediawiki/vendor/composer/semver")]);
  expect(result.exitCode).toBe(0);
  const input = {
    root: d, core: "1.46.2", platform: { php: "8.4.27" }, components: [],
    skins: new Map([["chameleon", { name: "Chameleon", requires: { extensions: { Bootstrap: "~5.0" } } }]]),
  } as unknown as Inventory;
  const checker = new Compatibility(input, php!, "composer", d, [], {});
  const sha = "a".repeat(40);
  const bootstrap = checker.checks(component(), { "extension.json": { name: "Bootstrap", version: "6.0.1" } },
    { ref: "6.0.1", commit: sha, version: "6.0.1" });
  const parsoid = checker.checks(component({ lock: { name: "wikimedia/parsoid", version: "v0.23.1" }, rule: { respectCorePin: true } }),
    { "extension.json": { name: "Parsoid" } }, { ref: "v0.24.0", commit: sha, version: "0.24.0" });
  await checker.evaluate([...bootstrap, ...parsoid]);
  expect(bootstrap).toMatchObject([{ type: "skin", name: "Chameleon", result: false }]);
  expect(parsoid).toMatchObject([{ type: "core", name: "wikimedia/parsoid", result: false }]);
});
