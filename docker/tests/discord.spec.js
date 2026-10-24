const { test, expect } = require('@playwright/test');

async function interceptDiscord(page, handle) {
  // Playwright only intercepts the first request in a redirect chain. Inspect
  // local navigation responses before the browser follows them to Discord.
  await page.route('**/*', async route => {
    const request = route.request();
    if (!request.isNavigationRequest() || !new URL(request.url()).hostname.endsWith('.localhost')) {
      await route.continue();
      return;
    }
    const response = await route.fetch({maxRedirects:0});
    const location = response.headers().location || '';
    if (location.startsWith('https://discord.com/oauth2/authorize?')) {
      await handle(route, new URL(location), response);
    } else {
      await route.fulfill({response});
    }
  });
}

for (const wiki of ['meta', 'test']) {
for (const special of ['UserLogin', 'CreateAccount']) {
  test(`Discord starts with an empty username on ${wiki}:${special}`, async ({ page }) => {
    let authorization;
    await interceptDiscord(page, async (route, url, response) => {
      authorization = url;
      await route.fulfill({response, status:200, contentType:'text/html', body:'<h1>Discord authorization fixture</h1>'});
    });
    await page.goto(`https://${wiki}.localhost/wiki/Special:${special}`, {waitUntil:'domcontentloaded'});
    await expect(page.locator('[name=wpName]')).toHaveValue('');
    const discord = page.getByRole('link', {name:'Continue with Discord', exact:true});
    await expect(discord).toBeVisible();
    const startToken = new URL(page.url()).searchParams.get('centralauthLoginToken');
    if (startToken) {
      expect(new URL(await discord.getAttribute('href'), page.url()).searchParams.get('centralauthLoginToken')).toBe(startToken);
    }
    await discord.click();
    await expect(page.getByRole('heading', {name:'Discord authorization fixture'})).toBeVisible();
    expect(authorization.searchParams.get('scope')).toBe('identify email');
    expect(authorization.searchParams.get('redirect_uri')).toBe('https://auth.localhost/metawiki/wiki/Special:DiscordCallback');
    expect(authorization.searchParams.has('username')).toBe(false);
    expect(authorization.searchParams.has('client_secret')).toBe(false);
    expect(authorization.searchParams.get('state')).toMatch(/^[a-f0-9]{64}$/);
  });
}
}

test('a forged callback does not open the username page', async ({ page }) => {
  await page.goto('https://auth.localhost/metawiki/wiki/Special:DiscordCallback?state=forged&code=fake');
  await expect(page.locator('body')).toContainText('Your Discord sign-in session expired or could not be verified.');
  await expect(page.locator('[name=wpName]')).toHaveCount(0);
});

test('username selection requires a verified Discord session', async ({ page }) => {
  await page.goto('https://auth.localhost/metawiki/wiki/Special:DiscordSignup');
  await expect(page.locator('body')).toContainText('Your Discord sign-in session expired or could not be verified.');
  await expect(page.locator('[name=wpName]')).toHaveCount(0);
});

test('cancelling Discord returns a cancellation message without creating an account', async ({ page }) => {
  let cancelledCallback;
  await interceptDiscord(page, async (route, authorize, response) => {
    const callback = new URL(authorize.searchParams.get('redirect_uri'));
    callback.searchParams.set('state', authorize.searchParams.get('state'));
    callback.searchParams.set('error', 'access_denied');
    cancelledCallback = callback.href;
    await route.fulfill({response, status:200, contentType:'text/html', body:'<h1>Discord cancellation fixture</h1>'});
  });
  await page.goto('https://meta.localhost/wiki/Special:UserLogin');
  await page.getByRole('link', {name:'Continue with Discord', exact:true}).click();
  await expect(page.getByRole('heading', {name:'Discord cancellation fixture'})).toBeVisible();
  await page.goto(cancelledCallback);
  await expect(page.locator('body')).toContainText('Discord sign-in was cancelled.');
});

test('OAuth transactions survive wiki session loss, reject another browser and cannot be replayed', async ({ page, browser }) => {
  const callbacks = [];
  await interceptDiscord(page, async (route, authorize, response) => {
    const url = new URL(authorize.searchParams.get('redirect_uri'));
    url.searchParams.set('state', authorize.searchParams.get('state'));
    url.searchParams.set('error', 'access_denied');
    callbacks.push(url.href);
    await route.fulfill({response, status:200, contentType:'text/html', body:'<h1>Authorization fixture</h1>'});
  });
  // Starting a second transaction must not replace the first one.
  for (const wiki of ['meta', 'test']) {
    await page.goto(`https://auth.localhost/${wiki}wiki/wiki/Special:DiscordLogin`);
  }
  expect(callbacks).toHaveLength(2);
  const bindings = (await page.context().cookies()).filter(c => c.name.startsWith('__Host-discordAuth-'));
  expect(bindings).toHaveLength(2);
  for (const cookie of bindings) {
    expect(cookie.secure && cookie.httpOnly).toBe(true);
    expect(cookie.sameSite).toBe('Lax');
    expect(cookie.domain).toBe('auth.localhost');
    expect(cookie.path).toBe('/');
  }
  const other = await browser.newContext({ignoreHTTPSErrors:true});
  try {
    const otherPage = await other.newPage();
    await otherPage.goto(callbacks[0]);
    await expect(otherPage.locator('body')).toContainText('Your Discord sign-in session expired or could not be verified.');
  } finally { await other.close(); }
  await page.context().clearCookies();
  await page.context().addCookies(bindings);
  for (const callback of callbacks) {
    await page.goto(callback);
    await expect(page.locator('body')).toContainText('Discord sign-in was cancelled.');
    await page.goto(callback);
    await expect(page.locator('body')).toContainText('Your Discord sign-in session expired or could not be verified.');
  }
});

