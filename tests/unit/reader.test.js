// Usage: node --test tests/reader_js_test.js
'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const rd = require(path.join(__dirname, '..', '..', 'reader.js'));

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
const EMPTY = () => ({ v: 1, last: null, files: {} });

test('module import touches no window/document and exposes the API', () => {
  assert.equal(typeof globalThis.window, 'undefined');
  assert.equal(typeof globalThis.document, 'undefined');
  for (const fn of ['storageKey', 'bookKind', 'sortBooks', 'clampPage', 'fractionOf', 'bookStatus', 'percent',
    'loadState', 'saveProgress', 'shouldSave', 'resumeTarget', 'lastRead', 'neighbor', 'sortBooksBy', 'filterBooks', 'formatSize', 'comicPageUrl', 'comicCountUrl']) {
    assert.equal(typeof rd[fn], 'function', fn);
  }
});

test('storageKey', () => {
  assert.equal(rd.storageKey('57652162edcf73a3'), 'asc-rd:57652162edcf73a3');
});

test('bookKind: pdf/epub, case-insensitive, else null', () => {
  assert.equal(rd.bookKind('a.pdf'), 'pdf');
  assert.equal(rd.bookKind('a.epub'), 'epub');
  assert.equal(rd.bookKind('A.PDF'), 'pdf');
  assert.equal(rd.bookKind('B.EpUb'), 'epub');
  assert.equal(rd.bookKind('Vol.1 ch.2.final.pdf'), 'pdf');
  assert.equal(rd.bookKind('a.pdf.txt'), null);
  assert.equal(rd.bookKind('a.mobi'), null);
  assert.equal(rd.bookKind('pdf'), null);
  assert.equal(rd.bookKind('filepdf'), null);
  assert.equal(rd.bookKind(''), null);
  assert.equal(rd.bookKind('.pdf.jpg'), null);
});

test('sortBooks: natural, case-insensitive (T2 before T10), copy not mutation', () => {
  const books = [{ name: 'T10.pdf' }, { name: 't2.pdf' }, { name: 'T1.epub' }, { name: 'B.pdf' }];
  const copy = JSON.parse(JSON.stringify(books));
  const out = rd.sortBooks(books);
  assert.deepEqual(out.map((b) => b.name), ['B.pdf', 'T1.epub', 't2.pdf', 'T10.pdf']);
  assert.deepEqual(books, copy);
  assert.notEqual(out, books);
  assert.deepEqual(rd.sortBooks([]), []);
});

test('sortBooks: keeps extra fields and ties are deterministic', () => {
  const out = rd.sortBooks([{ name: 'b.pdf', url: 'u2', kind: 'pdf' }, { name: 'a.epub', url: 'u1', kind: 'epub' }]);
  assert.deepEqual(out[0], { name: 'a.epub', url: 'u1', kind: 'epub' });
  const a = rd.sortBooks([{ name: 'x1.pdf' }, { name: 'X1.pdf' }]).map((b) => b.name);
  const b = rd.sortBooks([{ name: 'x1.pdf' }, { name: 'X1.pdf' }]).map((b2) => b2.name);
  assert.deepEqual(a, b);
});

test('clampPage', () => {
  assert.equal(rd.clampPage(5, 10), 5);
  assert.equal(rd.clampPage(1, 10), 1);
  assert.equal(rd.clampPage(10, 10), 10);
  assert.equal(rd.clampPage(11, 10), 10);
  assert.equal(rd.clampPage(0, 10), 1);
  assert.equal(rd.clampPage(-3, 10), 1);
  assert.equal(rd.clampPage(NaN, 10), 1);
  assert.equal(rd.clampPage(undefined, 10), 1);
  assert.equal(rd.clampPage('abc', 10), 1);
  assert.equal(rd.clampPage(5, 0), 1);
  assert.equal(rd.clampPage(5, -2), 1);
  assert.equal(rd.clampPage(5, NaN), 1);
  assert.equal(rd.clampPage(3.7, 10) % 1, 0, 'integer result');
  assert.equal(rd.clampPage(Infinity, 10), 10);
});

test('fractionOf', () => {
  assert.equal(rd.fractionOf(5, 10), 0.5);
  assert.equal(rd.fractionOf(10, 10), 1);
  assert.equal(rd.fractionOf(0, 10), 0);
  assert.equal(rd.fractionOf(15, 10), 1);
  assert.equal(rd.fractionOf(-1, 10), 0);
  assert.equal(rd.fractionOf(5, 0), 0);
  assert.equal(rd.fractionOf(5, -1), 0);
  assert.equal(rd.fractionOf(1, 3), 1 / 3);
});

