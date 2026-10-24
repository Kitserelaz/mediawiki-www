const path = require('node:path');
module.exports = {
  ...require('./docker/tests/playwright.config.js'),
  testDir: path.join(__dirname, 'docker/tests'),
  outputDir: path.join(__dirname, 'docker/tests/test-results'),
};
