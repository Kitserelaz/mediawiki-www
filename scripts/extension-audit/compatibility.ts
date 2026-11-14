import { existsSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { dirname, join, relative, resolve } from "node:path";
import { command, errorText, normalizeName, optionalJson, parse } from "./common";
import type { Component, Manifest, Package } from "./common";
import type { Installed, Inventory, Lock, Project } from "./inventory";
import type { Revision } from "./upstream";

export interface Check {
  type: "core" | "platform" | "extension" | "skin" | "package" | "conflict" | "ability";
  name: string;
  constraint: string;
  actual?: string;
  result?: boolean | null;
  provider?: string;
  detail?: string;
}

export interface DependencyResolution {
  status: "resolved" | "conflict" | "unverified";
  scope: "root" | "component";
  changes: string[];
  detail: string;
}

function records(value: unknown): Record<string, string> {
  return value && typeof value === "object" && !Array.isArray(value)
    ? Object.fromEntries(Object.entries(value).map(([name, constraint]) => [name, String(constraint)])) : {};
}

function constraintObject(value: unknown): string {
  return JSON.stringify(Object.entries(value ?? {}).sort(([left], [right]) => left.localeCompare(right, "en")));
}

export function composerChanged(before?: Manifest, after?: Manifest): boolean {
  return (["require", "conflict", "provide", "replace"] as const)
    .some(key => constraintObject(before?.[key]) !== constraintObject(after?.[key]));
}

function declared(item: Component, label: string): string | undefined {
  return Object.values(item.manifests).find(manifest => normalizeName(manifest.name ?? "") === label)?.version
    ?? (normalizeName(item.name) === label ? item.version : undefined);
}

function versionMap(value?: Installed): Map<string, Package> {
  return new Map((Array.isArray(value) ? value : value?.packages ?? []).map(item => [item.name, item]));
}

function allows(installed: Package, name: string): string | undefined {
  return name === installed.name ? installed.version : installed.provide?.[name] ?? installed.replace?.[name];
}

function combine(a: string, b: string): string {
  if (a === b || b === "*") return a;
  if (a === "*") return b;
  const alternatives = (constraint: string) => constraint.split(/\s*\|\|?\s*/);
  return alternatives(a).flatMap(left => alternatives(b).map(right => left + ", " + right)).join(" || ");
}

export function mergeProject(root: Project, includes: Project[]): Project {
  const project: Project = { ...root };
  for (const key of ["require", "conflict", "provide", "replace"] as const) {
    const merged: Record<string, string> = { ...root[key] };
    for (const include of includes) {
      for (const [name, constraint] of Object.entries(include[key] ?? {})) {
        const previous = merged[name];
        if (previous && key === "require") merged[name] = combine(previous, constraint);
        else if (previous && key === "conflict" && previous !== constraint) merged[name] = previous + " || " + constraint;
        else if (previous && previous !== constraint) throw new Error("Unsupported duplicate Composer " + key + " for " + name);
        else merged[name] = constraint;
      }
    }
    project[key] = merged;
  }
  return project;
}

export class Compatibility {
  private readonly rootInstalled: Map<string, Package>;
  private readonly providers = new Map<string, Component[]>();
  private readonly projectFiles: Map<string, Project | undefined> = new Map();
  private readonly installed = new Map<string, Map<string, Package>>();
  private readonly platform: Record<string, string | false>;
  private readonly constraintCache = new Map<string, boolean | null>();
  readonly composerPrefix: string[];

  constructor(readonly inventory: Inventory, readonly php: string, composer: string, readonly workspace: string,
    hostModules: string[], composerPlatform: Record<string, string>) {
    this.composerPrefix = composer.endsWith(".phar") ? [php, composer] : [composer];
    const rootPath = join(inventory.root, "mediawiki/vendor/composer/installed.json");
    this.rootInstalled = versionMap(optionalJson<Installed>(rootPath));
    for (const item of inventory.components.filter(item => item.path.startsWith("mediawiki/extensions/"))) {
      for (const name of new Set([item.name, ...Object.values(item.manifests).flatMap(manifest => manifest.name ? [manifest.name] : [])])) {
        const key = normalizeName(name);
        if (!this.providers.has(key)) this.providers.set(key, []);
        const providers = this.providers.get(key)!;
        if (!providers.includes(item)) providers.push(item);
      }
    }
    this.platform = { ...inventory.platform, ...composerPlatform };
    for (const name of hostModules) if (!(name in this.platform)) this.platform[name] = false;
  }

  async evaluate(checks: Check[]): Promise<void> {
    const pairs = new Map<string, [string, string]>();
    for (const check of checks) {
      if (!check.actual || check.actual === "present" || check.constraint === "*") continue;
      const values: [string, string] = [check.actual, check.constraint];
      const key = JSON.stringify(values);
      if (!this.constraintCache.has(key)) pairs.set(key, values);
    }
    if (pairs.size) {
      const result = await command([this.php, "-n", join(import.meta.dir, "constraints.php"),
        join(this.inventory.root, "mediawiki/vendor/composer/semver/src")], { input: JSON.stringify([...pairs.values()]) });
      if (result.code || result.timedOut) throw new Error("Composer version constraint check failed: " + errorText(result.stderr));
      const values = parse<(boolean | null)[]>(result.stdout);
      if (values.length !== pairs.size) throw new Error("Incomplete Composer version constraint check");
      [...pairs.keys()].forEach((key, index) => this.constraintCache.set(key, values[index]));
    }
    for (const check of checks) {
      if (!check.actual) check.result = check.type === "conflict" ? true : check.type === "ability" || check.detail ? null : false;
      else if (check.constraint === "*") check.result = check.type !== "conflict";
      else if (check.actual === "present") check.result = null;
      else {
        const result = this.constraintCache.get(JSON.stringify([check.actual, check.constraint])) ?? null;
        check.result = check.type === "conflict" && result !== null ? !result : result;
      }
    }
  }

  private componentPackages(component: Component): Map<string, Package> {
    let path = component.path;
    if (path.startsWith("mediawiki/skins/")) path = path.split("/").slice(0, 3).join("/");
    let packages = this.installed.get(path);
    if (!packages) {
      packages = versionMap(optionalJson<Installed>(join(this.inventory.root, path, "vendor/composer/installed.json")));
      this.installed.set(path, packages);
    }
    return packages;
  }

  private dependency(component: Component, name: string): string | undefined {
    const local = this.componentPackages(component);
    for (const group of [local, this.rootInstalled]) {
      if (group.has(name)) return group.get(name)!.version;
      for (const package_ of group.values()) {
        const match = allows(package_, name);
        if (match && /^v?\d+\.\d+(?:\.\d+)?$/.test(match)) return match;
      }
    }
  }

  checks(component: Component, metadata: Record<string, Manifest>, revision: Revision): Check[] {
    const checks: Check[] = [];
    function add(check: Check) {
      if (!checks.some(existing => JSON.stringify(existing) === JSON.stringify(check))) checks.push(check);
    }
    for (const [path, manifest] of Object.entries(metadata)) {
      if (!path.split("/").at(-1)?.startsWith("extension")) continue;
      const core = manifest.requires?.MediaWiki;
      if (typeof core === "string") add({ type: "core", name: "MediaWiki", constraint: core, actual: this.inventory.core });
      for (const [name, constraint] of Object.entries(records(manifest.requires?.platform))) {
        if (name.startsWith("ability-")) {
          const ability = name.slice(8);
          const shell = ability === "shell" && this.inventory.shellAvailable;
          add({ type: "ability", name, constraint: constraint === "true" ? "*" : constraint, actual: shell ? "present" : undefined });
        } else {
          add({ type: "platform", name, constraint, actual: this.platformVersion(name) });
        }
      }
      for (const [name, constraint] of Object.entries(records(manifest.requires?.extensions))) {
        const key = normalizeName(name);
        const providers = this.providers.get(key) ?? [];
        const provider = providers.length === 1 ? providers[0] : undefined;
        const actual = provider ? declared(provider, key) ?? "present" : undefined;
        add({ type: "extension", name, constraint, actual, provider: provider?.path,
          detail: providers.length > 1 ? "Multiple local copies could provide this registration." : undefined });
      }
      for (const [name, constraint] of Object.entries(records(manifest.requires?.skins))) {
        const skin = this.inventory.skins.get(normalizeName(name));
        add({ type: "skin", name, constraint, actual: skin ? skin.version ?? "present" : undefined });
      }
      for (const [name, constraint] of Object.entries(records(manifest.requires?.abilities))) add({ type: "ability", name, constraint });
    }
    const project = metadata["composer.json"];
    for (const [name, constraint] of Object.entries(project?.require ?? {})) {
      const platform = !name.includes("/");
      add({ type: platform ? "platform" : "package", name, constraint,
        actual: platform ? this.platformVersion(name) : this.dependency(component, name) });
    }
    for (const [name, constraint] of Object.entries(project?.conflict ?? {})) {
      add({ type: "conflict", name, constraint,
        actual: name.includes("/") ? this.dependency(component, name) : this.platformVersion(name) });
    }
    if (revision.version && component.lock && !component.rule.respectCorePin) {
      add({ type: "package", name: component.lock.name, constraint: revision.version, actual: component.lock.version });
    }
    if (component.rule.respectCorePin && component.lock && revision.version) {
      const core = this.project(join(this.inventory.root, "mediawiki/composer.json"));
      const pin = core?.require?.[component.lock.name];
      if (pin) add({ type: "core", name: component.lock.name, constraint: pin, actual: revision.version });
    }
    for (const [path, manifest] of Object.entries(metadata)) {
      if (!path.split("/").at(-1)?.startsWith("extension")) continue;
      const registration = normalizeName(manifest.name ?? "");
      const version = manifest.version ?? revision.version;
      if (!registration || !version) continue;
      for (const skin of this.inventory.skins.values()) {
        const reverse = Object.entries(records(skin.requires?.extensions))
          .find(([name]) => normalizeName(name) === registration);
        if (reverse) add({ type: "skin", name: skin.name ?? reverse[0], constraint: reverse[1], actual: version,
          detail: "Skin requires this extension version." });
      }
    }
    return checks;
  }

  private platformVersion(name: string): string | undefined {
    const version = this.platform[name];
    return typeof version === "string" ? version : undefined;
  }

  private project(path: string): Project | undefined {
    if (!this.projectFiles.has(path)) this.projectFiles.set(path, optionalJson<Project>(path));
    return this.projectFiles.get(path);
  }

  private rootProject(component: Component, candidate: Manifest, revision: Revision): Project {
    const mw = join(this.inventory.root, "mediawiki");
    const root = this.project(join(mw, "composer.json"));
    if (!root) throw new Error("Missing root Composer project");
    const includes: Project[] = [];
    const candidatePath = join(this.inventory.root, component.path, "composer.json");
    const seen = new Set<string>();
    const add = (path: string, document: Project, isRoot: boolean) => {
      if (seen.has(path)) return;
      seen.add(path);
      const extra = document.extra?.["merge-plugin"] as { include?: string[]; require?: string[]; replace?: boolean; "ignore-duplicates"?: boolean } | undefined;
      if (extra?.replace || extra?.["ignore-duplicates"]) throw new Error("Unsupported Composer merge-plugin duplicate policy");
      if (!isRoot) includes.push(document);
      for (const [pattern, required] of [...(extra?.include ?? []).map(value => [value, false] as const),
        ...(extra?.require ?? []).map(value => [value, true] as const)]) {
        if (/[*?\[\]{}]/.test(pattern)) throw new Error("Unsupported Composer include pattern: " + pattern);
        const child = resolve(dirname(path), pattern);
        if (relative(mw, child).startsWith("..")) throw new Error("Composer include outside MediaWiki");
        const source = child === candidatePath ? candidate : this.project(child);
        if (!source && required) throw new Error("Required Composer include is missing");
        if (source) add(child, source, false);
      }
    };
    add(join(mw, "composer.json"), root, true);
    if (!seen.has(candidatePath) && !component.lock) includes.push(candidate);
    if (component.lock && revision.version) {
      const name = component.lock.name;
      let found = false;
      for (let i = 0; i < includes.length; i++) {
        if (includes[i].require?.[name] === component.lock.version.replace(/^v/, "")) {
          includes[i] = { ...includes[i], require: { ...includes[i].require, [name]: revision.version } };
          found = true;
        }
      }
      if (!found) {
        if (root.require?.[name]) throw new Error("MediaWiki pins this dependency in the core Composer project");
        includes.push({ require: { [name]: revision.version } });
      }
    }
    return mergeProject(root, includes);
  }

  async resolve(component: Component, candidate: Manifest, revision: Revision, checks: Check[]): Promise<DependencyResolution> {
    const componentPath = join(this.inventory.root, component.path);
    const componentLock = join(componentPath, "composer.lock");
    const scope = component.rule.composerScope ?? (!component.lock && existsSync(componentLock) ? "component" : "root");
    const scratch = mkdtempSync(join(this.workspace, "composer-"));
    const result = (status: DependencyResolution["status"], detail: string, changes: string[] = []): DependencyResolution =>
      ({ status, scope, changes, detail });
    try {
      const base = scope === "root" ? this.rootProject(component, candidate, revision) : candidate;
      const lockPath = scope === "root" ? join(this.inventory.root, "mediawiki/composer.lock") : componentLock;
      const lock = optionalJson<Lock>(lockPath);
      const project = {
        name: "cloudwiki/extension-audit",
        type: "project",
        require: base.require,
        conflict: base.conflict,
        provide: base.provide,
        replace: base.replace,
        "minimum-stability": base["minimum-stability"],
        "prefer-stable": base["prefer-stable"],
        config: { platform: this.platform, "allow-plugins": false,
          ...(base.config?.audit ? { audit: base.config.audit } : {}) },
      };
      if (base.repositories) return result("unverified", "Review custom Composer repositories before dependency resolution.");
      writeFileSync(join(scratch, "composer.json"), JSON.stringify(project));
      if (lock) writeFileSync(join(scratch, "composer.lock"), JSON.stringify({ ...lock, "packages-dev": [] }));
      const requirementNames = new Set(checks.filter(check => check.name.includes("/") && check.result !== true).map(check => check.name));
      for (const key of ["require", "conflict", "replace", "provide"] as const) {
        for (const name of new Set([...Object.keys(candidate[key] ?? {}), ...Object.keys(component.composer?.[key] ?? {})])) {
          if (name.includes("/") && candidate[key]?.[name] !== component.composer?.[key]?.[name] &&
              (candidate.require?.[name] || lock?.packages.some(package_ => package_.name === name))) requirementNames.add(name);
        }
      }
      const packages = [...requirementNames];
      const parameters = packages.length && lock ? ["--with-all-dependencies", "--minimal-changes", ...packages] : [];
      const environment = { HOME: scratch, COMPOSER_HOME: scratch, COMPOSER_NO_INTERACTION: "1",
        COMPOSER_CACHE_DIR: join(this.workspace, "composer-cache"), LC_ALL: "C" };
      const solve = await command([...this.composerPrefix, "update", "--dry-run", "--no-dev", "--no-interaction",
        "--no-scripts", "--no-plugins", "--no-progress", "--no-audit", ...parameters],
      { cwd: scratch, env: environment, timeout: 100_000 });
      const log = solve.stdout + "\n" + solve.stderr;
      if (!solve.code && !solve.timedOut) {
        const changes = [...new Set(log.split("\n").flatMap(line => line.match(/^\s+- ((?:Locking|Upgrading|Downgrading|Removing) .+)/)?.[1] ?? []))];
        return result("resolved", "Composer dry run resolved the candidate dependency set.", changes);
      }
      if (solve.timedOut || /could not resolve host|timed? out|failed to (?:download|connect)|curl error|HTTP (?:429|5\d\d)/i.test(log)) {
        return result("unverified", solve.timedOut ? "Composer dry run timed out." : errorText(log));
      }
      return result(solve.code === 2 || /could not be resolved to an installable set/i.test(log) ? "conflict" : "unverified", errorText(log));
    } catch (error) {
      return result("unverified", errorText(error));
    } finally {
      rmSync(scratch, { recursive: true, force: true });
    }
  }
}
