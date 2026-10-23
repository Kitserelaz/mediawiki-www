'use strict';
// Exercise rendered status text and links without installing a browser runtime.
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
class Element {
  constructor(tag) { this.tag = tag; this.children = []; this.dataset = {}; this.value = ''; this.textContent = ''; }
  append(...children) { this.children.push(...children); }
  replaceChildren(...children) { this.children = children; this.textContent = ''; }
  setAttribute() {}
  addEventListener() {}
  get childElementCount() { return this.children.length; }
  querySelectorAll(selector) { return this.children.flatMap(n => [...(n.tag === 'details' && (selector === 'details' || selector === 'details[open]' && n.open) ? [n] : []), ...n.querySelectorAll(selector)]); }
}
const html = fs.readFileSync('docker/diff/public/index.html', 'utf8');
const nodes = Object.fromEntries([...html.matchAll(/id="([^"]+)"/g)].map(m => [m[1], new Element('div')]));
const context = vm.createContext({document: {getElementById: id => { assert.ok(nodes[id], `Missing HTML element ${id}`); return nodes[id]; }, createElement: tag => new Element(tag), querySelectorAll: () => []}, fetch: () => new Promise(() => {}), setInterval: () => {}, Date, console});
vm.runInContext(fs.readFileSync('docker/diff/public/app.js', 'utf8'), context);
const now = Date.now() / 1000;
const iso = seconds => new Date(seconds * 1000).toISOString();
context.fixture = {deployed_commit: 'a'.repeat(40), generated_at: now, config: {status: 'clean'}, pending: {files: [], commits: []}, pipeline: {
  id: 42, head_sha: 'b'.repeat(40), status: 'in_progress', checked_at: now, run_started_at: iso(now - 1200),
  activity: [{job: 'integration', phase: 'Runtime images', status: 'running', started_at: now - 600, heartbeat_at: now - 10, last_output_at: now - 400}],
  jobs: [{id: 7, name: 'integration', status: 'in_progress', started_at: iso(now - 1200), steps: [
    {name: 'Build runtime images', status: 'in_progress', started_at: iso(now - 600)},
    {name: 'Catalogue registration checks (advisory)', status: 'completed', conclusion: 'failure', started_at: iso(now - 1200), completed_at: iso(now - 1000)}]}],
  history: {durations: {'integration / Build runtime images': {samples: 4, median: 200}}}},
  deployment: {commit: 'b'.repeat(40), status: 'blocked', stage: 'validating', reason: 'uncommitted_files', started_at: now - 100, updated_at: now - 50, heartbeat_at: now - 50}};
vm.runInContext('report = fixture; renderPipeline(); render();', context);
const text = node => [node.textContent, ...node.children.map(text)].join(' ');
let output = text(nodes['build-panel']) + Object.values(nodes).map(text).join(' ');
assert.match(output, /Current step: Build runtime images/);
assert.match(output, /runner is reporting, but this build has produced no output/i);
assert.match(output, /Taking longer than usual/);
assert.match(output, /Advisory warning/);
assert.match(output, /Blocked: production has uncommitted changes/);
assert.equal(nodes['build-alert'].hidden, true);
assert.match(text(nodes['build-title']), /bbbbbbbb/);
// A running job may start expanded, but an explicit collapse survives timer and
// status refreshes. An explicit expansion must survive completion as well.
assert.equal(nodes['build-jobs'].children[0].open, true);
nodes['build-jobs'].children[0].open = false;
vm.runInContext('renderPipeline()', context);
assert.equal(nodes['build-jobs'].children[0].open, false);
context.fixture = JSON.parse(JSON.stringify(context.fixture));
vm.runInContext('report = fixture; renderPipeline()', context);
assert.equal(nodes['build-jobs'].children[0].open, false);
nodes['build-jobs'].children[0].open = true;
context.fixture.pipeline.jobs[0].status = 'completed';
context.fixture.pipeline.jobs[0].conclusion = 'success';
vm.runInContext('renderPipeline()', context);
assert.equal(nodes['build-jobs'].children[0].open, true);
context.fixture.pipeline.jobs[0].status = 'in_progress';
context.fixture.pipeline.jobs[0].conclusion = null;

context.fixture.pipeline.checked_at = now - 400;
context.fixture.pipeline.activity[0].heartbeat_at = now - 400;
vm.runInContext('renderPipeline()', context);
output = Object.values(nodes).map(text).join(' ');
assert.match(output, /Build data is stale/);
assert.match(output, /Build heartbeat overdue/);
context.fixture.pipeline = {status: 'waiting_for_run', checked_at: now, head_sha: 'c'.repeat(40), jobs: []};
vm.runInContext('renderPipeline()', context);
assert.match(text(nodes['build-jobs']), /No workflow run exists for this exact main commit/);
context.fixture.deployment.status = 'rolled_back';
context.fixture.deployment.stage = 'rolled_back';
context.fixture.deployment.reason = 'application_restored_database_preserved';
vm.runInContext('renderPipeline()', context);
assert.match(text(nodes['deployment-progress']), /Previous application restored/);
assert.match(text(nodes['deployment-progress']), /database contents and data volumes were preserved/);
context.fixture.deployment.status = 'failed';
context.fixture.deployment.stage = 'rolling_back';
context.fixture.deployment.reason = 'rollback_failed';
vm.runInContext('renderPipeline()', context);
assert.match(text(nodes['deployment-progress']), /manual recovery is required/);
console.log('Dashboard rendering scenarios passed');
