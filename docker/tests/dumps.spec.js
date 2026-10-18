const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const zlib = require('node:zlib');

test('DataDump jobs generate and serve XML and upload ZIP archives', async ({ page }) => {
  test.setTimeout(180000);
  const username = process.env.ADMIN_USER || 'Admin';
  const password = fs.readFileSync(path.resolve(__dirname, '../secrets/admin-password'), 'utf8').trim();
  const api = 'https://test.localhost/api.php';
  await page.goto('https://test.localhost/wiki/Special:UserLogin', {waitUntil:'domcontentloaded'});
  await page.locator('[name=wpName]').fill(username);
  await page.locator('[name=wpPassword]').fill(password);
  await page.locator('[name=wploginattempt]').click();
  await page.waitForURL(url => url.hostname === 'test.localhost');
  const tokenResponse = await page.request.get(api, {params:{action:'query',meta:'tokens',format:'json'}});
  const token = (await tokenResponse.json()).query.tokens.csrftoken;
  for (const type of ['xml', 'zip']) {
    const list = async () => {
      const response = await page.request.get(api, {params:{action:'viewdumps',type,format:'json'}});
      const data = await response.json();
      expect(data.error, JSON.stringify(data)).toBeUndefined();
      return Object.values(data.viewdumps || {});
    };
    const previous = await list();
    // Never delete a developer's existing dump to make room for this test.
    expect(previous, `Use a fresh fixture or remove the existing ${type} dump before this test`).toEqual([]);
    let created;
    try {
      const generated = await page.request.post(api, {form:{action:'generatedumps',type,token,format:'json'}});
      const data = await generated.json();
      expect(data.error, JSON.stringify(data)).toBeUndefined();
      await expect.poll(async () => {
        created = (await list())[0];
        expect(created?.status, 'DataDump worker reported a failed generation').not.toBe('failed');
        return created?.status;
      }, {timeout:60000, intervals:[500,1000,2000]}).toBe('completed');
      const download = await page.request.get(created.link);
      expect(download.status()).toBe(200);
      const content = await download.body();
      if (type === 'xml') expect(zlib.gunzipSync(content).toString()).toContain('<mediawiki');
      else expect(content.subarray(0,2).toString()).toBe('PK');
    } finally {
      if (!created) created = (await list())[0];
      if (created) {
        const removed = await page.request.post(api, {form:{action:'deletedumps',type,filename:created.filename,token,format:'json'}});
        const data = await removed.json();
        expect(data.error, JSON.stringify(data)).toBeUndefined();
      }
    }
  }
});
