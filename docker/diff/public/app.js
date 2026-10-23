'use strict';
let report, view = 'pending';
const $ = id => document.getElementById(id);
const element = (tag, text, cls) => { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (cls) n.className = cls; return n; };
const short = sha => sha.slice(0, 8);
const link = (text, url) => { const a = element('a', text); a.href = url; return a; };
const repo = 'https://github.com/Kitserelaz/mediawiki-www';
const stamp = seconds => new Date(seconds * 1000).toLocaleString();
const duration = value => {
  if (!Number.isFinite(value)) return '—';
  const seconds = Math.max(0, Math.floor(value)), minutes = Math.floor(seconds / 60);
  return minutes >= 60 ? `${Math.floor(minutes / 60)}h ${minutes % 60}m` : minutes ? `${minutes}m ${seconds % 60}s` : `${seconds}s`;
};
const epoch = value => typeof value === 'number' ? value : Date.parse(value) / 1000;
const elapsed = (start, end) => start ? duration((end ? epoch(end) : Date.now() / 1000) - epoch(start)) : 'Not started';
const age = value => value ? `${duration(Date.now() / 1000 - epoch(value))} ago` : 'Not reported';
const statusLabel = item => ({success: 'Passed', failure: 'Failed', cancelled: 'Cancelled', skipped: 'Skipped',
  timed_out: 'Timed out', action_required: 'Action required', neutral: 'Neutral', in_progress: 'Running',
  queued: 'Queued', pending: 'Pending', waiting: 'Waiting', requested: 'Requested', completed: 'Completed'}[item.conclusion || item.status] || item.status || 'Unknown');
const stateIcon = item => item.conclusion === 'success' ? '✓' : ['failure', 'timed_out', 'cancelled'].includes(item.conclusion) ? '×' : item.status === 'in_progress' ? '●' : '○';
const stages = {validating: 'Checking configuration and live edits', fetching: 'Fetching the requested commit',
  pulling_images: 'Pulling tested images', preparing_source: 'Preparing release source', backup: 'Taking a recovery backup',
  switching_release: 'Switching release', migrations: 'Running database migrations', health_checks: 'Checking origin health',
  starting_services: 'Starting services and waiting for container health', restoring_editor: 'Restoring the live editor', complete: 'Deployment complete', superseded: 'Superseded by a newer commit'};
const reasons = {local_configuration_changed: 'Blocked: local configuration differs from the accepted baseline.',
  uncommitted_files: 'Blocked: production has uncommitted changes.', insufficient_disk: 'Blocked: less than 5 GiB of storage is free.',
  stage_failed: 'This stage failed. Open the deployment job on GitHub for the error and recovery instructions.'};
