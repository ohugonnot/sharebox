'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const PlayerSeek = require(path.join(__dirname, '..', '..', 'player.js'));

// Manual clock: a timer fires only when advance() reaches its due time.
function fakeClock() {
  let now = 0, nextId = 1;
  const timers = new Map();
  return {
    setTimer: (fn, ms) => { const id = nextId++; timers.set(id, { fn, at: now + ms }); return id; },
    clearTimer: (id) => { timers.delete(id); },
    advance(ms) {
      const end = now + ms;
      for (;;) {
        let best = null;
        for (const [id, t] of timers) if (t.at <= end && (best === null || t.at < timers.get(best).at)) best = id;
        if (best === null) break;
        const t = timers.get(best); timers.delete(best); now = t.at; t.fn();
      }
      now = end;
    },
    count: () => timers.size,
  };
}
function setup() {
  const clock = fakeClock();
  const commits = [];
  const co = PlayerSeek.createCoalescer({ commit: (t) => commits.push(t), setTimer: clock.setTimer, clearTimer: clock.clearTimer });
  return { clock, commits, co };
}

test('module import touches no window/document', () => {
  assert.equal(typeof window, 'undefined');
  assert.equal(typeof document, 'undefined');
  assert.equal(typeof PlayerSeek.createCoalescer, 'function');
});

test('6 requests in a burst give 1 commit on the last target', () => {
  const { clock, commits, co } = setup();
  for (let i = 1; i <= 6; i++) { co.request(i * 10, 600); clock.advance(400); }
  assert.deepEqual(commits, []);
  clock.advance(200);
  assert.deepEqual(commits, [60]);
  assert.equal(co.pending(), null);
  assert.equal(clock.count(), 0);
});

test('requests further apart than the delay each commit', () => {
  const { clock, commits, co } = setup();
  co.request(10, 600); clock.advance(700);
  co.request(20, 600); clock.advance(700);
  assert.deepEqual(commits, [10, 20]);
});

test('pending() returns the waiting target until the commit', () => {
  const { clock, co } = setup();
  assert.equal(co.pending(), null);
  co.request(0, 300);
  assert.equal(co.pending(), 0);
  clock.advance(300);
  assert.equal(co.pending(), null);
});

test('hold blocks the commit, release arms it', () => {
  const { clock, commits, co } = setup();
  co.hold();
  co.request(42, 600);
  clock.advance(5000);
  assert.deepEqual(commits, []);
  assert.equal(co.pending(), 42);
  co.release();
  clock.advance(599);
  assert.deepEqual(commits, []);
  clock.advance(1);
  assert.deepEqual(commits, [42]);
});

test('hold cancels a timer that was already armed', () => {
  const { clock, commits, co } = setup();
  co.request(7, 600);
  clock.advance(300);
  co.hold();
  clock.advance(1000);
  assert.deepEqual(commits, []);
  co.release();
  clock.advance(600);
  assert.deepEqual(commits, [7]);
});

test('release without a waiting target arms nothing', () => {
  const { clock, commits, co } = setup();
  co.hold();
  co.release();
  assert.equal(clock.count(), 0);
  clock.advance(5000);
  assert.deepEqual(commits, []);
});

test('cancel drops timer and target, no commit', () => {
  const { clock, commits, co } = setup();
  co.request(10, 600);
  co.cancel();
  assert.equal(co.pending(), null);
  assert.equal(clock.count(), 0);
  clock.advance(5000);
  assert.deepEqual(commits, []);
});

test('cancel does not lift a hold', () => {
  const { clock, commits, co } = setup();
  co.hold();
  co.request(10, 600);
  co.cancel();
  co.request(20, 600);
  clock.advance(5000);
  assert.deepEqual(commits, []);
  co.release();
  clock.advance(600);
  assert.deepEqual(commits, [20]);
});