test('bookStatus: new / reading / done (threshold 0.98 inclusive)', () => {
  assert.equal(rd.bookStatus(undefined), 'new');
  assert.equal(rd.bookStatus(null), 'new');
  assert.equal(rd.bookStatus({ fraction: 0.1 }), 'reading');
  assert.equal(rd.bookStatus({ fraction: 0.5 }), 'reading');
  assert.equal(rd.bookStatus({ fraction: 0.979 }), 'reading');
  assert.equal(rd.bookStatus({ fraction: 0.98 }), 'done');
  assert.equal(rd.bookStatus({ fraction: 0.99 }), 'done');
  assert.equal(rd.bookStatus({ fraction: 1 }), 'done');
});

test('percent: rounded integer 0..100', () => {
  assert.equal(rd.percent(0), 0);
  assert.equal(rd.percent(1), 100);
  assert.equal(rd.percent(0.5), 50);
  assert.equal(rd.percent(0.494), 49);
  assert.equal(rd.percent(0.996), 100);
  assert.equal(rd.percent(0.004), 0);
  assert.equal(rd.percent(1.5), 100);
  assert.equal(rd.percent(-0.5), 0);
  assert.equal(rd.percent(NaN), 0);
  assert.equal(rd.percent(undefined), 0);
});

test('loadState: empty cases never throw', () => {
  assert.deepEqual(rd.loadState(null, 'tok'), EMPTY());
  assert.deepEqual(rd.loadState(undefined, 'tok'), EMPTY());
  assert.deepEqual(rd.loadState(fakeStore(), 'tok'), EMPTY());
  assert.deepEqual(rd.loadState({ getItem: throwing('SecurityError'), setItem() {} }, 'tok'), EMPTY());
});

test('loadState: corrupted JSON / wrong shapes / unknown version => empty', () => {
  for (const raw of ['{not json', '', 'null', '42', '"str"', '[]', 'true',
    JSON.stringify({ v: 2, last: 'a.pdf', files: { 'a.pdf': { fraction: 0.5 } } }),
    JSON.stringify({ last: 'a.pdf', files: {} }),
    JSON.stringify({ v: 1, last: null, files: 'oops' })]) {
    const s = fakeStore({ 'asc-rd:tok': raw });
    assert.deepEqual(rd.loadState(s, 'tok'), EMPTY(), 'raw=' + raw);
  }
});

test('loadState: valid v1 state is returned; other tokens do not leak', () => {
  const st = { v: 1, last: 'a.pdf', files: { 'a.pdf': { kind: 'pdf', page: 3, pages: 10, fraction: 0.3, ts: 5 } } };
  const s = fakeStore({ 'asc-rd:tok': JSON.stringify(st), 'asc-rd:other': JSON.stringify({ v: 1, last: 'z.pdf', files: {} }) });
  assert.deepEqual(rd.loadState(s, 'tok'), st);
  assert.deepEqual(rd.loadState(s, 'nope'), EMPTY());
});

test('saveProgress: persists under asc-rd:<token> and returns the state', () => {
  const s = fakeStore();
  const st = rd.saveProgress(s, 'tok', 'a.pdf', { kind: 'pdf', page: 3, pages: 10, fraction: 0.3 }, 1700000000000);
  assert.equal(st.v, 1);
  assert.equal(st.last, 'a.pdf');
  assert.equal(st.files['a.pdf'].page, 3);
  assert.equal(st.files['a.pdf'].pages, 10);
  assert.equal(st.files['a.pdf'].kind, 'pdf');
  assert.equal(st.files['a.pdf'].fraction, 0.3);
  assert.equal(st.files['a.pdf'].ts, 1700000000000);
  assert.deepEqual(JSON.parse(s.m.get('asc-rd:tok')), st);
  assert.deepEqual(rd.loadState(s, 'tok'), st);
});

