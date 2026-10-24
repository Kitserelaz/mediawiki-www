const path = require('node:path');

// Keep local browsers in the checkout; CI retains its existing shared cache.
if (!process.env.CI && !process.env.PLAYWRIGHT_BROWSERS_PATH) {
  process.env.PLAYWRIGHT_BROWSERS_PATH = path.resolve(__dirname, '../docker/.browsers');
}