test('cancel on an idle coalescer is a no-op', () => {
  const { commits, co } = setup();
  co.cancel();
  assert.equal(co.pending(), null);
  assert.deepEqual(commits, []);
});

test('non-numeric targets are ignored', () => {
  const { clock, commits, co } = setup();
  co.request(NaN, 600);
  co.request(undefined, 600);
  co.request('12', 600);
  assert.equal(co.pending(), null);
  assert.equal(clock.count(), 0);
  clock.advance(5000);
  assert.deepEqual(commits, []);
});

test('a target of 0 is a valid target', () => {
  const { clock, commits, co } = setup();
  co.request(0, 100);
  clock.advance(100);
  assert.deepEqual(commits, [0]);
});

test('commit may re-request without losing the new target', () => {
  const clock = fakeClock();
  const commits = [];
  let co;
  co = PlayerSeek.createCoalescer({
    commit: (t) => { commits.push(t); if (t === 1) co.request(2, 100); },
    setTimer: clock.setTimer, clearTimer: clock.clearTimer,
  });
  co.request(1, 100);
  clock.advance(100);
  clock.advance(100);
  assert.deepEqual(commits, [1, 2]);
});

test('clampTarget bounds to [0, duration - margin]', () => {
  assert.equal(PlayerSeek.clampTarget(-5, 100, 2), 0);
  assert.equal(PlayerSeek.clampTarget(50, 100, 2), 50);
  assert.equal(PlayerSeek.clampTarget(100, 100, 2), 98);
  assert.equal(PlayerSeek.clampTarget(500, 100, 2), 98);
});

test('clampTarget on a clip shorter than the margin or unknown duration gives 0', () => {
  assert.equal(PlayerSeek.clampTarget(1, 1, 2), 0);
  assert.equal(PlayerSeek.clampTarget(30, 0, 2), 0);
});

test('relativeTarget starts from the playhead when nothing is pending', () => {
  assert.equal(PlayerSeek.relativeTarget(null, 30, 10, 600, 2), 40);
  assert.equal(PlayerSeek.relativeTarget(null, 30, -10, 600, 2), 20);
});

test('relativeTarget never goes backwards on a forward jump near the end', () => {
  assert.equal(PlayerSeek.relativeTarget(null, 599.5, 10, 600, 2), 599.5);
  assert.equal(PlayerSeek.relativeTarget(null, 599.5, 10, 600, 0), 600);
  assert.equal(PlayerSeek.relativeTarget(null, 1.0, 10, 1.5, 2), 1.0);
  assert.equal(PlayerSeek.relativeTarget(null, 599.5, -10, 600, 2), 589.5);
});

test('relativeTarget starts from the pending target, even 0', () => {
  assert.equal(PlayerSeek.relativeTarget(100, 30, 10, 600, 2), 110);
  assert.equal(PlayerSeek.relativeTarget(0, 30, 10, 600, 2), 10);
});

test('6 chained +10 give +60, bounded by duration - margin', () => {
  let pending = null;
  for (let i = 0; i < 6; i++) pending = PlayerSeek.relativeTarget(pending, 100, 10, 1000, 2);
  assert.equal(pending, 160);
  pending = null;
  for (let i = 0; i < 6; i++) pending = PlayerSeek.relativeTarget(pending, 100, 10, 130, 2);
  assert.equal(pending, 128);
});

test('6 chained -10 are bounded at 0', () => {
  let pending = null;
  for (let i = 0; i < 6; i++) pending = PlayerSeek.relativeTarget(pending, 25, -10, 1000, 2);
  assert.equal(pending, 0);
});

test('coalescer + relativeTarget: 6 key presses 400 ms apart commit once at +60', () => {
  const { clock, commits, co } = setup();
  for (let i = 0; i < 6; i++) {
    co.request(PlayerSeek.relativeTarget(co.pending(), 100, 10, 1000, 2), 800);
    clock.advance(400);
  }
  clock.advance(400);
  assert.deepEqual(commits, [160]);
});