test('saveProgress: merges patches, keeps other files, updates last and ts', () => {
  const s = fakeStore();
  rd.saveProgress(s, 'tok', 'a.pdf', { kind: 'pdf', page: 3, pages: 10, fraction: 0.3 }, 1000);
  rd.saveProgress(s, 'tok', 'b.epub', { kind: 'epub', cfi: 'epubcfi(/6/4)', fraction: 0.2 }, 2000);
  const st = rd.saveProgress(s, 'tok', 'a.pdf', { page: 4, fraction: 0.4 }, 3000);
  assert.equal(st.last, 'a.pdf');
  assert.equal(st.files['a.pdf'].page, 4);
  assert.equal(st.files['a.pdf'].pages, 10, 'untouched field kept');
  assert.equal(st.files['a.pdf'].kind, 'pdf');
  assert.equal(st.files['a.pdf'].ts, 3000);
  assert.equal(st.files['b.epub'].cfi, 'epubcfi(/6/4)');
  assert.equal(st.files['b.epub'].ts, 2000);
  assert.deepEqual(rd.loadState(s, 'tok'), st);
});

test('saveProgress: storage null / quota / throwing getItem => no throw, state still returned', () => {
  const a = rd.saveProgress(null, 'tok', 'a.pdf', { page: 2, pages: 4, fraction: 0.5 }, 10);
  assert.equal(a.last, 'a.pdf');
  assert.equal(a.files['a.pdf'].page, 2);
  const q = { getItem: () => null, setItem: throwing('QuotaExceededError') };
  const b = rd.saveProgress(q, 'tok', 'a.pdf', { page: 2, fraction: 0.5 }, 10);
  assert.equal(b.files['a.pdf'].page, 2);
  const t = { getItem: throwing('SecurityError'), setItem: throwing('SecurityError') };
  const c = rd.saveProgress(t, 'tok', 'a.pdf', { page: 2, fraction: 0.5 }, 10);
  assert.equal(c.last, 'a.pdf');
});

test('saveProgress: corrupted stored state is replaced, not propagated', () => {
  const s = fakeStore({ 'asc-rd:tok': '{broken' });
  const st = rd.saveProgress(s, 'tok', 'a.pdf', { page: 1, fraction: 0.1 }, 5);
  assert.deepEqual(Object.keys(st.files), ['a.pdf']);
  assert.equal(JSON.parse(s.m.get('asc-rd:tok')).v, 1);
});

test('saveProgress: unknown-version state is discarded', () => {
  const s = fakeStore({ 'asc-rd:tok': JSON.stringify({ v: 9, last: 'old.pdf', files: { 'old.pdf': { fraction: 0.5 } } }) });
  const st = rd.saveProgress(s, 'tok', 'a.pdf', { page: 1, fraction: 0.1 }, 5);
  assert.equal(st.files['old.pdf'], undefined);
  assert.equal(st.v, 1);
});

test('saveProgress: file names with odd characters round-trip', () => {
  const s = fakeStore();
  const n = 'Qu"ote </script> & é.pdf';
  rd.saveProgress(s, 'tok', n, { page: 1, fraction: 0.1 }, 1);
  assert.equal(rd.loadState(s, 'tok').last, n);
});

test('shouldSave: gap of 1000 ms by default', () => {
  assert.equal(rd.shouldSave(0, 5000), true);
  assert.equal(rd.shouldSave(null, 5000), true);
  assert.equal(rd.shouldSave(undefined, 5000), true);
  assert.equal(rd.shouldSave(5000, 5500), false);
  assert.equal(rd.shouldSave(5000, 5999), false);
  assert.equal(rd.shouldSave(5000, 6000), true);
  assert.equal(rd.shouldSave(5000, 9000), true);
  assert.equal(rd.shouldSave(5000, 5000), false);
});

test('shouldSave: custom gap', () => {
  assert.equal(rd.shouldSave(1000, 1400, 500), false);
  assert.equal(rd.shouldSave(1000, 1500, 500), true);
  assert.equal(rd.shouldSave(1000, 1100, 0), true);
});

test('resumeTarget: only for status reading', () => {
  const state = { v: 1, last: 'a.pdf', files: {
    'a.pdf': { kind: 'pdf', page: 4, pages: 10, fraction: 0.4 },
    'b.epub': { kind: 'epub', cfi: 'epubcfi(/6/8)', fraction: 0.25 },
    'c.pdf': { kind: 'pdf', page: 10, pages: 10, fraction: 1 },
    'd.pdf': { kind: 'pdf', page: 49, pages: 50, fraction: 0.98 },
  } };
  const a = rd.resumeTarget(state, 'a.pdf');
  assert.equal(a.page, 4);
  assert.equal(a.fraction, 0.4);
  assert.equal(a.percent, 40);
  const b = rd.resumeTarget(state, 'b.epub');
  assert.equal(b.cfi, 'epubcfi(/6/8)');
  assert.equal(b.fraction, 0.25);
  assert.equal(b.percent, 25);
  assert.equal(rd.resumeTarget(state, 'c.pdf'), null, 'done => no resume');
  assert.equal(rd.resumeTarget(state, 'd.pdf'), null, '0.98 => done => no resume');
  assert.equal(rd.resumeTarget(state, 'zzz.pdf'), null, 'new => no resume');
  assert.equal(rd.resumeTarget(EMPTY(), 'a.pdf'), null);
});

