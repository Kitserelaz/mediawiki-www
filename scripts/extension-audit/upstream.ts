import { join } from "node:path";
import { command, errorText, parse, sameFile } from "./common";
import type { Component, FileEntry, Manifest } from "./common";

export interface Revision { ref: string; commit: string; version?: string }
export interface SourceEvidence {
  status: "new" | "current" | "unclear";
  baseline?: string;
  baselineKind?: string;
  matchedFiles: number;
  comparableFiles: number;
  missingChanges: string[];
  overlap: string[];
  reason: string;
}

export function versionParts(value?: string): number[] | undefined {
  const match = value?.match(/^v?(\d+(?:\.\d+){0,3})$/i);
  return match?.[1].split(".").map(Number);
}
export function compareVersions(a: number[], b: number[]): number {
  for (let i = 0; i < Math.max(a.length, b.length); i++) {
    const difference = (a[i] ?? 0) - (b[i] ?? 0);
    if (difference) return Math.sign(difference);
  }
  return 0;
}

export function refsFromRemote(text: string): { refs: Map<string, string>; defaultRef?: string } {
  const refs = new Map<string, string>();
  let defaultRef: string | undefined;
  for (const line of text.split("\n")) {
    const [id, ref] = line.split("\t");
    if (id.startsWith("ref: ") && ref === "HEAD") defaultRef = id.slice(5);
    else if (/^[a-f0-9]{40}$/.test(id) && ref) refs.set(ref, id);
  }
  return { refs, defaultRef };
}

export function revisions(
  remote: ReturnType<typeof refsFromRemote>, release: string, component: Component,
): Revision[] {
  const { refs, defaultRef } = remote;
  const matching = "refs/heads/" + release;
  const chosen = component.rule.branch ? "refs/heads/" + component.rule.branch : defaultRef;
  const branch = (ref?: string): Revision[] => ref && refs.has(ref) ? [{ ref: ref.slice(11), commit: refs.get(ref)! }] : [];
  const tags = [...refs].flatMap(([ref, commit]) => {
    if (!ref.startsWith("refs/tags/")) return [];
    const name = ref.slice(10);
    const version = versionParts(name);
    return version ? [{ ref: name, commit: refs.get(ref + "^{}") ?? commit, version: name.replace(/^v/i, "") }] : [];
  }).sort((a, b) => compareVersions(versionParts(b.version)!, versionParts(a.version)!) || a.ref.localeCompare(b.ref));
  const channel = component.rule.channel;
  const choices = channel === "release" ? branch(component.rule.branch ? chosen : matching)
    : channel === "tags" ? tags
      : channel === "default" ? [...tags, ...branch(chosen), ...branch(matching)]
        : refs.has(matching) && !component.rule.ltsOnly ? branch(matching) : tags;
  return choices.filter((candidate, index) => choices.findIndex(item => item.commit === candidate.commit) === index);
}

export function parseTree(text: string): Map<string, FileEntry> {
  const tree = new Map<string, FileEntry>();
  for (const entry of text.split("\0")) {
    if (!entry) continue;
    const end = entry.indexOf("\t");
    const [mode, , hash] = entry.slice(0, end).split(" ");
    tree.set(entry.slice(end + 1), { mode, hash });
  }
  return tree;
}

function shipped(path: string, entry?: FileEntry): boolean {
  if (entry?.mode === "160000") return false;
  return !path.split("/").some(part => part.startsWith(".") || ["test", "tests", "docs", "vendor", "node_modules"].includes(part))
    && (/\.(php|js|ts|css|less|lua|sql|svg|wasm|json|yaml|yml)$/i.test(path) || path === "Makefile");
}

function matches(local: Map<string, FileEntry>, tree: Map<string, FileEntry>): [number, number] {
  let matched = 0;
  let total = 0;
  for (const [file, entry] of tree) {
    if (!shipped(file, entry)) continue;
    total++;
    if (sameFile(local.get(file), entry)) matched++;
  }
  return [matched, total];
}

