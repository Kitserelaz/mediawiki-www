'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { mkdtempSync, rmSync } = require('node:fs');
const { tmpdir } = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
test('DB_PATH creates nested storage and preserves both write APIs across processes', () => {
  const dir = mkdtempSync(path.join(tmpdir(), 'counter-sqlite-'));
  const env = { ...process.env, DB_PATH: path.join(dir, 'nested/count.db') };
  const run = source => {
    const result = spawnSync(process.execPath, ['-e', source], { cwd: path.join(__dirname, '..'), env, encoding: 'utf8' });
    assert.equal(result.status, 0, result.stderr);
    return result.stdout.trim();
  };
  try {
    run("const db=require('./db/sqlite'); (async()=>{await db.setNum('one',4);await db.setNumMulti([{name:'two',num:8}]);db.close()})().catch(e=>{console.error(e);process.exit(1)})");
    assert.equal(run("const db=require('./db/sqlite'); (async()=>{console.log(JSON.stringify([(await db.getNum('one')).num,(await db.getNum('two')).num]));db.close()})()"), '[4,8]');
  } finally { rmSync(dir, { recursive: true, force: true }); }
});