test('lastRead: state.last only if still in books', () => {
  const books = [{ name: 'a.pdf' }, { name: 'b.epub' }];
  assert.equal(rd.lastRead({ v: 1, last: 'b.epub', files: {} }, books), 'b.epub');
  assert.equal(rd.lastRead({ v: 1, last: 'gone.pdf', files: {} }, books), null);
  assert.equal(rd.lastRead(EMPTY(), books), null);
  assert.equal(rd.lastRead({ v: 1, last: 'a.pdf', files: {} }, []), null);
});

test('neighbor: sorted order, null at both ends', () => {
  const books = [{ name: 'T10.pdf' }, { name: 'T2.pdf' }, { name: 'T1.epub' }];
  assert.equal(rd.neighbor(books, 'T1.epub', 1), 'T2.pdf');
  assert.equal(rd.neighbor(books, 'T2.pdf', 1), 'T10.pdf', 'T2 before T10');
  assert.equal(rd.neighbor(books, 'T10.pdf', -1), 'T2.pdf');
  assert.equal(rd.neighbor(books, 'T1.epub', -1), null);
  assert.equal(rd.neighbor(books, 'T10.pdf', 1), null);
  assert.equal(rd.neighbor(books, 'unknown.pdf', 1), null);
  assert.equal(rd.neighbor([{ name: 'only.pdf' }], 'only.pdf', 1), null);
  assert.equal(rd.neighbor([{ name: 'only.pdf' }], 'only.pdf', -1), null);
  assert.equal(rd.neighbor([], 'a.pdf', 1), null);
});

test('done at 0.98 through the save/load/status chain', () => {
  const s = fakeStore();
  const st = rd.saveProgress(s, 'tok', 'a.pdf', { kind: 'pdf', page: 49, pages: 50, fraction: rd.fractionOf(49, 50) }, 1);
  assert.equal(rd.bookStatus(st.files['a.pdf']), 'done');
  const st2 = rd.saveProgress(s, 'tok', 'a.pdf', { page: 48, fraction: rd.fractionOf(48, 50) }, 2);
  assert.equal(rd.bookStatus(st2.files['a.pdf']), 'reading');
});

const LIB = () => [
  { name: 'T10.pdf', size: 300, mtime: 3000 },
  { name: 'T2.pdf', size: 100, mtime: 2000 },
  { name: 'b.epub', size: 200, mtime: 1000 },
];
const names = (a) => a.map((b) => b.name);

test('sortBooksBy name: natural, asc and desc', () => {
  assert.deepEqual(names(rd.sortBooksBy(LIB(), 'name', true)), ['b.epub', 'T2.pdf', 'T10.pdf']);
  assert.deepEqual(names(rd.sortBooksBy(LIB(), 'name', false)), ['T10.pdf', 'T2.pdf', 'b.epub']);
});

test('sortBooksBy size: asc and desc', () => {
  assert.deepEqual(names(rd.sortBooksBy(LIB(), 'size', true)), ['T2.pdf', 'b.epub', 'T10.pdf']);
  assert.deepEqual(names(rd.sortBooksBy(LIB(), 'size', false)), ['T10.pdf', 'b.epub', 'T2.pdf']);
});

test('sortBooksBy date (mtime): asc and desc', () => {
  assert.deepEqual(names(rd.sortBooksBy(LIB(), 'date', true)), ['b.epub', 'T2.pdf', 'T10.pdf']);
  assert.deepEqual(names(rd.sortBooksBy(LIB(), 'date', false)), ['T10.pdf', 'T2.pdf', 'b.epub']);
});

test('sortBooksBy: input not mutated, returns a copy', () => {
  const l = LIB();
  const before = JSON.parse(JSON.stringify(l));
  const out = rd.sortBooksBy(l, 'size', false);
  assert.deepEqual(l, before);
  assert.notEqual(out, l);
  assert.deepEqual(rd.sortBooksBy([], 'size', true), []);
});

