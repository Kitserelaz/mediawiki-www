"use strict";

const { test } = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
const { createRequire } = require("node:module");
const { createCounterStore } = require("../utils/counter-store");

function startFixture(db) {
  const entry = require.resolve("../index");
  const realRequire = createRequire(entry);
  const signals = new Map();
  const processStub = { env: { DB_INTERVAL: "60" }, once: (signal, handler) => signals.set(signal, handler) };
  let counters, shutdownComplete, closes = 0;
  const listener = {
    close(callback) {
      closes++;
      shutdownComplete = callback();
    },
  };
  const realExpress = realRequire("express");
  const express = () => {
    const app = realExpress();
    app.listen = () => listener;
    return app;
  };
  express.static = realExpress.static;
  const mocks = {
    express,
    dotenv: { config() {} },
    "./db": db,
    "./utils": { logger: { info() {}, debug() {}, error() {} }, randomArray: (items) => items[0] },
    "./utils/counter-store": {
      createCounterStore(...args) {
        counters = createCounterStore(...args);
        return counters;
      },
    },
  };
  const moduleStub = { exports: {} };
  vm.runInNewContext(fs.readFileSync(entry, "utf8"), {
    require: (name) => mocks[name] || realRequire(name),
    module: moduleStub,
    process: processStub,
  }, { filename: entry });
  moduleStub.exports.start();
  return {
    counters,
    processStub,
    get closes() { return closes; },
    signal: async (signal) => {
      signals.get(signal)();
      await shutdownComplete;
    },
  };
}

test("SIGTERM drains pending visits before closing the database, once", async () => {
  const events = [];
  const fixture = startFixture({
    getNum: async () => ({ num: 0 }),
    setNumMulti: async (rows) => events.push(["write", rows]),
    close: async () => events.push(["close"]),
  });
  const visit = fixture.counters.increment("last");
  await fixture.signal("SIGTERM");
  await visit;
  await fixture.signal("SIGINT");
  assert.equal(fixture.closes, 1);
  assert.deepEqual(events, [["write", [{ name: "last", num: 1 }]], ["close"]]);
});

test("shutdown reports a failed flush and still closes the database", async () => {
  let closed = false;
  const fixture = startFixture({
    getNum: async () => ({ num: 0 }),
    setNumMulti: async () => { throw new Error("offline"); },
    close: async () => { closed = true; },
  });
  await fixture.counters.increment("last");
  await fixture.signal("SIGINT");
  assert.equal(fixture.processStub.exitCode, 1);
  assert.equal(closed, true);
});
