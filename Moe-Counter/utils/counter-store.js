"use strict";

// Serialize reads, increments and flushes so a pending write cannot erase new visits.
function createCounterStore(db, { intervalMs = 0, onError = console.error } = {}) {
  const pending = new Map();
  let queue = Promise.resolve();
  const enqueue = (operation) => {
    const result = queue.then(operation);
    queue = result.catch(() => {});
    return result;
  };

  async function flushPending() {
    if (!pending.size) return;
    await db.setNumMulti(Array.from(pending, ([name, num]) => ({ name, num })));
    pending.clear();
  }

  const timer = intervalMs > 0
    ? setInterval(() => enqueue(flushPending).catch(onError), intervalMs)
    : null;
  timer?.unref();

  return {
    increment: (name) => enqueue(async () => {
      const current = pending.has(name) ? pending.get(name) : (await db.getNum(name))?.num || 0;
      const num = current + 1;
      pending.set(name, num);
      if (intervalMs <= 0) await flushPending();
      return { name, num };
    }),
    read: (name) => enqueue(async () => ({
      name,
      num: pending.has(name) ? pending.get(name) : (await db.getNum(name))?.num || 0,
    })),
    flush: () => enqueue(flushPending),
    close: () => {
      clearInterval(timer);
      return enqueue(flushPending);
    },
  };
}

module.exports = { createCounterStore };
