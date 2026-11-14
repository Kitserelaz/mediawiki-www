#!/usr/bin/env bun
import { existsSync, lstatSync, mkdtempSync, realpathSync, renameSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { parseArgs } from "node:util";
import { Compatibility, composerChanged } from "./extension-audit/compatibility";
import type { Check, DependencyResolution } from "./extension-audit/compatibility";
import { command, errorText, parallel, parse, stopCommands, webLink } from "./extension-audit/common";
import type { Component, Manifest } from "./extension-audit/common";
import { inventory } from "./extension-audit/inventory";
import type { Inventory } from "./extension-audit/inventory";
import { compareVersions, revisions, Upstream, versionParts } from "./extension-audit/upstream";
import type { Revision, SourceEvidence } from "./extension-audit/upstream";

type Disposition = "update available" | "current on supported channel" | "blocked" | "local-only" | "source missing" | "unverified";
interface Candidate {
  revision: Revision;
  url: string;
  source: SourceEvidence;
  metadata: Record<string, Manifest>;
  checks: Check[];
  composer?: DependencyResolution;
}
interface Result {
  name: string;
  path?: string;
  catalogKeys: string[];
  loadTargets: string[];
  aliases: string[];
  localVersion?: string;
  repository?: string;
  disposition: Disposition;
  reason: string;
  notes?: string;
  evidence?: string;
  candidate?: Omit<Candidate, "metadata">;
  coordinated?: string[];
  blockedCandidate?: { ref: string; url: string; reason: string };
}
interface Report {
  schemaVersion: 1;
  checkedAt: string;
  commit: string;
  fingerprint: string;
  target: { mediaWiki: string; php: string; releaseBranch: string };
  coverage: { packages: number; manifests: number; catalog: number; skins: number };
  diagnostics: string[];
  totals: Record<Disposition, number>;
  entries: Result[];
}
const dispositions: Disposition[] = ["update available", "current on supported channel", "blocked", "local-only", "source missing", "unverified"];
const help = `Usage: bun run scripts/audit-extension-updates.ts [options]

  --format markdown|json   Output format (default: markdown)
  --output PATH            Atomically replace the requested report
  --php PATH               PHP CLI executable (default: php on PATH)
  --composer PATH          Composer command or composer.phar (default: composer on PATH)
  --workers N              Concurrent upstream repositories (default: 12, range: 1–24)
  --help                   Show usage
`;

function releaseFits(component: Component, revision: Revision, source: SourceEvidence): boolean {
  if (source.status !== "new") return true;
  const local = versionParts(component.version);
  const candidate = versionParts(revision.version);
  return !local || !candidate || compareVersions(candidate, local) >= 0;
}

async function discover(input: Inventory, directory: string, workers: number): Promise<{
  candidates: Map<string, Candidate[]>; errors: Map<string, string>;
}> {
  const groups = new Map<string, Component[]>();
  const candidates = new Map<string, Candidate[]>();
  const errors = new Map<string, string>();
  for (const item of input.components) {
    if (item.rule.localOnly) continue;
    if (!item.repository) {
      errors.set(item.path, "No public repository could be identified from local provenance or manifests.");
      continue;
    }
    if (!groups.has(item.repository)) groups.set(item.repository, []);
    groups.get(item.repository)!.push(item);
  }
  let completed = 0;
  const started = Date.now();
  await parallel([...groups].map(([repository, items], index) => ({ repository, items, index })), workers, async ({ repository, items, index }) => {
    try {
      const remote = new Upstream(repository, directory, index);
      await remote.open(input.release, [...new Set(items.flatMap(item => item.rule.branch ? [item.rule.branch] : []))]);
      for (const item of items) {
        const choices = revisions(remote.remote, input.release, item);
        if (!choices.length) {
          errors.set(item.path, "No stable tag or verified release branch was found. Confirm the publisher's supported channel.");
          continue;
        }
        const found: Candidate[] = [];
        // Bound history and tag inspection on projects that publish large release series.
        const localVersion = versionParts(item.version);
        const localRelease = choices.find(choice => choice.version && localVersion &&
          compareVersions(versionParts(choice.version)!, localVersion) === 0);
        const selected = [...choices.slice(0, 8), ...choices.filter(choice => !choice.version),
          ...(localRelease ? [localRelease] : [])];
        for (const revision of [...new Map(selected.map(choice => [choice.commit, choice])).values()]) {
          const olderTag = revision.version && localVersion && compareVersions(versionParts(revision.version)!, localVersion) < 0;
          if (olderTag && found.length) continue;
          try {
            if (!await remote.fetch(revision.commit)) throw new Error("Git could not fetch the advertised commit");
            const metadata = await remote.metadata(item, revision);
            if (!item.legacy && !Object.keys(metadata).some(path => path.split("/").at(-1)?.startsWith("extension"))) {
              throw new Error("No extension registration manifest at the candidate commit");
            }
            const source = await remote.evidence(item, input.trees.get(item.path)!, revision);
            if (olderTag && source.status !== "current") continue;
            if (releaseFits(item, revision, source)) found.push({
              revision, url: webLink(repository, revision.commit), source, metadata, checks: [],
            });
            // The highest stable candidate can be settled here if its source already matches.
            if (source.status === "current" && found.length === 1 && !item.rule.channel) break;
          } catch (error) {
            errors.set(item.path, errorText(error));
          }
        }
        if (found.length) candidates.set(item.path, found);
        else if (!errors.has(item.path)) errors.set(item.path, "Published release versions are older than the checkout. Confirm the local branch or fork.");
      }
    } catch (error) {
      for (const item of items) errors.set(item.path, "Upstream lookup failed: " + errorText(error));
    } finally {
      completed++;
      if (completed === groups.size || (completed % 25 === 0 && Date.now() - started > 1000)) {
        console.error(`Checked ${completed}/${groups.size} upstream repositories`);
      }
    }
  });
  return { candidates, errors };
}

function candidateVersion(candidate: Candidate, name: string): string | undefined {
  const manifest = Object.values(candidate.metadata).find(value => value.name?.toLowerCase() === name.toLowerCase());
  return manifest?.version ?? candidate.metadata["extension.json"]?.version ?? candidate.revision.version;
}

const unresolved = (check: Check): string =>
  `${check.name} ${check.constraint} (farm: ${check.actual ?? "unavailable"})${check.detail ? " — " + check.detail : ""}`;

export async function decide(input: Inventory, candidates: Map<string, Candidate[]>, errors: Map<string, string>,
  compatibility: Compatibility, workers: number): Promise<Result[]> {
  const checks: Check[] = [];
  for (const component of input.components) {
    for (const candidate of candidates.get(component.path) ?? []) {
      candidate.checks = compatibility.checks(component, candidate.metadata, candidate.revision);
      checks.push(...candidate.checks);
    }
  }
  await compatibility.evaluate(checks);
  const localRequirements = (candidate: Candidate) => candidate.checks.filter(check => check.result !== true);
  const composerCheck = (check: Check) => check.type === "package" || (check.type === "conflict" && check.name.includes("/"));
  const results = await parallel(input.components, Math.min(4, workers), async (item): Promise<Result> => {
    const base = {
      name: item.name, path: item.path, localVersion: item.version, repository: item.repository,
      catalogKeys: item.catalogKeys, loadTargets: item.loadTargets,
      aliases: [...new Set(Object.values(item.manifests).flatMap(m => m.name && m.name !== item.name ? [m.name] : []))],
      notes: item.rule.notes, evidence: item.rule.evidence,
    };
    const options = candidates.get(item.path) ?? [];
    const result = (disposition: Disposition, reason: string, choice?: Candidate, coordinated?: string[]): Result => {
      if (!choice) return { ...base, disposition, reason };
      const { metadata, ...candidate } = choice;
      return { ...base, disposition, reason, candidate, coordinated };
    };
    if (item.rule.localOnly) return result("local-only", item.rule.localOnly);
    const blocker = item.rule.blocked;
    let fallback: Result | undefined;
    let unverified: Result | undefined;
    for (const candidate of options) {
      if (candidate.source.status === "current") {
        if (blocker && /archived|retired|superseded/i.test(blocker)) return result("blocked", blocker, candidate);
        if (candidate.checks.some(check => check.type === "core" && check.result !== true)) continue;
        if (unverified) continue;
        const entry = result("current on supported channel", candidate.source.reason, candidate);
        if (fallback?.candidate) entry.blockedCandidate = {
          ref: fallback.candidate.revision.ref, url: fallback.candidate.url, reason: fallback.reason,
        };
        return entry;
      }
      if (blocker) {
        fallback ??= result("blocked", blocker, candidate);
        continue;
      }
      if (candidate.source.status === "unclear") {
        unverified ??= result("unverified", candidate.source.reason, candidate);
        continue;
      }
      const incomplete = localRequirements(candidate);
      const partOfPlan: string[] = [];
      const missing = [];
      for (const check of incomplete.filter(check => !composerCheck(check))) {
        const provider = check.type === "extension" && check.provider ? input.components.find(entry => entry.path === check.provider) : undefined;
        let replacement: Candidate | undefined;
        if (provider && !provider.rule.blocked && provider.path !== item.path) {
          for (const proposed of candidates.get(provider.path) ?? []) {
            if (proposed.source.status !== "new" || proposed.checks.some(req => req.result !== true)) continue;
            const version = candidateVersion(proposed, check.name);
            if (!version) continue;
            const proposedCheck: Check = { ...check, actual: version };
            await compatibility.evaluate([proposedCheck]);
            if (proposedCheck.result === true) { replacement = proposed; break; }
          }
        }
        if (replacement) partOfPlan.push(`Update ${check.name} to ${candidateVersion(replacement, check.name)} first (${replacement.url}).`);
        else missing.push(check);
      }
      if (missing.length) {
        const known = missing.some(check => check.result === false);
        const fail = result(known ? "blocked" : "unverified", missing.map(unresolved).join(", "), candidate);
        if (known) fallback ??= fail;
        else unverified ??= fail;
        continue;
      }
      const composer = candidate.metadata["composer.json"];
      const changes = incomplete.filter(composerCheck);
      if (changes.length || composerChanged(item.composer, composer)) {
        candidate.composer = await compatibility.resolve(item, composer ?? {}, candidate.revision, changes);
        if (candidate.composer.status !== "resolved") {
          const conflict = candidate.composer.status === "conflict";
          const fail = result(conflict ? "blocked" : "unverified", candidate.composer.detail, candidate);
          if (conflict) fallback ??= fail;
          else unverified ??= fail;
          continue;
        }
      }
      return result("update available", candidate.source.reason, candidate, partOfPlan);
    }
    return fallback ?? unverified ?? result(blocker ? "blocked" : "unverified",
      blocker ?? errors.get(item.path) ?? "Could not establish a supported update.");
  });
  results.push(...input.missing.map(entry => ({
    name: entry.name, catalogKeys: [entry.key], loadTargets: [], aliases: [],
    disposition: entry.reason.startsWith("Ambiguous") ? "unverified" as const : "source missing" as const, reason: entry.reason,
  })));
  return results.sort((a, b) => a.name.localeCompare(b.name, "en", { sensitivity: "base" }) || (a.path ?? "").localeCompare(b.path ?? "", "en"));
}

const code = (value: string) => "`" + value.replaceAll("`", "'") + "`";
const display = (entry: Result) => entry.name + (entry.path?.startsWith("mediawiki/extensions_staging/") ? " (staging)"
  : entry.path?.startsWith("mediawiki/skins/") ? " (skin-bundled copy)" : entry.path?.startsWith("mediawiki/vendor/") ? " (Composer copy)" : "");
const table = (...values: string[]) => "| " + values.map(value => value.replaceAll("|", "\\|").replace(/\s+/g, " ").trim()).join(" | ") + " |";
function candidateCell(entry: Result): string {
  const item = entry.candidate;
  return item ? `${item.revision.ref} [${code(item.revision.commit.slice(0, 12))}](${item.url})` : "—";
}
function localCell(entry: Result): string {
  const base = entry.candidate?.source;
  return [entry.localVersion && code(entry.localVersion), base?.baseline && `${code(base.baseline.slice(0, 12))} (${base.baselineKind})`]
    .filter(Boolean).join(" · ") || "—";
}
export function markdown(report: Report): string {
  const { coverage, totals } = report;
  const available = totals["update available"];
  const output = ["# MediaWiki extension update audit", "",
    `Checked ${report.checkedAt} for MediaWiki **${report.target.mediaWiki}** and PHP **${report.target.php}**.`,
    `Checkout ${code(report.commit)}. Audited-input SHA-256 ${code(report.fingerprint)}.`, "",
    `**${available} ${available === 1 ? "update" : "updates"} available** across **${coverage.packages} local extension packages or copies**. ${totals.unverified} copies need more evidence.`,
    "", "## Available updates", "",
    table("Extension", "Local source", "Candidate", "Compatibility and integration"), table("---", "---", "---", "---")];
  for (const entry of report.entries.filter(entry => entry.disposition === "update available")) {
    const candidate = entry.candidate!;
    const checks = candidate.checks.filter(check => ["core", "platform", "extension", "skin"].includes(check.type));
    const info = [checks.map(check => `${check.name} ${check.constraint}`).join(", "),
      candidate.source.missingChanges.length ? "Changed upstream: " + candidate.source.missingChanges.slice(0, 3).map(code).join(", ") + "." : "",
      candidate.source.overlap.length ? "Review overlap: " + candidate.source.overlap.slice(0, 3).map(code).join(", ") + "." : "",
      candidate.composer?.detail, ...(candidate.composer?.changes ?? []), ...(entry.coordinated ?? []), entry.notes,
    ].filter(Boolean).join(" ");
    output.push(table(display(entry), localCell(entry), candidateCell(entry), info || entry.reason));
  }
  output.push("", "## Full inventory", "",
    `Reconciled ${coverage.manifests} manifests and ${coverage.catalog} ManageWiki catalog entries, including ${coverage.skins} skin entries.`,
    "", table("Disposition", "Count"), table("---", "---:"));
  for (const disposition of dispositions) output.push(table(disposition, String(totals[disposition])));
  output.push("", table("Extension", "Checkout path", "Catalog, registrations and load targets", "Local source", "Disposition", "Evidence"), table("---", "---", "---", "---", "---", "---"));
  for (const entry of report.entries) {
    const blocked = entry.blockedCandidate;
    const notes = [candidateCell(entry) === "—" ? undefined : candidateCell(entry), entry.reason,
      blocked && `Newer candidate [${blocked.ref}](${blocked.url}) is blocked. ${blocked.reason}`, entry.notes,
      entry.evidence?.startsWith("https://") ? `[Source policy](${entry.evidence})` : entry.evidence && code(entry.evidence)];
    output.push(table(display(entry), entry.path ? code(entry.path) : "—",
      [...new Set([...entry.catalogKeys, ...entry.aliases, ...entry.loadTargets])].map(code).join(", ") || "—",
      localCell(entry), entry.disposition, notes.filter(Boolean).join(" ")));
  }
  if (report.diagnostics.length) output.push("", "## Inventory diagnostics", "", ...report.diagnostics.map(value => "- " + value));
  output.push("", "Composer dry runs model the farm PHP platform when requirements change. Runtime registration, migrations and smoke tests were outside this audit.", "");
  return output.join("\n");
}

export async function report(options: { php: string; composer: string; workers: number }): Promise<Report> {
  const root = resolve(import.meta.dir, "..");
  const rules = join(import.meta.dir, "extension-audit/rules.json");
  const [git, phpInfo] = await Promise.all([
    command(["git", "rev-parse", "HEAD"], { cwd: root }),
    command([options.php, "-r", "echo json_encode(get_loaded_extensions());"]),
  ]);
  if (git.code || !/^[a-f0-9]{40}$/.test(git.stdout.trim())) throw new Error("Unable to identify checkout HEAD");
  if (phpInfo.code) throw new Error("Unable to inspect PHP CLI modules");
  const scratch = mkdtempSync(join(tmpdir(), "mediawiki-extension-audit-"));
  try {
    const prefix = options.composer.endsWith(".phar") ? [options.php, options.composer] : [options.composer];
    const composer = await command([...prefix, "--no-plugins", "--no-scripts", "show", "--platform", "--format=json"],
      { cwd: scratch, env: { HOME: scratch, COMPOSER_HOME: scratch, COMPOSER_NO_INTERACTION: "1" } });
    if (composer.code) throw new Error("Unable to inspect Composer platform: " + errorText(composer.stderr));
    const packages = parse<{ platform: { name: string; version: string }[] }>(composer.stdout).platform;
    const composerPlatform = Object.fromEntries(packages.filter(item => /^composer(?:-|$)/.test(item.name)).map(item => [item.name, item.version]));
    const hostModules = parse<string[]>(phpInfo.stdout).map(name => "ext-" + name.toLowerCase().replaceAll(" ", "-"));
    const input = await inventory(root, options.php, rules);
    console.error(`Inventoried ${input.components.length} extension packages or distinct copies`);
    const compatibility = new Compatibility(input, options.php, options.composer, scratch, hostModules, composerPlatform);
    const { candidates, errors } = await discover(input, scratch, options.workers);
    console.error("Checking compatibility and resolving dependency changes");
    const entries = await decide(input, candidates, errors, compatibility, options.workers);
    const verification = await inventory(root, options.php, rules);
    const last = await command(["git", "rev-parse", "HEAD"], { cwd: root });
    if (last.code || last.stdout !== git.stdout || verification.fingerprint !== input.fingerprint) {
      throw new Error("Checkout changed during the audit. Run again to generate a consistent report.");
    }
    const totals = Object.fromEntries(dispositions.map(label => [label, entries.filter(entry => entry.disposition === label).length])) as Report["totals"];
    return { schemaVersion: 1, checkedAt: new Date().toISOString(), commit: git.stdout.trim(), fingerprint: input.fingerprint,
      target: { mediaWiki: input.core, php: input.php, releaseBranch: input.release },
      coverage: { packages: input.components.length, manifests: input.manifestCount, catalog: input.catalogCount, skins: input.catalogSkins },
      diagnostics: input.diagnostics, totals, entries };
  } finally {
    rmSync(scratch, { recursive: true, force: true });
  }
}

export function publish(path: string, contents: string, beforeRename?: () => void): void {
  const target = resolve(path);
  if (existsSync(target) && !lstatSync(target).isFile()) throw new Error("Output path must be a regular file");
  const temp = mkdtempSync(join(dirname(target), ".extension-audit-"));
  try {
    const file = join(temp, "report");
    writeFileSync(file, contents);
    beforeRename?.();
    renameSync(file, target);
  } finally {
    rmSync(temp, { recursive: true, force: true });
  }
}

async function main(): Promise<void> {
  const { values } = parseArgs({ options: {
    help: { type: "boolean" }, format: { type: "string", default: "markdown" },
    output: { type: "string" }, php: { type: "string", default: "php" },
    composer: { type: "string", default: "composer" }, workers: { type: "string", default: "12" },
  } });
  if (values.help) { process.stdout.write(help); return; }
  if (values.format !== "markdown" && values.format !== "json") throw new Error("--format must be markdown or json");
  const workers = Number(values.workers);
  if (!Number.isInteger(workers) || workers < 1 || workers > 24) throw new Error("--workers must be an integer between 1 and 24");
  const executable = (value: string) => value.includes("/") ? realpathSync(value) : Bun.which(value) ?? (() => {
    throw new Error("Missing executable on PATH: " + value);
  })();
  const audit = await report({ php: executable(values.php!), composer: executable(values.composer!), workers });
  const output = values.format === "json" ? JSON.stringify(audit, null, 2) + "\n" : markdown(audit);
  if (values.output) publish(values.output, output);
  else process.stdout.write(output);
}

if (import.meta.main) {
  for (const signal of ["SIGINT", "SIGTERM"] as const) {
    process.once(signal, () => {
      stopCommands();
      process.exit(signal === "SIGINT" ? 130 : 143);
    });
  }
  try {
    await main();
  } catch (error) {
    console.error(errorText(error));
    process.exitCode = 1;
  }
}