export function compareTrees(
  local: Map<string, FileEntry>, upstream: Map<string, FileEntry>, base?: Map<string, FileEntry>,
  ancestor: Map<string, FileEntry> | undefined = base,
): Omit<SourceEvidence, "baseline" | "baselineKind"> {
  const [matchedFiles, comparableFiles] = matches(local, base ?? upstream);
  const missingChanges: string[] = [];
  const overlap: string[] = [];
  const result = { matchedFiles, comparableFiles, missingChanges, overlap };
  const candidateFiles = [...upstream].filter(([path, entry]) => shipped(path, entry));
  if (candidateFiles.length && candidateFiles.every(([path, entry]) => sameFile(local.get(path), entry))
      && ![...(ancestor ?? upstream)].some(([path, entry]) => shipped(path, entry) && !upstream.has(path) && sameFile(local.get(path), entry))) {
    return { ...result, status: "current", reason: "Checkout matches the upstream distribution files." };
  }
  if (!base || !ancestor || comparableFiles === 0 || matchedFiles / comparableFiles < 0.65) {
    return { ...result, status: "unclear", reason: "The installed source could not be matched to an earlier upstream tree." };
  }
  for (const path of new Set([...ancestor.keys(), ...upstream.keys()])) {
    const prev = ancestor.get(path), next = upstream.get(path), installed = local.get(path), recorded = base.get(path);
    if (!shipped(path, next ?? prev) || sameFile(prev, next) || sameFile(installed, next)) continue;
    if (sameFile(installed, prev) || (installed && sameFile(installed, recorded))) {
      missingChanges.push(path);
    } else {
      overlap.push(path);
    }
  }
  missingChanges.sort();
  overlap.sort();
  if (missingChanges.length) return { ...result, status: "new", reason: "The checkout still contains older files or lacks files changed upstream." };
  if (overlap.length) return { ...result, status: "unclear", reason: "Upstream differences overlap local changes and need review." };
  return { ...result, status: "current", reason: "No outstanding source changes on the selected channel." };
}

export class Upstream {
  readonly dir: string;
  remote!: ReturnType<typeof refsFromRemote>;
  private readonly trees = new Map<string, Map<string, FileEntry>>();
  private readonly fetched = new Set<string>();
  private readonly env: Record<string, string>;

  constructor(readonly repository: string, workspace: string, index: number, readonly localFixture = false) {
    this.dir = join(workspace, "repository-" + index);
    this.env = { HOME: workspace, XDG_CONFIG_HOME: workspace, GIT_CONFIG_NOSYSTEM: "1", GIT_TERMINAL_PROMPT: "0",
      GIT_CONFIG_GLOBAL: "/dev/null", GIT_ALLOW_PROTOCOL: localFixture ? "file" : "https", LC_ALL: "C" };
  }

  private async git(args: string[], cwd = this.dir, attempts = 1, timeout = 30_000): Promise<string> {
    let failure = "";
    for (let attempt = 0; attempt < attempts; attempt++) {
      const result = await command(["git", "-c", "credential.helper=", "-c", "core.hooksPath=/dev/null", ...args], {
        cwd, env: this.env, timeout,
      });
      if (!result.code && !result.timedOut) return result.stdout;
      failure = result.timedOut ? "Git timed out" : errorText(result.stderr || "Git command exited " + result.code);
      if (attempt + 1 < attempts) await Bun.sleep(300 * (attempt + 1));
    }
    throw new Error(failure);
  }

  async open(release: string, additionalBranches: string[]): Promise<void> {
    const root = join(this.dir, "..");
    const listing = await this.git(["ls-remote", "--symref", this.repository, "HEAD", "refs/tags/*", "refs/heads/" + release,
      ...additionalBranches.map(branch => "refs/heads/" + branch)], root, 2);
    this.remote = refsFromRemote(listing);
    const defaultRef = this.remote.defaultRef;
    const head = this.remote.refs.get("HEAD");
    if (defaultRef && head) this.remote.refs.set(defaultRef, head);
    await this.git(["init", "--bare", this.dir], root);
    await this.git(["remote", "add", "origin", this.repository]);
    if (!this.localFixture) {
      await this.git(["config", "remote.origin.promisor", "true"]);
      await this.git(["config", "remote.origin.partialclonefilter", "blob:none"]);
    }
  }

