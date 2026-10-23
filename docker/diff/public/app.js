'use strict';
let report, view = 'pending';
const $ = id => document.getElementById(id);
const element = (tag, text, cls) => { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (cls) n.className = cls; return n; };
const short = sha => sha.slice(0, 8);
const link = (text, url) => { const a = element('a', text); a.href = url; return a; };
const repo = 'https://github.com/Kitserelaz/mediawiki-www';
const stamp = seconds => new Date(seconds * 1000).toLocaleString();
function render() {
  if (!report) return;
  const privateView = view === 'config' || view === 'working_tree';
  const data = report[view];
  document.querySelectorAll('[role=tab]').forEach(t => { const active = t.dataset.view === view; t.setAttribute('aria-selected', active); t.tabIndex = active ? 0 : -1; });
  $('changes').setAttribute('aria-labelledby', `tab-${view}`);
  $('view-title').textContent = { pending: 'From production to main', released: 'What the last release shipped', config: 'Changes to local configuration', working_tree: 'Uncommitted production edits' }[view];
  $('view-caption').textContent = view === 'working_tree' ? 'Saved live edits are already affecting production. Commit or revert these files before deploying. Contents remain private.' : view === 'config' ? 'Compared with the baseline accepted by the deployment guard. File names only.' : view === 'pending' ? 'These changes are in the repository. They have not shipped to production yet.' : report.previous_commit ? 'Compared with the previous successful production release.' : 'This is the initial production release; there is no earlier deployment to compare.';
  $('compare').hidden = privateView || (view === 'released' && !report.previous_commit);
  $('compare').href = `${repo}/compare/${view === 'pending' ? report.deployed_commit + '...main' : report.previous_commit + '...' + report.deployed_commit}`;
  $('commits').replaceChildren();
  for (const c of data?.commits || []) {
    const row = element('div', undefined, 'commit'); row.append(link(short(c.sha), `${repo}/commit/${c.sha}`), element('span', c.message.split('\n')[0])); $('commits').append(row);
  }
  const files = data?.files || [], query = $('search').value.toLowerCase();
  $('file-count').textContent = `${files.length} CHANGED FILE${files.length === 1 ? '' : 'S'}`;
  $('changes').replaceChildren();
  for (const f of files.filter(f => f.filename.toLowerCase().includes(query))) {
    const row = element('details'), summary = element('summary');
    summary.append(element('span', f.filename, 'filename'), element('span', f.status, 'status'));
    if (!privateView) { const stats = element('span', undefined, 'stats'); stats.append(element('span', `+${f.additions}`, 'add'), element('span', `−${f.deletions}`, 'del')); summary.append(stats); }
    row.append(summary);
    if (privateView) row.append(element('pre', 'Contents are private. Review this file directly in the editor or on the server.'));
    else if (f.patch) { const pre = element('pre'); for (const line of f.patch.split('\n')) pre.append(element('span', line, line.startsWith('@@') ? 'diff-hunk' : line.startsWith('+') ? 'diff-add' : line.startsWith('-') ? 'diff-del' : '')); row.append(pre); }
    else row.append(element('pre', 'No inline patch available. View the full comparison on GitHub.'));
    $('changes').append(row);
  }
  if (!$('changes').childElementCount) $('changes').append(element('div', query ? 'No files match this filter.' : view === 'working_tree' ? 'No uncommitted production edits.' : view === 'config' ? (report.config.status === 'clean' ? 'Local configuration matches the accepted baseline.' : 'Configuration status is unavailable.') : !data ? (view === 'released' && !report.previous_commit ? 'No previous production release yet.' : 'Source comparison is temporarily unavailable.') : 'No file changes in this comparison.', 'empty'));
}
async function refresh() {
  try {
    const response = await fetch('/changes.json', { cache: 'no-store' }); if (!response.ok) throw Error('report');
    report = await response.json();
    $('live').replaceChildren(link(short(report.deployed_commit), `${repo}/commit/${report.deployed_commit}`));
    const dirty = report.working_tree?.status === 'changed';
    $('live-caption').textContent = report.live_editing ? (dirty ? 'Live checkout · includes uncommitted edits' : 'Live checkout · no uncommitted edits') : 'The last successful production release';
    const count = report.pending?.total_commits;
    $('pending-count').textContent = count === undefined ? 'Unavailable' : `${count} commit${count === 1 ? '' : 's'}`;
    $('pending-caption').textContent = count ? 'On main, waiting for a successful deployment' : count === 0 ? (dirty ? 'Main matches the base commit; live edits differ' : 'Production matches main') : 'Source comparison unavailable';
    $('config').textContent = { clean: 'Unchanged', changed: 'Needs review', unknown: 'Unavailable' }[report.config.status];
    $('config').className = 'value ' + (report.config.status === 'clean' ? 'good' : 'warn');
    $('config-caption').textContent = report.config.status === 'changed' ? `${report.config.files.length} changed file(s) · deployments blocked` : report.config.status === 'clean' ? 'Matches the accepted baseline' : 'The guard requires an accepted, readable baseline';
    const pipeline = report.pipeline;
    $('pipeline').href = pipeline ? `${repo}/actions/runs/${pipeline.id}` : `${repo}/actions`;
    $('pipeline').textContent = pipeline ? `CI · ${pipeline.conclusion || pipeline.status}` : 'CI · unavailable';
    $('pipeline').className = 'badge ' + (pipeline?.conclusion === 'success' ? 'good' : pipeline?.conclusion === 'failure' ? 'warn' : '');
    $('refresh').textContent = `Report · ${stamp(report.generated_at)}`;
    const stale = Date.now() / 1000 - report.generated_at > 180;
    $('notice').hidden = !report.source_stale && !stale && !dirty;
    $('notice').textContent = dirty ? 'Uncommitted production edits detected. Automatic deployments are blocked until they are committed or reverted. See Live edits for filenames.' : stale ? 'This report is older than expected. The last available data is shown below.' : 'GitHub is temporarily unavailable. The last available source comparison is shown below.';
    $('source-time').textContent = report.source_updated_at ? `Source checked ${stamp(report.source_updated_at)} · config refreshes every minute` : 'Waiting for source information';
    render();
  } catch (_) { $('notice').hidden = false; $('notice').textContent = 'Could not refresh the report. Retrying automatically.'; }
}
document.querySelectorAll('[role=tab]').forEach((tab, index, tabs) => { tab.addEventListener('click', () => { view = tab.dataset.view; $('search').value = ''; render(); }); tab.addEventListener('keydown', e => { if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') { e.preventDefault(); const next = tabs[(index + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length]; next.click(); next.focus(); } }); });
$('search').addEventListener('input', render);
refresh(); setInterval(refresh, 30000);