test('sortBooksBy: stable on equal keys (asc)', () => {
  const l = [{ name: 'z.pdf', size: 5, mtime: 1 }, { name: 'a.pdf', size: 5, mtime: 1 }, { name: 'm.pdf', size: 1, mtime: 1 }];
  assert.deepEqual(names(rd.sortBooksBy(l, 'size', true)), ['m.pdf', 'z.pdf', 'a.pdf']);
  assert.deepEqual(names(rd.sortBooksBy(l, 'date', true)), ['z.pdf', 'a.pdf', 'm.pdf']);
});

test('filterBooks: case/accent-insensitive, trimmed, empty => all, no match => []', () => {
  const l = [{ name: 'Été 1.pdf' }, { name: 'Zoë.epub' }, { name: 'Cours Math.pdf' }];
  assert.deepEqual(names(rd.filterBooks(l, 'ete')), ['Été 1.pdf']);
  assert.deepEqual(names(rd.filterBooks(l, 'ÉTÉ')), ['Été 1.pdf']);
  assert.deepEqual(names(rd.filterBooks(l, 'zoe')), ['Zoë.epub']);
  assert.deepEqual(names(rd.filterBooks(l, '  math  ')), ['Cours Math.pdf']);
  assert.deepEqual(names(rd.filterBooks(l, '.pdf')), ['Été 1.pdf', 'Cours Math.pdf']);
  assert.deepEqual(names(rd.filterBooks(l, '')), names(l));
  assert.deepEqual(names(rd.filterBooks(l, '   ')), names(l));
  assert.deepEqual(names(rd.filterBooks(l, null)), names(l));
  assert.deepEqual(names(rd.filterBooks(l, undefined)), names(l));
  assert.deepEqual(rd.filterBooks(l, 'zzzz'), []);
  assert.deepEqual(rd.filterBooks([], 'a'), []);
});

test('filterBooks: regex characters are literal; input not mutated', () => {
  const l = [{ name: 'a(1).pdf' }, { name: 'ab.pdf' }];
  assert.deepEqual(names(rd.filterBooks(l, '(1)')), ['a(1).pdf']);
  assert.deepEqual(rd.filterBooks(l, '.*'), []);
  assert.equal(l.length, 2);
});

test('formatSize: French style', () => {
  const f = (n) => rd.formatSize(n).replace(/\s/g, ' ');
  assert.equal(f(0), '0 o');
  assert.equal(f(1), '1 o');
  assert.equal(f(512), '512 o');
  assert.equal(f(1023), '1023 o');
  assert.equal(f(1536), '1,5 Ko');
  assert.match(f(1024), /^1(,0)? Ko$/);
  assert.match(f(1048576), /^1(,0+)? Mo$/);
  assert.match(f(5 * 1048576), /^5(,0+)? Mo$/);
  assert.match(f(1073741824), /^1(,0+)? Go$/);
  assert.match(f(1610612736), /^1,5\d* Go$/);
});

test('formatSize: bad input does not throw', () => {
  for (const v of [NaN, undefined, null, -5]) assert.doesNotThrow(() => rd.formatSize(v));
  assert.equal(typeof rd.formatSize(NaN), 'string');
});

