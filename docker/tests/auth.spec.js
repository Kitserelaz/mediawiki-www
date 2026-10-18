const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const username = process.env.ADMIN_USER || 'Admin';
const password = fs.readFileSync(path.resolve(__dirname, '../secrets/admin-password'), 'utf8').trim();
async function currentUser(page) {
  try {
    return await page.evaluate(async () => {
      const result = await fetch((window.mw?.config.get('wgScriptPath') || '') + '/api.php?action=query&meta=userinfo&format=json', {cache:'no-store'});
      const data = await result.json();
      return data.query?.userinfo?.anon === undefined ? data.query?.userinfo?.name : null;
    });
  } catch { return null; }
}
test('browser login propagates between local wikis and global logout ends it', async ({ page }) => {
  const outbound = new Set();
  page.on('request', request => {
    const host = new URL(request.url()).hostname;
    if (host && host !== 'localhost' && !host.endsWith('.localhost')) outbound.add(host);
  });
  await page.goto('https://test.localhost/wiki/Special:UserLogin', {waitUntil:'domcontentloaded'});
  await page.locator('[name=wpName]').fill(username);
  await page.locator('[name=wpPassword]').fill(password);
  await page.locator('[name=wploginattempt]').click();
  await expect.poll(() => currentUser(page), { timeout: 30000 }).toBe(username);
  for (const wiki of ['meta', 'login', 'test']) {
    await page.goto(`https://${wiki}.localhost/wiki/Main_Page`, {waitUntil:'domcontentloaded'});
    // Allow MediaWiki's background SSO request to establish this host's cookie.
    await expect.poll(() => currentUser(page), { timeout: 15000 }).toBe(username);
  }
  await page.goto('https://meta.localhost/wiki/Special:CreateWiki', {waitUntil:'domcontentloaded'});
  await expect(page.locator('[name=wpdbname]')).toBeVisible();
  const uiWiki = `${process.env.DOCKER_FIXTURE_PREFIX || 'docker'}uismoke`;
  const uiApi = `https://${uiWiki}.localhost/api.php?action=query&meta=siteinfo&format=json`;
  const existing = await page.request.get(uiApi);
  if (existing.status() !== 200) {
    await page.locator('[name=wpdbname]').fill(uiWiki + 'wiki');
    await page.locator('[name=wprequester]').fill(username);
    await page.locator('[name=wpsitename]').fill('Docker browser fixture');
    await page.locator('[name=wpreason]').fill('Verify fresh administrator farm creation and worker provisioning');
    await page.locator('form').filter({has:page.locator('[name=wpdbname]')}).locator('button[type=submit], input[type=submit]').click();
    await expect(page.locator('.cdx-message--success').first()).toContainText('The wiki was successfully created.');
  }
  await expect.poll(async () => {
    const response = await page.request.get(uiApi);
    return response.status() === 200 && Boolean((await response.json()).query?.general);
  }, {timeout:60000}).toBe(true);
  const cookies = (await page.context().cookies()).filter(cookie => /Session$/.test(cookie.name));
  expect(cookies.length).toBeGreaterThan(0);
  for (const cookie of cookies) { expect(cookie.secure).toBe(true); expect(cookie.httpOnly).toBe(true); }
  expect([...outbound]).toEqual([]);
  await page.goto('https://test.localhost/wiki/Special:UserLogout', {waitUntil:'domcontentloaded'});
  await page.getByRole('button', {name:'Log out', exact:true}).click();
  await page.goto('https://meta.localhost/wiki/Main_Page');
  await expect.poll(() => currentUser(page), { timeout: 30000 }).toBeNull();
});

test('editing, Lua, uploads, and thumbnails work in the container', async ({ page }) => {
  await page.goto('https://test.localhost/wiki/Special:UserLogin', {waitUntil:'domcontentloaded'});
  await page.locator('[name=wpName]').fill(username);
  await page.locator('[name=wpPassword]').fill(password);
  await page.locator('[name=wploginattempt]').click();
  await expect.poll(() => currentUser(page), {timeout:30000}).toBe(username);
  await page.goto('https://test.localhost/wiki/Main_Page', {waitUntil:'domcontentloaded'});
  const token = await page.evaluate(async () => (await (await fetch('/api.php?action=query&meta=tokens&format=json')).json()).query.tokens.csrftoken);
  const edit = await page.request.post('https://test.localhost/api.php', {form:{action:'edit',title:'Module:DockerSmoke',text:'return { main = function() return "docker-lua-ok" end }',token,format:'json'}});
  const editResult = await edit.json();
  expect(editResult.edit?.result, JSON.stringify(editResult)).toBe('Success');
  const parsed = await page.request.get('https://test.localhost/api.php', {params:{action:'parse',text:'{{#invoke:DockerSmoke|main}}',contentmodel:'wikitext',format:'json'}});
  expect((await parsed.json()).parse?.text?.['*']).toContain('docker-lua-ok');
  const filename = `DockerSmoke-${Date.now()}.png`;
  const image = fs.readFileSync(path.resolve(__dirname, '../../Moe-Counter/assets/favicon.png'));
  const upload = await page.request.post('https://test.localhost/api.php', {multipart:{action:'upload',filename,ignorewarnings:'1',token,format:'json',file:{name:'DockerSmoke.png',mimeType:'image/png',buffer:image}}});
  const uploadResult = await upload.json();
  expect(uploadResult.upload?.result, JSON.stringify(uploadResult)).toBe('Success');
  const info = await page.request.get('https://test.localhost/api.php', {params:{action:'query',titles:'File:' + filename,prop:'imageinfo',iiprop:'url',iiurlwidth:'16',format:'json'}});
  const data = await info.json();
  const file = Object.values(data.query.pages)[0].imageinfo[0];
  const thumbnail = await page.request.get(file.thumburl || file.url);
  expect(thumbnail.status()).toBe(200);
  expect(thumbnail.headers()['content-type']).toMatch(/^image\//);
});

test('new public and private wikis finish provisioning and private files stay protected', async ({ page }) => {
  const prefix = process.env.DOCKER_FIXTURE_PREFIX || 'docker';
  await expect.poll(async () => {
    const response = await page.request.get(`https://${prefix}smoke.localhost/api.php?action=query&meta=siteinfo&format=json`);
    if (response.status() !== 200) return false;
    return Boolean((await response.json()).query?.general);
  }, {timeout:60000}).toBe(true);
  const privateApi = await page.request.get(`https://${prefix}private.localhost/api.php?action=query&meta=siteinfo&format=json`);
  expect((await privateApi.json()).error?.code).toBe('readapidenied');
  for (const url of [
    `https://${prefix}private.localhost/img_auth.php/docker-access-test.txt`,
    `https://${prefix}private.localhost/images/${prefix}privatewiki/docker-access-test.txt`,
    `https://${prefix}smoke.localhost/images/${prefix}privatewiki/docker-access-test.txt`,
  ]) {
    const response = await page.request.get(url);
    expect(await response.text()).not.toContain('Private development fixture');
    expect(response.status()).toBeGreaterThanOrEqual(400);
  }
});
