// Usage: node --test tests/audiobook_js_test.js
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const ab = require(path.join(__dirname, '..', '..', 'audiobook.js'));

function fakeStore(initial) {
  const m = new Map(Object.entries(initial || {}));
  return {
    m,
    getItem: (k) => (m.has(k) ? m.get(k) : null),
    setItem: (k, v) => { m.set(k, String(v)); },
    removeItem: (k) => { m.delete(k); },
  };
}
const throwing = (msg) => () => { const e = new Error(msg); e.name = msg; throw e; };

test('naturalCompare/sortNatural are gone (server sorts)', () => {
  assert.equal(ab.naturalCompare, undefined);
  assert.equal(ab.sortNatural, undefined);
});

test('module import touches no window/document', () => {
  assert.equal(typeof globalThis.window, 'undefined');
  assert.equal(typeof globalThis.document, 'undefined');
  for (const fn of ['progressKey', 'loadProgress', 'saveProgress', 'markListened', 'clampRate', 'stepRate', 'displayNames', 'formatTime', 'init']) {
    assert.equal(typeof ab[fn], 'function', fn);
  }
});

test('progressKey', () => {
  assert.equal(ab.progressKey('57652162edcf73a3'), 'asc-ab:57652162edcf73a3');
});

test('save/load round trip', () => {
  const s = fakeStore();
  const state = { track: '01 a.mp3', time: 12.5, rate: 1.5, listened: ['00.mp3'], updated: 1700000000000 };
  ab.saveProgress(s, 'tok', state);
  assert.ok(s.m.has('asc-ab:tok'));
  assert.deepEqual(ab.loadProgress(s, 'tok'), state);
});

test('loadProgress: missing => null', () => {
  assert.equal(ab.loadProgress(fakeStore(), 'tok'), null);
});

test('loadProgress: tokens are isolated', () => {
  const s = fakeStore();
  ab.saveProgress(s, 'a', { track: 'x', time: 1, rate: 1, listened: [], updated: 1 });
  assert.equal(ab.loadProgress(s, 'b'), null);
});

test('loadProgress: corrupted JSON => null, no throw', () => {
  for (const bad of ['{not json', '', 'undefined', '{"track":', '\u0000']) {
    const s = fakeStore({ 'asc-ab:tok': bad });
    assert.doesNotThrow(() => ab.loadProgress(s, 'tok'));
    assert.equal(ab.loadProgress(s, 'tok'), null, JSON.stringify(bad));
  }
});

test('loadProgress: valid JSON but not an object => null', () => {
  for (const bad of ['null', '42', '"str"', 'true']) {
    const s = fakeStore({ 'asc-ab:tok': bad });
    assert.equal(ab.loadProgress(s, 'tok'), null, bad);
  }
});

test('loadProgress: store.getItem throws => null', () => {
  const s = { getItem: throwing('SecurityError'), setItem() {} };
  assert.doesNotThrow(() => ab.loadProgress(s, 'tok'));
  assert.equal(ab.loadProgress(s, 'tok'), null);
});

test('loadProgress: null/undefined store => null, no throw', () => {
  assert.doesNotThrow(() => ab.loadProgress(null, 'tok'));
  assert.equal(ab.loadProgress(undefined, 'tok'), null);
});

test('saveProgress: quota exceeded swallowed', () => {
  const s = { getItem: () => null, setItem: throwing('QuotaExceededError') };
  assert.doesNotThrow(() => ab.saveProgress(s, 'tok', { track: 'a', time: 1, rate: 1, listened: [], updated: 1 }));
});

test('saveProgress: private mode (SecurityError) swallowed', () => {
  const s = { getItem: throwing('SecurityError'), setItem: throwing('SecurityError') };
  assert.doesNotThrow(() => ab.saveProgress(s, 'tok', { track: 'a' }));
});

test('saveProgress: null store swallowed', () => {
  assert.doesNotThrow(() => ab.saveProgress(null, 'tok', { track: 'a' }));
});

test('saveProgress does not mutate the state', () => {
  const state = Object.freeze({ track: 'a', time: 1, rate: 1, listened: Object.freeze(['x']), updated: 1 });
  assert.doesNotThrow(() => ab.saveProgress(fakeStore(), 'tok', state));
});

test('markListened: adds track, no mutation', () => {
  const state = Object.freeze({ track: 'b', time: 3, rate: 1, listened: Object.freeze(['a']), updated: 1 });
  const out = ab.markListened(state, 'b');
  assert.deepEqual(out.listened, ['a', 'b']);
  assert.deepEqual(state.listened, ['a']);
  assert.notEqual(out, state);
  assert.equal(out.track, 'b');
  assert.equal(out.rate, 1);
});

