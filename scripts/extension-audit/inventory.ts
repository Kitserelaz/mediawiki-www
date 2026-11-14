import { existsSync, readFileSync, realpathSync } from "node:fs";
import { basename, dirname, isAbsolute, join, posix, relative, resolve } from "node:path";
import { command, fileTree, fingerprint, normalizeName, parse, publicRepository, walk } from "./common";
import type { Component, FileEntry, Manifest, Package, Rule } from "./common";

type Token = [string, string];
type TokenMap = Record<string, Token[]>;
type Catalog = Map<string, { name: string; skin: boolean }>;
type Provenance = { path: string; head?: string; remote?: string; git?: { commit?: string; origin?: string } };
export type Project = Manifest & { config?: Record<string, unknown> };
export type Lock = { packages: Package[]; "packages-dev"?: Package[] };
export type Installed = Package[] | { packages: Package[] };
export interface Rules {
  components: Record<string, Rule>;
  legacy: string[];
  platform: { imageFamily: string; coreModules: string[] };
}
export interface Inventory {
  root: string;
  core: string;
  php: string;
  release: string;
  platform: Record<string, string>;
  shellAvailable: boolean;
  components: Component[];
  trees: Map<string, Map<string, FileEntry>>;
  files: Map<string, Uint8Array>;
  fingerprint: string;
  missing: { name: string; key: string; reason: string }[];
  catalogCount: number;
  catalogSkins: number;
  manifestCount: number;
  diagnostics: string[];
  skins: Map<string, Manifest>;
}

