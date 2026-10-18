const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
test('fresh production administrator signs in across the configured farm', async ({ page }) => {
  test.skip(!process.env.PRODUCTION_TEST_DOMAIN, 'Explicit isolated production rehearsal only');
  const domain = process.env.PRODUCTION_TEST_DOMAIN;
  if (process.env.PRODUCTION_TEST_DEBUG) {
    page.on('response', response => { const u=new URL(response.url()); if (/Central|UserLogin/.test(u.pathname)) console.log(response.status(), u.hostname, u.pathname, response.headers()['x-centralauth-status'] || ''); });
    page.on('requestfailed', request => {const u=new URL(request.url()); console.log('Request failed',u.hostname,u.pathname,request.failure()?.errorText);});
  }
  const outbound = new Set();
  await page.route('**/*', async route => {
    const host = new URL(route.request().url()).hostname;
    if (host && host !== domain && !host.endsWith('.' + domain)) {
      outbound.add(host); await route.abort();
    } else { await route.continue(); }
  });
  const password = fs.readFileSync(path.resolve(process.env.PRODUCTION_TEST_SECRETS, 'admin-password'), 'utf8').trim();
  const user = async () => {
    try { return await page.evaluate(async () => {
      const data = await (await fetch('/api.php?action=query&meta=userinfo&format=json', {cache:'no-store'})).json();
      return data.query?.userinfo?.anon === undefined ? data.query?.userinfo?.name : null;
    }); } catch { return null; }
  };
  await page.goto(`https://test.${domain}/wiki/Special:UserLogin`, {waitUntil:'domcontentloaded'});
  await page.locator('[name=wpName]').fill('Admin');
  await page.locator('[name=wpPassword]').fill(password);
  await page.locator('[name=wploginattempt]').click();
  await expect.poll(() => user(), {timeout:30000}).toBe('Admin');
  // Finish the login page's background edge-login redirects before navigating away.
  await page.waitForLoadState('networkidle');
  for (const wiki of ['meta','login','test']) {
    await page.goto(`https://${wiki}.${domain}/wiki/Main_Page`, {waitUntil:'domcontentloaded'});
    await expect.poll(() => user(), {timeout:15000, message:`Shared login on ${wiki}`}).toBe('Admin');
  }
  expect([...outbound], 'Fresh production requested legacy external assets or integrations').toEqual([]);
  for (const cookie of (await page.context().cookies()).filter(c => /Session$/.test(c.name))) {
    expect(cookie.secure).toBe(true); expect(cookie.httpOnly).toBe(true);
  }
  await page.goto(`https://test.${domain}/wiki/Special:UserLogout`, {waitUntil:'domcontentloaded'});
  await page.getByRole('button', {name:'Log out', exact:true}).click();
  await page.goto(`https://meta.${domain}/wiki/Main_Page`, {waitUntil:'domcontentloaded'});
  await expect.poll(() => user(), {timeout:30000}).toBeNull();
});
