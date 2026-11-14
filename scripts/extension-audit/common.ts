import { createHash } from "node:crypto";
import { lstatSync, readdirSync, readFileSync, readlinkSync } from "node:fs";
import { join } from "node:path";

export interface Manifest {
  name?: string;
  version?: string;
  url?: string;
  requires?: Record<string, string | Record<string, string | boolean>>;
  require?: Record<string, string>;
  conflict?: Record<string, string>;
  provide?: Record<string, string>;
  replace?: Record<string, string>;
  support?: { source?: string };
  type?: string;
  extra?: Record<string, unknown>;
  engines?: Record<string, string>;
  repositories?: unknown;
  "minimum-stability"?: string;
  "prefer-stable"?: boolean;
}

export interface Package extends Manifest {
  name: string;
  version: string;
  source?: { url?: string; reference?: string };
  dist?: { reference?: string };
  "install-path"?: string;
}

export interface Rule {
  repository?: string;
  channel?: "release" | "tags" | "default";
  branch?: string;
  localOnly?: string;
  blocked?: string;
  ltsOnly?: boolean;
  respectCorePin?: boolean;
  notes?: string;
  evidence?: string;
  composerScope?: "root" | "component";
}

export interface Component {
  path: string;
  name: string;
  manifests: Record<string, Manifest>;
  legacy?: string;
  composer?: Manifest;
  lock?: Package;
  baseline?: string;
  baselineKind?: string;
  repository?: string;
  version?: string;
  catalogKeys: string[];
  loadTargets: string[];
  rule: Rule;
}

export interface FileEntry {
  mode: string;
  hash: string;
}

export function parse<T>(source: string): T {
  return JSON.parse(source) as T;
}

export function json<T>(path: string): T {
  return parse<T>(readFileSync(path, "utf8"));
}

export function optionalJson<T>(path: string): T | undefined {
  try {
    return json<T>(path);
  } catch (error) {
    if ((error as NodeJS.ErrnoException).code !== "ENOENT") throw error;
  }
}

const excluded = new Set([".git", "node_modules", ".phpunit.cache", "__pycache__", ".DS_Store"]);
const transient = new Set([".phpunit.result.cache", ".eslintcache", ".stylelintcache"]);

export function walk(root: string, omit: ReadonlySet<string> = new Set()): string[] {
  const result: string[] = [];
  function visit(prefix: string): void {
    for (const entry of readdirSync(join(root, prefix), { withFileTypes: true })) {
      if (excluded.has(entry.name) || transient.has(entry.name) || omit.has(entry.name)) continue;
      const path = prefix + entry.name;
      if (entry.isDirectory()) visit(path + "/");
      else if (entry.isFile() || entry.isSymbolicLink()) result.push(path);
    }
  }
  visit("");
  return result.sort();
}

export function fileTree(root: string): Map<string, FileEntry> {
  const result = new Map<string, FileEntry>();
  for (const path of walk(root, new Set(["vendor"]))) {
    const absolute = join(root, path);
    const stat = lstatSync(absolute);
    const symlink = stat.isSymbolicLink();
    const bytes = symlink ? Buffer.from(readlinkSync(absolute)) : readFileSync(absolute);
    const hash = createHash("sha1").update("blob " + bytes.length + "\0").update(bytes).digest("hex");
    result.set(path, { hash, mode: symlink ? "120000" : stat.mode & 0o111 ? "100755" : "100644" });
  }
  return result;
}

export function sameFile(a?: FileEntry, b?: FileEntry): boolean {
  if (!a || !b) return a === b;
  return a.hash === b.hash && (a.mode === b.mode || (a.mode.startsWith("100") && b.mode.startsWith("100")));
}