function renderPipeline() {
  if (!report) return;
  const pipeline = report.pipeline || {}, now = Date.now() / 1000, jobs = pipeline.jobs || [];
  const sha = pipeline.head_sha;
  $('build-title').textContent = sha ? `Build progress · ${short(sha)}${sha === report.deployed_commit ? ' · live in production' : ' · latest main'}` : 'Build progress unavailable';
  $('build-clock').textContent = pipeline.run_started_at ? `${statusLabel(pipeline)} · ${elapsed(pipeline.run_started_at, pipeline.status === 'completed' ? pipeline.updated_at : null)}` : pipeline.status === 'waiting_for_run' ? 'Waiting for GitHub to create the run' : '';
  $('build-freshness').textContent = `GitHub checked: ${age(pipeline.checked_at)}${pipeline.poll_interval > 60 ? ' · Public API polling every 10 minutes' : ''} · Report generated: ${age(report.generated_at)}${report.snapshot_at && now - report.snapshot_at > 180 ? ' · Release/config snapshot: ' + age(report.snapshot_at) : ''}`;
  const warnings = [];
  if (pipeline.id && pipeline.activity_available === false) warnings.push('Build heartbeat feed is unavailable; GitHub job and step status is still shown.');
  if (pipeline.stale || !pipeline.checked_at || now - pipeline.checked_at > Math.max(180, (pipeline.poll_interval || 60) * 2)) warnings.push('Build data is stale or unavailable. The last known state is shown; elapsed time is not proof of progress.');
  if (jobs.some(j => j.name === 'deploy' && j.status !== 'in_progress' && j.status !== 'completed')) warnings.push('Deployment is waiting for the required integration and architecture jobs, or production approval.');
  $('build-alert').hidden = !warnings.length;
  $('build-alert').textContent = warnings.join(' ');
  const expanded = new Set([...$('build-jobs').querySelectorAll('details[open]')].map(d => d.dataset.job));
  $('build-jobs').replaceChildren();
  for (const job of jobs) {
    const details = element('details', undefined, 'build-job'); details.dataset.job = String(job.id);
    details.open = expanded.has(String(job.id)) || job.status === 'in_progress';
    const summary = element('summary');
    summary.append(element('span', stateIcon(job), job.conclusion === 'success' ? 'good' : ['failure', 'timed_out'].includes(job.conclusion) ? 'warn' : 'muted'),
      element('span', job.name, 'job-name'), element('span', statusLabel(job), 'job-state'), element('span', elapsed(job.started_at, job.completed_at), 'job-duration'));
    details.append(summary);
    const body = element('div', undefined, 'job-body');
    body.append(link('View job and logs ↗', `${repo}/actions/runs/${pipeline.id}/job/${job.id}`));
    const current = (job.steps || []).find(s => s.status === 'in_progress');
    if (current) body.append(element('p', `Current step: ${current.name} · ${elapsed(current.started_at)}`, 'current-step'));
    const activities = (pipeline.activity || []).filter(a => a.job === job.name && a.status === 'running' && job.status === 'in_progress' && (!current?.started_at || a.started_at >= epoch(current.started_at) - 10));
    const activity = activities.sort((a, b) => b.started_at - a.started_at)[0];
    if (activity) {
      body.append(element('p', `${activity.phase} · Runner heartbeat: ${age(activity.heartbeat_at)} · Last build output: ${age(activity.last_output_at)}`, 'activity'));
      if (now - activity.heartbeat_at > 180) body.append(element('p', 'Build heartbeat overdue. Check the job log and runner; the build may have stopped or reporting may be unavailable.', 'warn'));
      else if (activity.last_output_at && now - activity.last_output_at > 300) body.append(element('p', 'The runner is reporting, but this build has produced no output for over five minutes.', 'warn'));
    } else if (current) body.append(element('p', 'No build heartbeat for this step. GitHub step status is the available signal.', 'muted'));
    const key = current ? `${job.name} / ${current.name}` : job.name;
    const stepBaseline = pipeline.history?.durations?.[key];
    const baseline = stepBaseline?.samples >= 3 ? stepBaseline : pipeline.history?.durations?.[job.name];
    if (baseline?.samples >= 3) {
      body.append(element('p', `Typical successful ${stepBaseline?.samples >= 3 && current ? 'step' : 'job'} duration: ${duration(baseline.median)} · based on ${baseline.samples} runs`, 'muted'));
      const start = stepBaseline?.samples >= 3 ? current?.started_at || job.started_at : job.started_at;
      if (job.status === 'in_progress' && start && now - epoch(start) > Math.max(300, baseline.median * 1.5)) body.append(element('p', 'Taking longer than usual. This is a timing warning, not a confirmed stall.', 'warn'));
    }
    const list = element('ol', undefined, 'build-steps');
    for (const step of job.steps || []) {
      const advisory = step.conclusion === 'failure' && /^Catalogue (registration checks|skin asset checks) \(advisory\)$/.test(step.name) && !['failure', 'timed_out'].includes(job.conclusion);
      const row = element('li'); row.append(element('span', `${stateIcon(step)} ${step.name}`, 'step-name'),
        element('span', advisory ? 'Advisory warning' : statusLabel(step), advisory || step.conclusion === 'failure' ? 'warn' : 'muted'),
        element('span', elapsed(step.started_at, step.completed_at), 'job-duration')); list.append(row);
    }
    body.append(list); details.append(body); $('build-jobs').append(details);
  }
  if (!jobs.length) $('build-jobs').append(element('p', pipeline.status === 'waiting_for_run' ? 'No workflow run exists for this exact main commit yet.' : 'Job details are not available yet.', 'empty'));
  const deployment = report.deployment;
  const deploymentJob = jobs.find(j => j.name === 'deploy');
  $('deployment-progress').replaceChildren(element('h3', 'Production deployment'));
  if (deployment) {
    $('deployment-progress').append(element('p', `${short(deployment.commit)} · ${deployment.status} · ${stages[deployment.stage] || 'Status unavailable'}`));
    $('deployment-progress').append(element('p', `Total: ${elapsed(deployment.started_at, deployment.status === 'running' ? null : deployment.updated_at)} · Stage: ${elapsed(deployment.stage_started_at, deployment.status === 'running' ? null : deployment.updated_at)} · Heartbeat: ${age(deployment.heartbeat_at)}`, 'muted'));
    if (deployment.reason) $('deployment-progress').append(element('p', reasons[deployment.reason] || 'See the deployment job for details.', 'warn'));
    if (deployment.status === 'running' && now - deployment.heartbeat_at > 150) $('deployment-progress').append(element('p', 'Deployment heartbeat overdue. The process may have stopped; check the deployment log.', 'warn'));
    if (sha && deployment.commit !== sha) $('deployment-progress').append(element('p', deploymentJob?.status === 'in_progress' ? 'The current deployment job is running; waiting for server acceptance or the deployment lock. This record belongs to a different commit.' : 'This deployment record belongs to a different commit than the build above.', 'muted'));
  } else $('deployment-progress').append(element('p', deploymentJob?.status === 'in_progress' ? 'The deployment job is running; waiting for server acceptance or the deployment lock.' : sha === report.deployed_commit ? 'This commit is already live. Stage reporting begins with the next automatic deployment.' : 'Not started for this build. Deployment begins after required checks pass.', 'muted'));
}
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
    $('pipeline').href = pipeline?.id ? `${repo}/actions/runs/${pipeline.id}` : `${repo}/actions`;
    $('pipeline').textContent = pipeline?.id ? `CI · ${statusLabel(pipeline)}` : 'CI · awaiting run';
    $('pipeline').className = 'badge ' + (pipeline?.conclusion === 'success' ? 'good' : pipeline?.conclusion === 'failure' ? 'warn' : '');
    $('refresh').textContent = `Report · ${stamp(report.generated_at)}`;
    const stale = Date.now() / 1000 - report.generated_at > 180;
    $('notice').hidden = !report.source_stale && !stale && !dirty;
    $('notice').textContent = dirty ? 'Uncommitted production edits detected. Automatic deployments are blocked until they are committed or reverted. See Live edits for filenames.' : stale ? 'This report is older than expected. The last available data is shown below.' : 'GitHub is temporarily unavailable. The last available source comparison is shown below.';
    $('source-time').textContent = report.source_updated_at ? `Source checked ${stamp(report.source_updated_at)} · config refreshes every minute` : 'Waiting for source information';
    renderPipeline();
    render();
  } catch (_) { $('notice').hidden = false; $('notice').textContent = 'Could not refresh the report. Retrying automatically.'; }
}
document.querySelectorAll('[role=tab]').forEach((tab, index, tabs) => { tab.addEventListener('click', () => { view = tab.dataset.view; $('search').value = ''; render(); }); tab.addEventListener('keydown', e => { if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') { e.preventDefault(); const next = tabs[(index + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length]; next.click(); next.focus(); } }); });
$('search').addEventListener('input', render);
refresh(); setInterval(refresh, 30000); setInterval(renderPipeline, 5000);
