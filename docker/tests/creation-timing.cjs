// Time actual localhost form creation and worker provisioning, excluding login.
// From the repository root: npm run test:creation-timing
require('../../scripts/playwright-env.cjs');
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { execFile } = require('node:child_process');
const { promisify } = require('node:util');
const exec = promisify(execFile);
const root = path.resolve(__dirname, '../..');
const reportPath = process.env.CREATION_TIMING_REPORT || '/tmp/wiki-creation-timing.json';
const idleBeforeLast = Number(process.env.CREATION_TIMING_IDLE_BEFORE_LAST_MS || 0);
if (!Number.isFinite(idleBeforeLast) || idleBeforeLast < 0) throw new Error('Invalid idle duration');
const report = { samples: [], status: 'running', method: 'Sequential fresh wiki creations through Special:CreateWiki; login and form preparation excluded. Provisioning completion observed every 500 ms.' };
const save = () => fs.writeFileSync(reportPath, JSON.stringify(report, null, 2) + '\n');
const probe = `require '/opt/farm/dispatch.php';
$wiki=$argv[1];$r=FarmDispatch::connect();$status=$r->hGetAll(FarmDispatch::statusKey($wiki));
$p='/var/www/mediawiki/cw_cache/.provision-'.$wiki;$lock=$p.'.lock';
$metadataLog=@file_get_contents($p.'.log')?:'';
preg_match('/^Provisioning metadata: (creator|repair)$/m',$metadataLog,$metadataMatch);
echo json_encode(['pending'=>is_file($p)||$r->hExists(FarmDispatch::PROVISION_PENDING,$wiki),
'marker_ms'=>is_file($p)?filemtime($p)*1000:null,'queued_ms'=>isset($status['queued_ms'])?(float)$status['queued_ms']:null,
'lock_ms'=>is_file($lock)?filemtime($lock)*1000:null,'started_ms'=>isset($status['started_ms'])?(float)$status['started_ms']:null,
'finished_ms'=>isset($status['finished_ms'])?(float)$status['finished_ms']:null,
'metadata_source'=>$metadataMatch[1]??null,
'stage_timings'=>is_file($p.'.timing.json')?json_decode(file_get_contents($p.'.timing.json'),true):null]);`;
async function provisioning(wiki) {
  const { stdout } = await exec('docker', ['compose', 'exec', '-T', 'php', 'php', '-r', probe, wiki], { cwd: root });
  return JSON.parse(stdout);
}
(async () => {
  const { stdout } = await exec('docker', ['compose', '--profile', 'tools', 'config', '--format', 'json'], { cwd: root });
  const config = JSON.parse(stdout);
  const env = config.services.init.environment;
  if (env.MW_ENV !== 'development' || env.MW_DOMAIN !== 'localhost') throw new Error('Timing requires the localhost development stack');
  const username = env.ADMIN_USER || 'Admin';
  const password = fs.readFileSync(config.secrets['admin-password'].file, 'utf8').trim();
  const browser = await chromium.launch({ headless: true, args: ['--host-resolver-rules=MAP *.localhost 127.0.0.1', '--no-proxy-server'] });
  report.prefix = '9timing' + crypto.randomBytes(4).toString('hex');
  report.started = new Date().toISOString();
  report.timing_clock = 'Host monotonic duration; queue/start times use Redis stream/status timestamps, with legacy Docker volume file timestamps as fallback.';
  save();
  try {
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const anonymous = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await context.newPage();
    await page.goto('https://meta.localhost/wiki/Special:UserLogin', { waitUntil: 'domcontentloaded' });
    await page.locator('[name=wpName]').fill(username);
    await page.locator('[name=wpPassword]').fill(password);
    await page.locator('[name=wploginattempt]').click();
    await page.waitForFunction(name => window.mw?.config.get('wgUserName') === name, username, { timeout: 30000 });
    for (const [index, isPrivate] of [false, true, false].entries()) {
      if (index === 2 && idleBeforeLast) {
        console.log(`Leaving creation traffic idle for ${idleBeforeLast} ms before the final sample`);
        await new Promise(resolve => setTimeout(resolve, idleBeforeLast));
      }
      const subdomain = report.prefix + (isPrivate ? 'private' : 'public' + (index + 1));
      const wiki = subdomain + 'wiki';
      await page.goto('https://meta.localhost/wiki/Special:CreateWiki', { waitUntil: 'domcontentloaded' });
      await page.locator('[name=wpdbname]').fill(wiki);
      await page.locator('[name=wprequester]').fill(username);
      await page.locator('[name=wpsitename]').fill('Wiki creation timing ' + (index + 1));
      await page.locator('[name=wpreason]').fill('Measure fresh wiki creation and background provisioning on localhost');
      await page.locator('[name=wpprivate]').setChecked(isPrivate);
      const form = page.locator('form').filter({ has: page.locator('[name=wpdbname]') });
      const startedWall = Date.now();
      const started = performance.now();
      const submission = page.waitForResponse(response => response.request().method() === 'POST' &&
        (response.request().postData() || '').includes('wpdbname=' + wiki));
      await form.locator('button[type=submit], input[type=submit]').click();
      await page.locator('.cdx-message--success').first().waitFor({ timeout: 60000 });
      const accepted = performance.now() - started;
      if (!(await page.locator('.cdx-message--success').first().innerText()).includes('The wiki was successfully created.')) throw new Error('Creation was not accepted');
      const submissionResponse = await submission;
      await submissionResponse.finished();
      const formHttpTiming = submissionResponse.request().timing();
      let markerMs = null;
      let state;
      while (performance.now() - started < 180000) {
        state = await provisioning(wiki);
        if (state.queued_ms !== null || state.marker_ms !== null) markerMs = state.queued_ms ?? state.marker_ms;
        if (state.lock_ms !== null && !state.pending) break;
        await new Promise(resolve => setTimeout(resolve, 500));
      }
      if (!state?.lock_ms || state.pending) throw new Error('Timed out provisioning ' + wiki);
      const provisioned = performance.now() - started;
      const workerStart = state.started_ms ?? state.lock_ms;
      const apiStarted = performance.now();
      const response = await anonymous.request.get(`https://${subdomain}.localhost/api.php?action=query&meta=siteinfo&format=json`, { timeout: 20000 });
      const data = await response.json();
      if (isPrivate ? data.error?.code !== 'readapidenied' : !data.query?.general) throw new Error('New wiki API readiness/privacy check failed');
      const sample = {
        wiki, private: isPrivate, form_success_ms: Math.round(accepted),
        idle_before_ms: index === 2 ? idleBeforeLast : 0,
        form_http_timing: formHttpTiming,
        worker_start_after_submit_ms: Math.round(workerStart - startedWall),
        queue_wait_ms: markerMs === null ? null : Math.round(workerStart - markerMs),
        provisioning_after_worker_start_ms: Math.round(provisioned - (workerStart - startedWall)),
        provisioning_finished_after_submit_ms: Math.round(provisioned),
        provisioning_actual_ms: state.finished_ms === null ? null : Math.round(state.finished_ms - workerStart),
        provisioning_completed_after_submit_ms: state.finished_ms === null ? null : Math.round(state.finished_ms - startedWall),
        completion_observation_overhead_ms: state.finished_ms === null ? null : Math.round(provisioned - (state.finished_ms - startedWall)),
        api_verification_ms: Math.round(performance.now() - apiStarted),
        stage_timings: state.stage_timings,
        metadata_source: state.metadata_source,
        verified_ready_after_submit_ms: Math.round(performance.now() - started),
        api_check: isPrivate ? 'anonymous read denied' : 'public siteinfo ready'
      };
      report.samples.push(sample); save(); console.log(JSON.stringify(sample));
    }
    report.status = 'passed';
    report.finished = new Date().toISOString();
    save();
  } catch (error) {
    report.status = 'failed'; report.error = error.message; save(); throw error;
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
