"use strict";

const { test } = require("node:test");
const assert = require("node:assert/strict");
process.env.LOG_LEVEL = "none";
const { createApp } = require("../index");

async function service(t, overrides = {}) {
  const rows = new Map();
  const db = {
    getNum: async (name) => ({ name, num: rows.get(name) || 0 }),
    setNumMulti: async (counters) => {
      for (const { name, num } of counters) rows.set(name, num);
    },
    ...overrides,
  };
  const { app, counters } = createApp({ db });
  const server = app.listen(0, "127.0.0.1");
  await new Promise((resolve, reject) => {
    server.once("listening", resolve);
    server.once("error", reject);
  });
  t.after(async () => {
    await new Promise((resolve) => server.close(resolve));
    await counters.close();
  });
  return { rows, get: (path) => fetch(`http://127.0.0.1:${server.address().port}${path}`) };
}

test("formerly crashing queries return SVG and leave the service alive", async (t) => {
  const { get } = await service(t);
  for (const query of ["theme=constructor", "theme=__proto__", "theme=toString", "count=invalid", "prefix=1e3", "prefix=%2B1"]) {
    const response = await get(`/@demo?${query}`);
    assert.equal(response.status, 200, query);
    assert.match(response.headers.get("content-type"), /image\/svg\+xml/);
    assert.match(await response.text(), /<svg /);
  }
  assert.equal(await (await get("/heart-beat")).text(), "alive");
});

test("invalid input is rejected before it can add a visit", async (t) => {
  const { get, rows } = await service(t);
  for (const query of ["prefix=invalid", "padding=999", "scale=0", "theme[x]=bad"]) {
    const response = await get(`/@visits?${query}`);
    assert.equal(response.status, 400);
    assert.equal((await response.json()).code, 400);
  }
  assert.equal(rows.size, 0);
  assert.equal((await get(`/record/@${"x".repeat(33)}`)).status, 400);
});

test("HTTP record reads are read-only and concurrent image visits are retained", async (t) => {
  const { get, rows } = await service(t);
  assert.deepEqual(await (await get("/record/@visits")).json(), { name: "visits", num: 0 });
  const images = await Promise.all(Array.from({ length: 20 }, () => get("/@visits")));
  for (const image of images) {
    assert.equal(image.status, 200);
    await image.text();
  }
  for (let i = 0; i < 2; i++) {
    const record = await get("/record/@visits");
    assert.equal(record.headers.get("cache-control"), "no-store");
    assert.deepEqual(await record.json(), { name: "visits", num: 20 });
  }
  assert.equal(rows.get("visits"), 20);
  assert.equal((await get("/@constructor")).status, 200);
});

test("async database failures return a generic error and the process keeps serving", async (t) => {
  const { get } = await service(t, { getNum: async () => { throw new Error("secret database details"); } });
  for (const path of ["/@visits", "/record/@visits"]) {
    const response = await get(path);
    assert.equal(response.status, 500);
    assert.match(response.headers.get("content-type"), /application\/json/);
    const body = await response.text();
    assert.doesNotMatch(body, /secret database details/);
  }
  assert.equal((await get("/heart-beat")).status, 200);
});