for (const {wiki, direct} of [{wiki:'meta'}, {wiki:'test'}, {wiki:'test', direct:true}]) {
  test(`verified Discord signup returns directly to ${wiki} wiki${direct ? ' without a return token' : ''}`, async ({ page }) => {
    test.skip(process.env.DISCORD_BROWSER_FIXTURES !== '1', 'Explicit development fixture mode required');
    let authorization;
    await interceptDiscord(page, async (route, url, response) => {
      authorization = url;
      await route.fulfill({response, status:200, contentType:'text/html', body:'<h1>Discord identity fixture</h1>'});
    });
    let startUrl;
    if (direct) {
      startUrl = new URL(`https://auth.localhost/${wiki}wiki/wiki/Special:DiscordLogin?returnto=Main_Page`);
      await page.goto(startUrl.href);
    } else {
      await page.goto(`https://${wiki}.localhost/wiki/Special:UserLogin?returnto=Main_Page`);
      const button = page.getByRole('link', {name:'Continue with Discord', exact:true});
      startUrl = new URL(await button.getAttribute('href'), page.url());
      await button.click();
    }
    await expect(page.getByRole('heading', {name:'Discord identity fixture'})).toBeVisible();
    const state = authorization.searchParams.get('state');
    const binding = (await page.context().cookies('https://auth.localhost'))
      .find(cookie => cookie.name === `__Host-discordAuth-${state}`);
    expect(Boolean(binding)).toBe(true);
    // Delete all wiki session cookies before the callback. Only the OAuth binding survives.
    await page.context().clearCookies();
    await page.context().addCookies([binding]);
    const { spawnSync } = require('node:child_process');
    const result = spawnSync('docker', ['compose', 'exec', '-T', 'php', 'php', 'maintenance/run.php',
      '/var/www/mediawiki/extensions/DiscordAuth/tests/maintenance/ExerciseAuthentication.php',
      '--wiki=metawiki', '--resume-browser'], {
        cwd:require('node:path').resolve(__dirname, '../..'),
        input:JSON.stringify({state, binding:binding.value}), encoding:'utf8', timeout:30000
      });
    expect(result.status, result.stderr).toBe(0);
    const verified = JSON.parse(result.stdout.trim().split('\n').at(-1));
    await page.context().addCookies([{name:`__Host-discordAuth-${verified.state}`, value:verified.binding,
      domain:'auth.localhost', path:'/', secure:true, httpOnly:true, sameSite:'Lax'}]);
    await page.goto(verified.returnUrl);
    await expect(page.getByRole('heading', {name:'Choose your wiki username'})).toBeVisible();
    await expect(page.locator('[name=wpPassword]')).toHaveCount(0);
    const username = `DiscordBrowser${wiki}${Date.now()}`;
    await expect(page.getByRole('textbox', {name:'Username', exact:true})).toHaveValue('Discord-fixture');
    await expect(page.getByRole('textbox', {name:/Email/})).toHaveCount(0);
    await page.getByRole('textbox', {name:'Username', exact:true}).fill(username);
    const signupResponse = page.waitForResponse(response => response.request().method() === 'POST'
      && new URL(response.url()).pathname.endsWith('/wiki/Special:DiscordSignup'));
    await page.getByRole('button', {name:'Create your account', exact:true}).click();
    expect((await signupResponse).status()).toBe(302);
    await expect(page.getByRole('heading', {name:/^Welcome[,!]/})).toHaveCount(0);
    await expect(page.locator('.mw-returnto')).toHaveCount(0);
    await expect(page).toHaveURL(new RegExp(`^https://${wiki}\\.localhost/wiki/`));
    await expect.poll(async () => {
      const response = await page.request.get(`https://${wiki}.localhost/api.php?action=query&meta=userinfo&format=json`);
      return (await response.json()).query?.userinfo?.name;
    }).toBe(username);
  });
}

for (const special of ['UserLogin', 'CreateAccount']) {
test(`Social sign-in on ${special} sits beside the form and stacks on mobile`, async ({ page }) => {
  await page.setViewportSize({width:1440, height:1000});
  await page.goto(`https://meta.localhost/wiki/Special:${special}`);
  const cookieNotice = page.getByRole('button', {name:'OK', exact:true});
  if (await cookieNotice.isVisible()) await cookieNotice.click();
  const social = page.getByRole('complementary', {name:'Social sign-in', exact:true});
  const form = page.locator('#userloginForm');
  await expect(social.getByRole('heading', {name:'Social sign-in', exact:true})).toBeVisible();
  await expect(social.getByRole('link', {name:'Continue with Discord', exact:true})).toBeVisible();
  await expect(form.getByRole('link', {name:'Continue with Discord', exact:true})).toHaveCount(0);
  await expect(page.locator('.mw-createacct-benefits-container')).toHaveCount(0);
  await expect.poll(async () => {
    const a = await form.boundingBox(), b = await social.boundingBox();
    return b.x >= a.x + a.width - 1;
  }).toBe(true);
  await page.screenshot({path:`/tmp/discord-social-${special}-desktop.png`, fullPage:true});
  await page.setViewportSize({width:390, height:844});
  await expect.poll(async () => {
    const a = await form.boundingBox(), b = await social.boundingBox();
    return b.y >= a.y + a.height - 1;
  }).toBe(true);
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  await page.screenshot({path:`/tmp/discord-social-${special}-mobile.png`, fullPage:true});
});
}