test('markListened: no duplicate', () => {
  const s0 = { track: 'a', time: 0, rate: 1, listened: ['a'], updated: 1 };
  const out = ab.markListened(s0, 'a');
  assert.deepEqual(out.listened, ['a']);
  assert.deepEqual(ab.markListened(out, 'a').listened, ['a']);
});

test('markListened: tolerates state without listened array', () => {
  const out = ab.markListened({ track: 'a', time: 0, rate: 1, updated: 1 }, 'a');
  assert.deepEqual(out.listened, ['a']);
  assert.deepEqual(ab.markListened({}, 'z').listened, ['z']);
});

test('markListened: tolerates null state', () => {
  assert.doesNotThrow(() => ab.markListened(null, 'a'));
  assert.deepEqual(ab.markListened(null, 'a').listened, ['a']);
});

test('clampRate: in range kept, rounded to 1 decimal', () => {
  assert.equal(ab.clampRate(1), 1);
  assert.equal(ab.clampRate(1.5), 1.5);
  assert.equal(ab.clampRate(0.5), 0.5);
  assert.equal(ab.clampRate(3), 3);
  assert.equal(ab.clampRate(1.2000000000000002), 1.2);
  assert.equal(ab.clampRate(1.26), 1.3);
});

test('clampRate: invalid or out-of-range values => exactly 1', () => {
  for (const v of [NaN, undefined, null, 'abc', '', Infinity, -Infinity, -1, 0, 3.1, 100, 0.4, {}, []]) {
    assert.equal(ab.clampRate(v), 1, String(v));
  }
});

test('clampRate: numeric strings accepted', () => {
  assert.equal(ab.clampRate('1.5'), 1.5);
  assert.equal(ab.clampRate('2'), 2);
});

test('stepRate: basic steps', () => {
  assert.equal(ab.stepRate(1, 1), 1.1);
  assert.equal(ab.stepRate(1, -1), 0.9);
  assert.equal(ab.stepRate(1.5, 1), 1.6);
});

test('stepRate: bounds hold', () => {
  assert.equal(ab.stepRate(3.0, 1), 3.0);
  assert.equal(ab.stepRate(0.5, -1), 0.5);
  assert.equal(ab.stepRate(2.9, 1), 3.0);
  assert.equal(ab.stepRate(0.6, -1), 0.5);
});

test('stepRate: no float drift over repeated steps', () => {
  let r = 1;
  for (let i = 0; i < 7; i++) r = ab.stepRate(r, 1);
  assert.equal(r, 1.7);
  for (let i = 0; i < 12; i++) r = ab.stepRate(r, -1);
  assert.equal(r, 0.5);
  assert.equal(ab.stepRate(1.1, 1), 1.2);
  assert.equal(ab.stepRate(1.2, 1), 1.3);
  assert.equal(ab.stepRate(0.7, 1), 0.8);
  let x = 0.5;
  for (let i = 0; i < 40; i++) { x = ab.stepRate(x, 1); assert.equal(x, Math.round(x * 10) / 10); }
  assert.equal(x, 3);
});

test('stepRate: invalid rate falls back to 1 before stepping', () => {
  for (const v of [NaN, undefined, 'abc', Infinity, -1]) {
    assert.equal(ab.stepRate(v, 1), 1.1, String(v));
    assert.equal(ab.stepRate(v, -1), 0.9, String(v));
  }
});

test('stepRate: numeric string rate', () => {
  assert.equal(ab.stepRate('1.3', 1), 1.4);
});

// displayNames: file name without extension, "_" -> space, repeated spaces collapsed, trimmed, never empty, no prefix/number stripping.
const nfc = (a) => a.map((x) => x.normalize('NFC'));

test('displayNames: same length and order as input', () => {
  const inp = ['b.mp3', 'a.mp3', 'c.mp3'];
  assert.deepEqual(ab.displayNames(inp), ['b', 'a', 'c']);
  assert.deepEqual(ab.displayNames([]), []);
});

test('displayNames: does not mutate input', () => {
  const inp = Object.freeze(['01_a.mp3']);
  assert.doesNotThrow(() => ab.displayNames(inp));
});