test('needsScriptSandbox: true only on pure WebKit', () => {
  const U = {
    iphoneSafari: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
    iphoneChrome: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/124.0.6367.111 Mobile/15E148 Safari/604.1',
    iphoneFirefox: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/125.0 Mobile/15E148 Safari/605.1.15',
    ipodSafari: 'Mozilla/5.0 (iPod touch; CPU iPhone OS 15_8 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.6 Mobile/15E148 Safari/604.1',
    ipadSafari: 'Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
    macSafari: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
    chromeWin: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    chromeMac: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    chromeLinux: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    chromiumLinux: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Ubuntu Chromium/124.0.0.0 Chrome/124.0.0.0 Safari/537.36',
    chromeAndroid: 'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36',
    androidStock: 'Mozilla/5.0 (Linux; U; Android 4.4.2; en-us; SM-T530) AppleWebKit/534.30 (KHTML, like Gecko) Version/4.0 Safari/534.30',
    edgeWin: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.2478.67',
    firefoxWin: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) Gecko/20100101 Firefox/125.0',
    firefoxLinux: 'Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0',
    firefoxAndroid: 'Mozilla/5.0 (Android 14; Mobile; rv:125.0) Gecko/125.0 Firefox/125.0',
  };
  const cases = [
    ['iphoneSafari', 'iPhone', 5, true], ['iphoneChrome', 'iPhone', 5, true], ['iphoneFirefox', 'iPhone', 5, true],
    ['ipodSafari', 'iPhone', 5, true], ['ipadSafari', 'iPad', 5, true],
    ['macSafari', 'MacIntel', 0, true],
    ['chromeWin', 'Win32', 0, false], ['chromeMac', 'MacIntel', 0, false], ['chromeLinux', 'Linux x86_64', 0, false],
    ['chromiumLinux', 'Linux x86_64', 0, false], ['chromeAndroid', 'Linux armv81', 5, false],
    ['androidStock', 'Linux armv7l', 5, false],
    ['edgeWin', 'Win32', 0, false], ['firefoxWin', 'Win32', 0, false], ['firefoxLinux', 'Linux x86_64', 0, false],
    ['firefoxAndroid', 'Linux armv81', 5, false],
  ];
  assert.ok(cases.length >= 12);
  for (const [k, platform, touch, want] of cases) {
    assert.equal(rd.needsScriptSandbox(U[k], platform, touch), want, k);
  }
  // iPadOS 13+ in desktop mode: Mac UA, platform MacIntel, touch screen
  assert.equal(rd.needsScriptSandbox(U.macSafari, 'MacIntel', 5), true, 'ipados desktop mode');
  assert.equal(rd.needsScriptSandbox(U.macSafari, 'MacIntel', 1), true, 'mac safari, 1 touch point is still Safari');
  assert.equal(rd.needsScriptSandbox(U.chromeMac, 'MacIntel', 5), true, 'iPadOS desktop-mode Chrome UA is Mac-like: MacIntel+touch rule');
  assert.equal(rd.needsScriptSandbox(U.chromeMac, 'MacIntel', 0), false, 'mac chrome without touch');
  assert.equal(rd.needsScriptSandbox(U.chromeMac, 'MacIntel', 1), false, 'maxTouchPoints must be > 1');
  assert.equal(rd.needsScriptSandbox(U.chromeWin, 'MacIntel', 5), true, 'platform rule applies whatever the UA says');
});

test('needsScriptSandbox: missing or odd inputs never throw and stay fail-closed (false)', () => {
  assert.equal(rd.needsScriptSandbox('', '', 0), false);
  assert.equal(rd.needsScriptSandbox(undefined, undefined, undefined), false);
  assert.equal(rd.needsScriptSandbox(null, null, null), false);
  assert.equal(rd.needsScriptSandbox('Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101 Firefox/125.0', 'MacIntel', undefined), false);
});

test('bookKind: cbz/cbr are comics, case-insensitive; other comic formats and pdf/epub unchanged', () => {
  assert.equal(rd.bookKind('x.cbz'), 'comic');
  assert.equal(rd.bookKind('X.CBR'), 'comic');
  assert.equal(rd.bookKind('Tome 1.CbZ'), 'comic');
  assert.equal(rd.bookKind('a.cbz.txt'), null);
  assert.equal(rd.bookKind('a.cb7'), null);
  assert.equal(rd.bookKind('cbz'), null);
  assert.equal(rd.bookKind('a.pdf'), 'pdf');
  assert.equal(rd.bookKind('a.EPUB'), 'epub');
});

test('comicPageUrl / comicCountUrl: query separator and numeric page', () => {
  assert.equal(rd.comicPageUrl('/dl/tok?p=BD%2Fa.cbz&inline=1', 3), '/dl/tok?p=BD%2Fa.cbz&inline=1&page=3');
  assert.equal(rd.comicPageUrl('/dl/tok', 1), '/dl/tok?page=1');
  assert.equal(rd.comicPageUrl('/dl/tok?inline=1', '12'), '/dl/tok?inline=1&page=12');
  assert.equal(rd.comicCountUrl('/dl/tok?p=BD%2Fa.cbz&inline=1'), '/dl/tok?p=BD%2Fa.cbz&inline=1&pages=1');
  assert.equal(rd.comicCountUrl('/dl/tok'), '/dl/tok?pages=1');
});

test('comicPageUrl: never emits a URL without page=, whatever n is', () => {
  for (const n of [1, 10, 250]) assert.match(rd.comicPageUrl('/dl/t?inline=1', n), /[?&]page=\d+$/);
});