export function fingerprint(trees: ReadonlyMap<string, Map<string, FileEntry>>, files: ReadonlyMap<string, Uint8Array>): string {
  const hash = createHash("sha256");
  for (const path of [...trees.keys()].sort()) {
    hash.update(JSON.stringify([path, [...trees.get(path)!]]) + "\n");
  }
  for (const path of [...files.keys()].sort()) {
    const bytes = files.get(path)!;
    hash.update(path + "\0" + bytes.length + "\0").update(bytes);
  }
  return hash.digest("hex");
}

export interface CommandResult {
  code: number;
  stdout: string;
  stderr: string;
  timedOut: boolean;
}

const active = new Set<ReturnType<typeof Bun.spawn>>();
export function stopCommands(): void {
  for (const process of active) process.kill("SIGKILL");
}

export function cleanEnvironment(extra: Record<string, string> = {}): Record<string, string> {
  const allowed = ["PATH", "SYSTEMROOT", "TMPDIR", "TEMP", "TMP", "LANG", "LC_ALL", "SSL_CERT_FILE", "SSL_CERT_DIR", "CURL_CA_BUNDLE"];
  return { ...Object.fromEntries(allowed.flatMap(key => process.env[key] ? [[key, process.env[key]!]] : [])), ...extra };
}

export async function command(
  argv: string[], options: { cwd?: string; env?: Record<string, string>; input?: string; timeout?: number } = {},
): Promise<CommandResult> {
  const child = Bun.spawn(argv, {
    cwd: options.cwd,
    env: cleanEnvironment(options.env),
    stdin: options.input === undefined ? "ignore" : new Blob([options.input]),
    stdout: "pipe",
    stderr: "pipe",
  });
  active.add(child);
  let timedOut = false;
  const timer = setTimeout(() => {
    timedOut = true;
    child.kill("SIGKILL");
  }, options.timeout ?? 30_000);
  try {
    const [stdout, stderr, code] = await Promise.all([child.stdout.text(), child.stderr.text(), child.exited]);
    return { code, stdout, stderr, timedOut };
  } finally {
    clearTimeout(timer);
    active.delete(child);
  }
}

export function errorText(value: unknown): string {
  const message = value instanceof Error ? value.message : String(value);
  return message.replace(/https?:\/\/[^\s/@]+@/g, "https://[redacted]@").replace(/\s+/g, " ").trim().slice(0, 420);
}

export async function parallel<T, U>(items: readonly T[], limit: number, visit: (item: T) => Promise<U>): Promise<U[]> {
  const result = new Array<U>(items.length);
  let next = 0;
  await Promise.all(Array.from({ length: Math.min(limit, items.length) }, async () => {
    while (next < items.length) {
      const index = next++;
      result[index] = await visit(items[index]);
    }
  }));
  return result;
}

export function normalizeName(value: string): string {
  return value.toLowerCase().replace(/[^a-z0-9]/g, "");
}

export function publicRepository(value?: string): string | undefined {
  if (!value) return;
  try {
    const url = new URL(value);
    if (url.protocol !== "https:" || url.username || url.password || url.port || url.search || url.hash) return;
    const path = url.pathname.replace(/\/$/, "").replace(/\.git$/, "");
    if (url.hostname === "gerrit.wikimedia.org") {
      const name = path.match(/^\/(?:r\/|g\/)?mediawiki\/extensions\/([\w.-]+)$/)?.[1];
      if (name) return "https://github.com/wikimedia/mediawiki-extensions-" + name;
      return;
    }
    if (["github.com", "git.average.name", "codeberg.org", "gitlab.com"].includes(url.hostname)
        && /^\/[\w.-]+(?:\/[\w.-]+)+$/.test(path)) {
      if (url.hostname === "github.com") return "https://github.com/" + path.split("/").slice(1, 3).join("/");
      return url.origin + path.split("/-/")[0];
    }
  } catch {
    return;
  }
}

export function webLink(repository: string, sha: string): string {
  return repository + (repository.includes("gitlab.com") ? "/-/commit/" : "/commit/") + sha;
}
