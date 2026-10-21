"use strict";

const { test } = require("node:test");
const assert = require("node:assert/strict");
const { createCounterStore } = require("../utils/counter-store");

function database() {
  const rows = new Map();
  return {
    rows,
    getNum: async (name) => ({ name, num: rows.get(name) || 0 }),
    setNumMulti: async (counters) => {
      for (const { name, num } of counters) rows.set(name, num);
    },
  };
}

test("concurrent visits each increment the stored counter", async () => {
  const db = database();
  const store = createCounterStore(db);
  const results = await Promise.all(Array.from({ length: 100 }, () => store.increment("visits")));
  assert.deepEqual(results.map(({ num }) => num), Array.from({ length: 100 }, (_, i) => i + 1));
  assert.equal(db.rows.get("visits"), 100);
  await store.close();
});

test("visits arriving during a flush survive it", async () => {
  const db = database();
  let release;
  const originalWrite = db.setNumMulti;
  db.setNumMulti = async (rows) => {
    await new Promise((resolve) => { release = resolve; });
    await originalWrite(rows);
  };
  const store = createCounterStore(db, { intervalMs: 60000 });
  await store.increment("visits");
  const flushing = store.flush();
  // Let the queued flush reach the database.
  await Promise.resolve();
  const visiting = store.increment("visits");
  release();
  await flushing;
  assert.equal((await visiting).num, 2);
  db.setNumMulti = originalWrite;
  await store.close();
  assert.equal(db.rows.get("visits"), 2);
});

test("the timer writes idle counters without another visit", async (t) => {
  t.mock.timers.enable({ apis: ["setInterval"] });
  const db = database();
  const store = createCounterStore(db, { intervalMs: 1000 });
  await store.increment("idle");
  t.mock.timers.tick(1000);
  await store.read("idle");
  assert.equal(db.rows.get("idle"), 1);
  await store.close();
});

test("closing drains pending counters before the next interval", async () => {
  const db = database();
  const store = createCounterStore(db, { intervalMs: 60000 });
  const visiting = store.increment("last");
  await store.close();
  await visiting;
  assert.equal(db.rows.get("last"), 1);
});

test("a failed write retains the batch for retry", async () => {
  const db = database();
  const originalWrite = db.setNumMulti;
  const store = createCounterStore(db, { intervalMs: 60000 });
  await store.increment("visits");
  db.setNumMulti = async () => { throw new Error("offline"); };
  await assert.rejects(store.flush(), /offline/);
  assert.equal((await store.increment("visits")).num, 2);
  db.setNumMulti = originalWrite;
  await store.close();
  assert.equal(db.rows.get("visits"), 2);
});

test("record reads do not increment, including reserved object names", async () => {
  const db = database();
  const store = createCounterStore(db);
  for (const name of ["constructor", "__proto__", "toString"]) {
    assert.deepEqual(await store.read(name), { name, num: 0 });
    assert.deepEqual(await store.increment(name), { name, num: 1 });
    assert.deepEqual(await store.read(name), { name, num: 1 });
    assert.equal(db.rows.get(name), 1);
  }
  await store.close();
});
