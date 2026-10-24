require('../../scripts/playwright-env.cjs');
const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  testDir: '.',
  testMatch: '*.spec.js',
  workers: 1,
  timeout: 90000,
  use: { ignoreHTTPSErrors: true, headless: true, launchOptions: {args: process.env.PRODUCTION_TEST_DOMAIN ? [`--host-resolver-rules=MAP *.${process.env.PRODUCTION_TEST_DOMAIN} 127.0.0.1`, "--no-proxy-server"] : []} },
  reporter: 'list',
});