function string(token?: Token): string | undefined {
  return token?.[0] === "T_CONSTANT_ENCAPSED_STRING" ? token[1].slice(1, -1).replace(/\\(['"\\])/g, "$1") : undefined;
}

function endOf(tokens: Token[], start: number): number {
  const pairs: Record<string, string> = { "[": "]", "(": ")", "{": "}" };
  const stack: string[] = [];
  for (let i = start; i < tokens.length; i++) {
    const [kind, value] = tokens[i];
    if (kind) continue;
    if (value in pairs) stack.push(pairs[value]);
    else if (value === stack.at(-1)) {
      stack.pop();
      if (stack.length === 0) return i;
    }
  }
  throw new Error("Unclosed PHP expression at token " + start);
}

export function registrations(source: TokenMap): { catalog: Catalog; loads: Set<string>; diagnostics: string[] } {
  const catalog: Catalog = new Map();
  const loads = new Set<string>();
  const diagnostics = new Set<string>();
  for (const [path, tokens] of Object.entries(source)) {
    for (let i = 0; i < tokens.length; i++) {
      const [kind, value] = tokens[i];
      const next = tokens[i + 1]?.[1];
      if (value === "$wgManageWikiExtensions" && ["=", "+="].includes(next)) {
        if (tokens[i + 2]?.[1] !== "[") throw new Error("Unsupported ManageWiki catalog assignment in " + path);
        const end = endOf(tokens, i + 2);
        for (let j = i + 3; j < end; j++) {
          if (tokens[j][1] === ",") continue;
          const key = string(tokens[j]);
          if (!key || tokens[j + 1]?.[1] !== "=>" || tokens[j + 2]?.[1] !== "[") {
            throw new Error("Unsupported ManageWiki entry in " + path + " at token " + j);
          }
          const entryEnd = endOf(tokens, j + 2);
          let name: string | undefined;
          let skin = false;
          for (let k = j + 3; k < entryEnd; k++) {
            const field = string(tokens[k]);
            if (tokens[k + 1]?.[1] === "=>") {
              if (field === "name") name = string(tokens[k + 2]);
              if (field === "section") skin = string(tokens[k + 2]) === "skins";
            }
            if (!tokens[k][0] && ["[", "(", "{"].includes(tokens[k][1])) k = endOf(tokens, k);
          }
          if (!name) throw new Error("ManageWiki entry has no literal name: " + key);
          if (next === "=" || !catalog.has(key)) catalog.set(key, { name, skin });
          j = entryEnd;
        }
        i = end;
        continue;
      }
      if (kind === "T_STRING" && /^wfLoadExtensions?$/i.test(value) && next === "(") {
        const arg = tokens[i + 2];
        if (string(arg)) loads.add(string(arg)!);
        else if (arg?.[1] === "[") {
          for (const token of tokens.slice(i + 3, endOf(tokens, i + 2))) {
            const name = string(token);
            if (name) loads.add(name);
          }
        } else if (arg?.[1] !== "$farmExtensions") diagnostics.add("Dynamic extension load in " + path);
      }
      if (value === "$farmExtensions" && next === "=") {
        const end = tokens.findIndex((token, index) => index > i && token[1] === ";");
        if (end < 0) throw new Error("Unclosed farm extension assignment in " + path);
        const start = tokens[i + 2]?.[1] === "[" ? i + 2
          : tokens[i + 2]?.[1] === "array_merge" ? tokens.findIndex((token, index) => index > i + 2 && index < end && token[1] === "[") : -1;
        if (start >= 0) {
          for (const token of tokens.slice(start + 1, endOf(tokens, start))) {
            const name = string(token);
            if (name) loads.add(name);
          }
        } else if (tokens[i + 2]?.[1] !== "array_values") diagnostics.add("Dynamic farm extension assignment in " + path);
      }
      if (["T_REQUIRE", "T_REQUIRE_ONCE", "T_INCLUDE", "T_INCLUDE_ONCE"].includes(kind)) {
        const end = tokens.findIndex((token, index) => index > i && token[1] === ";");
        const expression = tokens.slice(i + 1, end < 0 ? i + 3 : end).map(token => token[1]).join("");
        const legacy = expression.match(/(?:\$IP)?\/(extensions(?:_staging)?\/[\w./-]+\.php)/)?.[1];
        if (legacy) loads.add(legacy);
      }
    }
  }
  return { catalog, loads, diagnostics: [...diagnostics] };
}

const phpFiles = ["mediawiki/ManageWikiExtensions.php", "mediawiki/GlobalExtensions.php", "mediawiki/GlobalSettings.php",
  "mediawiki/LocalSettings.php", "docker/mediawiki/bootstrap.php"];
const provenanceFiles = ["docs/upgrade-catalogue-baseline.json", "docs/upgrade-catalogue-nonstandard-baseline.json",
  "docs/vendored-source-provenance.json"];
const manifestPattern = /^extension(?:[-_][\w-]+)?\.json$/;
const ignored = new Set(["vendor", "tests", "test", "i18n", "resources", ".github", ".phan", "lib", "libs", "assets", "dist", "examples", "docs", "modules", "maintenance"]);
const relativeToMediaWiki = (root: string, path: string) => relative(join(root, "mediawiki"), path).split("\\").join("/");

export async function inventory(root: string, phpExe: string, rulesPath: string): Promise<Inventory> {
  root = realpathSync(root);
  rulesPath = realpathSync(rulesPath);
  const files = new Map<string, Uint8Array>();
  function read(path: string, optional = false): Buffer | undefined {
    const full = isAbsolute(path) ? path : join(root, path);
    if (optional && !existsSync(full)) return;
    const real = realpathSync(full);
    if (full !== rulesPath && relative(root, real).startsWith("..")) throw new Error("Audit input outside checkout: " + path);
    const bytes = readFileSync(full);
    files.set(relative(root, full), bytes);
    return bytes;
  }
  function data<T>(path: string, optional = false): T | undefined {
    const bytes = read(path, optional);
    return bytes ? parse<T>(bytes.toString("utf8")) : undefined;
  }
  const rules = data<Rules>(rulesPath)!;
  const config = data<{ extensions: Record<string, { repository?: string; commit?: string; branch?: string }> }>("docs/upgrade-targets.json")!;
  for (const path of ["compose.yaml", "docs/EXTENSION-DEPENDENCIES.md", ".dockerignore", "mediawiki/composer.json",
    "mediawiki/composer.local.json", "mediawiki/SkyWikiFunctions.php"]) read(path, true);
  const core = read("mediawiki/includes/Defines.php")!.toString().match(/define\(\s*['"]MW_VERSION['"]\s*,\s*['"]([\d.]+)['"]/)?.[1];
  const dockerfile = read("docker/mediawiki/Dockerfile")!.toString();
  const image = dockerfile.match(/^ARG PHP_IMAGE=(\S+)/m)?.[1];
  const php = image?.match(/^php:([\d.]+)-/)?.[1];
  if (!core || !php || !image) throw new Error("Cannot determine target MediaWiki or PHP from the checkout");
  if (!image.includes(rules.platform.imageFamily)) throw new Error("No audited PHP base platform for " + image);
  const platform: Record<string, string> = { php, "php-64bit": php };
  for (const ext of rules.platform.coreModules) platform["ext-" + ext] = php;
  for (const match of dockerfile.matchAll(/docker-php-ext-install(?:\s+-j"\$\(nproc\)")? ([\w ]+)/g)) {
    for (const ext of match[1].trim().split(/\s+/)) platform["ext-" + ext] = php;
  }
  if (platform["ext-opcache"]) platform["ext-zend-opcache"] = platform["ext-opcache"];
  for (const match of dockerfile.matchAll(/pecl install ([\w.-]+(?: [\w.-]+)*)/g)) {
    for (const arg of match[1].split(" ")) {
      const m = arg.match(/^([\w-]+)-([\d.]+)$/);
      if (m) platform["ext-" + m[1]] = m[2];
    }
  }
  const provenance = new Map<string, Provenance>();
  for (const path of provenanceFiles) {
    for (const record of data<{ components: Provenance[] }>(path)?.components ?? []) provenance.set(record.path, record);
  }
  const source = Object.fromEntries(phpFiles.map(path => [path, read(path)!.toString("utf8")]));
  const parser = await command([phpExe, "-n", join(import.meta.dir, "tokenize.php")], { input: JSON.stringify(source) });
  if (parser.code || parser.timedOut) throw new Error("PHP tokenizer failed. A PHP CLI with tokenizer enabled under php -n is required. " + parser.stderr);
  const { catalog, loads, diagnostics } = registrations(parse<TokenMap>(parser.stdout));
  const mw = join(root, "mediawiki");
  const components = new Map<string, Component>();
  const skins = new Map<string, Manifest>();
  const packages = data<Lock>("mediawiki/composer.lock")!.packages;
  const installed = data<Installed>("mediawiki/vendor/composer/installed.json", true);
  const installedPackages = Array.isArray(installed) ? installed : installed?.packages ?? [];
  const installs = new Map<string, Package>();
  for (const pkg of installedPackages) {
    const location = pkg["install-path"];
    if (!location) continue;
    const target = resolve(mw, "vendor", "composer", location);
    const local = relativeToMediaWiki(root, target);
    if (!local.startsWith("..")) installs.set("mediawiki/" + local, packages.find(item => item.name === pkg.name) ?? pkg);
  }
  function component(path: string): Component {
    let item = components.get(path);
    if (!item) {
      item = { path, name: basename(path), manifests: {}, catalogKeys: [], loadTargets: [], rule: rules.components[path] ?? {} };
      components.set(path, item);
    }
    return item;
  }
  const manifestPaths = new Set<string>();
  for (const area of ["extensions", "extensions_staging", "skins"]) {
    const base = join(mw, area);
    if (!existsSync(base)) continue;
    for (const path of walk(base, ignored)) {
      if (!path.includes("/")) continue;
      if (area === "skins" && /^[^/]+\/skin\.json$/.test(path)) {
        const skin = data<Manifest>("mediawiki/" + area + "/" + path)!;
        if (skin.name) skins.set(normalizeName(skin.name), skin);
      }
      if (manifestPattern.test(basename(path))) manifestPaths.add("mediawiki/" + area + "/" + path);
    }
  }
  for (const path of installs.keys()) {
    if (!path.startsWith("mediawiki/vendor/")) continue;
    for (const name of ["extension.json", "extension-client.json", "extension-repo.json"]) {
      if (existsSync(join(root, path, name))) manifestPaths.add(path + "/" + name);
    }
  }
  for (const path of [...manifestPaths].sort()) {
    const manifest = data<Manifest>(path)!;
    if (!manifest.name) throw new Error("Missing extension name in " + path);
    const parts = path.split("/");
    const direct = parts.slice(0, 3).join("/");
    const parent = dirname(path);
    const pkg = parent === direct ? undefined : data<Manifest>(parent + "/composer.json", true);
    const group = ["extensions", "extensions_staging"].includes(parts[1]) && pkg?.type !== "mediawiki-extension" ? direct : parent;
    component(group).manifests[path.slice(group.length + 1)] = manifest;
  }
  const legacyPaths = new Set(rules.legacy);
  for (const load of loads) {
    if (/^extensions(?:_staging)?\/.+\.php$/.test(load)) legacyPaths.add("mediawiki/" + load);
  }
  for (const path of legacyPaths) {
    if (!existsSync(join(root, path))) continue;
    const item = component(dirname(path));
    item.legacy = path.slice(item.path.length + 1);
    const text = read(path)!.toString();
    item.version = text.match(/['"]version['"]\s*=>\s*['"]([^'"]+)['"]/)?.[1]
      ?? text.match(/define\(\s*['"]\w+_VERSION['"]\s*,\s*['"]([^'"]+)/)?.[1];
  }
  const trees = new Map<string, Map<string, FileEntry>>();
  for (const item of components.values()) {
    const main = item.manifests["extension.json"];
    if (item.path.startsWith("mediawiki/vendor/") && main?.name) item.name = main.name;
    item.composer = data<Manifest>(item.path + "/composer.json", true);
    item.lock = installs.get(item.path) ?? (item.composer?.type === "mediawiki-extension"
      ? packages.find(pkg => pkg.name === item.composer?.name && item.path.startsWith("mediawiki/extensions/")) : undefined);
    item.version ??= item.lock?.version ?? main?.version ?? Object.values(item.manifests).find(m => m.version)?.version;
    const historical = provenance.get(item.path);
    const target = item.path === "mediawiki/extensions/" + item.name ? config.extensions[item.name] : undefined;
    const nativeGit = join(root, item.path, ".git");
    let localOrigin: string | undefined;
    let localHead: string | undefined;
    if (existsSync(nativeGit)) {
      const cwd = join(root, item.path);
      const [repo, sha] = await Promise.all([
        command(["git", "config", "--local", "--get", "remote.origin.url"], { cwd }),
        command(["git", "rev-parse", "--verify", "HEAD"], { cwd }),
      ]);
      if (!repo.code) localOrigin = repo.stdout.trim();
      if (!sha.code) localHead = sha.stdout.trim();
    }
    const lockedSha = item.lock?.source?.reference ?? item.lock?.dist?.reference;
    item.baseline = localHead ?? lockedSha ?? target?.commit ?? historical?.head ?? historical?.git?.commit;
    item.baselineKind = localHead ? "local Git HEAD" : lockedSha ? "Composer lock" : target?.commit ? "source port" : item.baseline ? "historical ref" : undefined;
    const urls = [item.rule.repository, localOrigin, item.lock?.source?.url, target?.repository, historical?.remote,
      historical?.git?.origin, item.composer?.support?.source, main?.url, ...Object.values(item.manifests).map(m => m.url)];
    item.repository = urls.map(publicRepository).find(Boolean);
    if (!item.repository && urls.some(url => url?.match(/^https?:\/\/(?:www\.)?mediawiki\.org\/wiki\/(?:Special:MyLanguage\/)?Extension:/i))) {
      item.repository = publicRepository("https://github.com/wikimedia/mediawiki-extensions-" + item.name);
    }
    item.rule = { ...item.rule };
    if (!item.rule.channel && target?.branch === "REL" + core.split(".").slice(0, 2).join("_")) item.rule.channel = "release";
    trees.set(item.path, fileTree(join(root, item.path)));
    for (const path of ["composer.lock", "vendor/composer/installed.json"]) {
      read(item.path + "/" + path, true);
      if (item.path.startsWith("mediawiki/skins/")) read(item.path.split("/").slice(0, 3).join("/") + "/" + path, true);
    }
  }
  const missing: Inventory["missing"] = [];
  let catalogSkins = 0;
  const byName = new Map<string, Set<Component>>();
  for (const item of components.values()) {
    if (!item.path.startsWith("mediawiki/extensions/")) continue;
    const labels = [item.name, ...Object.values(item.manifests).map(m => m.name!)];
    for (const name of labels) {
      const key = normalizeName(name);
      if (!byName.has(key)) byName.set(key, new Set());
      byName.get(key)!.add(item);
    }
  }
  for (const [key, entry] of catalog) {
    const label = normalizeName(entry.name);
    const matches = [...(byName.get(label) ?? [])];
    if (entry.skin || (!matches.length && skins.has(label))) catalogSkins++;
    else if (matches.length === 1) matches[0].catalogKeys.push(key);
    else missing.push({ name: entry.name, key, reason: matches.length
      ? "Ambiguous catalog mapping: " + matches.map(item => item.path).join(", ")
      : "No matching local extension registration or entrypoint." });
  }
  for (const load of loads) {
    const path = load.startsWith("../") ? posix.normalize("mediawiki/extensions/" + load)
      : load.startsWith("extensions") && load.endsWith(".php") ? dirname("mediawiki/" + load)
        : "mediawiki/extensions/" + load.split("/")[0];
    const item = components.get(path);
    if (item) item.loadTargets.push(load);
    else if (!missing.some(entry => entry.key === load)) missing.push({ name: load, key: load, reason: "Extension load target has no matching source." });
  }
  const sorted = [...components.values()].sort((a, b) => a.path.localeCompare(b.path, "en"));
  if (!sorted.length) throw new Error("No extensions found in checkout");
  return { root, core, php, shellAvailable: /\bdiffutils\b/.test(dockerfile), release: "REL" + core.split(".").slice(0, 2).join("_"), platform, components: sorted, trees, files,
    fingerprint: fingerprint(trees, files), missing, catalogCount: catalog.size, catalogSkins, manifestCount: manifestPaths.size, diagnostics, skins };
}
