require('./playwright-env.cjs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const args = process.argv.slice(2);

if (args[0] === 'timing') {
  require('../docker/tests/creation-timing.cjs');
} else {
  if (!args.length) args.push('test');
  const result = spawnSync(process.execPath, [require.resolve('@playwright/test/cli'), ...args], {
    cwd: path.join(root, 'docker/tests'),
    stdio: 'inherit',
    env: process.env,
  });
  if (result.error) throw result.error;
  process.exitCode = result.status ?? 1;
}