test('displayNames: real samples (A1, A2, numbering kept)', () => {
  assert.deepEqual(ab.displayNames(['Siasaigne_ep7--0001.mp3', 'Siasaigne_ep7--0054.mp3']),
    ['Siasaigne ep7--0001', 'Siasaigne ep7--0054']);
  assert.deepEqual(ab.displayNames(['001.mp3', '002.mp3', '010.mp3']), ['001', '002', '010']);
  assert.deepEqual(ab.displayNames(['01 - Chapitre 1.mp3', '02 - Chapitre 2.mp3']), ['01 - Chapitre 1', '02 - Chapitre 2']);
  assert.deepEqual(ab.displayNames(['001.02 - Chapter 1.2 & Chapter 2.1 - Luxure en Russie impériale - Une nouvelle érotique.mp3']),
    ['001.02 - Chapter 1.2 & Chapter 2.1 - Luxure en Russie impériale - Une nouvelle érotique']);
  assert.deepEqual(ab.displayNames(['002.01 - Chapter 1 & Chapter 2.1 - Du sexe, docteur ! \u2013 Une nouvelle érotique.mp3']),
    ['002.01 - Chapter 1 & Chapter 2.1 - Du sexe, docteur ! \u2013 Une nouvelle érotique']);
});

test('displayNames: underscores become spaces, spaces collapsed, trimmed', () => {
  assert.deepEqual(ab.displayNames(['01_Prologue.mp3', '02_Chapitre_1.mp3']), ['01 Prologue', '02 Chapitre 1']);
  assert.deepEqual(ab.displayNames(['a   b__c.mp3']), ['a b c']);
  assert.deepEqual(ab.displayNames(['  padded  .mp3']), ['padded']);
  assert.deepEqual(ab.displayNames(['_x_.mp3']), ['x']);
});

test('displayNames: uppercase and mixed-case extensions', () => {
  assert.deepEqual(ab.displayNames(['Chapitre 1.MP3', 'chapitre 2.Mp3', 'x.FLAC', 'y.M4B']), ['Chapitre 1', 'chapitre 2', 'x', 'y']);
});

test('displayNames: case of the label is preserved', () => {
  assert.deepEqual(ab.displayNames(['CHAPITRE 01 - LE RETOUR.mp3']), ['CHAPITRE 01 - LE RETOUR']);
});

test('displayNames: NFD accents and typographic punctuation survive', () => {
  const nfd = '01 \u2013 E\u0301clat d\u2019e\u0301te\u0301.mp3';
  assert.deepEqual(nfc(ab.displayNames([nfd])), nfc(['01 \u2013 \u00c9clat d\u2019\u00e9t\u00e9']));
  assert.deepEqual(ab.displayNames(["01 - Le C\u0153ur d'\u00c8ve !.mp3"]), ["01 - Le C\u0153ur d'\u00c8ve !"]);
});

test('displayNames: only the last extension is removed (multiple dots)', () => {
  assert.deepEqual(ab.displayNames(['Titre.du.livre.Auteur.FR.2022.mp3']), ['Titre.du.livre.Auteur.FR.2022']);
  assert.deepEqual(ab.displayNames(['01.Prologue.mp3', '02.Chapitre.1.mp3']), ['01.Prologue', '02.Chapitre.1']);
});

test('displayNames: name without extension is kept', () => {
  assert.deepEqual(ab.displayNames(['Prologue']), ['Prologue']);
  assert.deepEqual(ab.displayNames(['Piste_7']), ['Piste 7']);
});

test('displayNames: never empty (fallback to the full name)', () => {
  const out = ab.displayNames(['.mp3', '___.mp3', '   .mp3']);
  assert.equal(out.length, 3);
  for (const o of out) assert.notEqual(o.trim(), '', JSON.stringify(o));
  assert.equal(out[0], '.mp3');
});

test('displayNames: duplicates are kept as separate entries', () => {
  assert.deepEqual(ab.displayNames(['01 - Chapitre 1.mp3', '01 - Chapitre 1 (1).mp3']), ['01 - Chapitre 1', '01 - Chapitre 1 (1)']);
  assert.deepEqual(ab.displayNames(['a.mp3', 'a.MP3']), ['a', 'a']);
});

test('displayNames: no prefix removal across a shared prefix', () => {
  assert.deepEqual(ab.displayNames(['Le Hobbit - 01.mp3', 'Le Hobbit - 02.mp3']), ['Le Hobbit - 01', 'Le Hobbit - 02']);
});

test('formatTime: exact values', () => {
  assert.equal(ab.formatTime(3725), '1:02:05');
  assert.equal(ab.formatTime(65), '01:05');
  assert.equal(ab.formatTime(0), '00:00');
  assert.equal(ab.formatTime(3600), '1:00:00');
  assert.equal(ab.formatTime(59.9), '00:59');
  assert.equal(ab.formatTime(600), '10:00');
  assert.equal(ab.formatTime(36000), '10:00:00');
});

test('formatTime: invalid => 00:00', () => {
  for (const v of [-1, NaN, Infinity, -Infinity, undefined, null, 'abc']) assert.equal(ab.formatTime(v), '00:00', String(v));
});