  async fetch(commit: string): Promise<boolean> {
    if (!/^[a-f0-9]{40}$/.test(commit)) return false;
    if (this.fetched.has(commit)) return true;
    try {
      // cat-file can lazy-fetch missing objects in a promisor repository.
      const result = await command(["git", "cat-file", "-e", commit + "^{commit}"],
        { cwd: this.dir, env: { ...this.env, GIT_NO_LAZY_FETCH: "1" }, timeout: 5_000 });
      if (result.code || result.timedOut) {
        await this.git(["fetch", "--quiet", "--no-tags", ...(this.localFixture ? [] : ["--filter=blob:none"]), "origin", commit], this.dir, 2);
      }
      this.fetched.add(commit);
      return true;
    } catch {
      return false;
    }
  }

  async tree(commit: string): Promise<Map<string, FileEntry>> {
    let tree = this.trees.get(commit);
    if (!tree) {
      tree = parseTree(await this.git(["ls-tree", "-rz", commit]));
      this.trees.set(commit, tree);
    }
    return tree;
  }

  async metadata(component: Component, revision: Revision): Promise<Record<string, Manifest>> {
    const tree = await this.tree(revision.commit);
    const paths = new Set([...Object.keys(component.manifests), "extension.json", "composer.json", "package.json"]);
    const valid = [...paths].filter(path => tree.get(path)?.mode.startsWith("100"));
    const blobs = [...new Set(valid.map(path => tree.get(path)!.hash))];
    if (blobs.length && !this.localFixture) {
      try { await this.git(["fetch", "--quiet", "--no-tags", "--filter=blob:none", "origin", ...blobs], this.dir, 1, 12_000); }
      catch { /* Git providers can disallow bulk blob requests. cat-file can fetch blobs individually. */ }
    }
    const result: Record<string, Manifest> = {};
    for (const path of valid) {
      result[path] = parse<Manifest>(await this.git(["cat-file", "blob", tree.get(path)!.hash], this.dir, 2, 15_000));
    }
    return result;
  }

  private async mergeBase(a: string, b: string): Promise<string | undefined> {
    const result = await command(["git", "merge-base", a, b], { cwd: this.dir, env: this.env });
    if (result.code === 1) return;
    if (result.code || result.timedOut) throw new Error(errorText(result.stderr || "Git could not determine merge base"));
    return result.stdout.trim();
  }

  async evidence(component: Component, local: Map<string, FileEntry>, revision: Revision): Promise<SourceEvidence> {
    const target = await this.tree(revision.commit);
    let baseRef = component.baseline;
    let baseKind = component.baselineKind;
    if (baseRef && !await this.fetch(baseRef)) baseRef = undefined;
    const tag = component.version;
    if (!baseRef && versionParts(tag)) {
      for (const prefix of ["", "v"]) {
        const ref = "refs/tags/" + prefix + tag!.replace(/^v/, "");
        const candidate = this.remote.refs.get(ref + "^{}") ?? this.remote.refs.get(ref);
        if (candidate && await this.fetch(candidate)) {
          baseRef = candidate;
          baseKind = "published local version";
          break;
        }
      }
    }
    let base = baseRef ? await this.tree(baseRef) : undefined;
    let common = baseRef ? await this.mergeBase(baseRef, revision.commit) : undefined;
    const [matched, total] = base ? matches(local, base) : [0, 0];
    if ((!common || !total || matched / total < 0.65) && compareTrees(local, target).status !== "current") {
      const history = (await this.git(["rev-list", "--first-parent", "--max-count=129", revision.commit])).trim().split("\n");
      let bestScore = 0;
      for (const index of [1, 2, 4, 8, 16, 32, 64, 128]) {
        if (!history[index]) continue;
        const older = await this.tree(history[index]);
        const [score, total] = matches(local, older);
        if (total && score > bestScore && score / total >= 0.8) {
          bestScore = score;
          base = older;
          common = history[index];
          baseRef = common;
          baseKind = "matching upstream files";
          if (score === total) break;
        }
      }
    }
    const result = compareTrees(local, target, common ? base : undefined, common ? await this.tree(common) : undefined);

    return { ...result, baseline: baseRef, baselineKind: baseKind };
  }
}
