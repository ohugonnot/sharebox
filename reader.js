(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.AscReader = api;
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var DONE_AT = 0.98;
    var NEW_DAYS = 14;
    var SAVE_GAP_MS = 1000;
    var ZOOM_MIN = 0.5;
    var ZOOM_MAX = 3;
    var ZOOM_STEP = 0.25;
    var MAX_CANVAS_PIXELS = 16e6;
    var EPUB_OPEN_TIMEOUT_MS = 10000;
    var FS_HIDE_MS = 3000;
    var SWIPE_MIN_PX = 50;
    var REFIT_MS = 150;
    var HANDLE_SIZE = 44;
    var HANDLE_FREE_PX = 48;
    var ZONE_SIDE = 0.35;
    var CLICK_SLOP_PX = 10;
    var COMIC_ZOOMS = [1, 1.5, 2.5, 4];
    var COMIC_TAP_ZOOM = 2.5;
    var DOUBLE_TAP_MS = 250;
    var KEY_SCROLL = 0.12;
    var WHEEL_STEP_PX = 100;
    var SPREAD_KEY = 'asc-rd-pref:spread';
    var SHARP_KEY = 'asc-rd-pref:sharp';
    // 50 = the tested default: crisper lettering on soft scans without turning screentones into grain.
    var SHARP_DEFAULT = 50;
    var EPUB_SIZE_KEY = 'asc-rd-pref:epub-size';
    var EPUB_THEME_KEY = 'asc-rd-pref:epub-theme';
    var EPUB_SIZE_DEFAULT = 100;
    var EPUB_THEMES = {
        light: { bg: '#ffffff', fg: '#1a1a1a', link: '#0b57d0' },
        sepia: { bg: '#f4ecd8', fg: '#5b4636', link: '#8a4b14' },
        dark: { bg: '#121212', fg: '#e0e0e0', link: '#8ab4f8' }
    };
    var CURSOR_IDLE_MS = 2500;
    // A pinch released this close to the fit snaps back to it.
    var PINCH_SNAP = 1.05;
    // Browsers may still send a click right after a multi-touch gesture.
    var MULTI_TOUCH_QUIET_MS = 500;
    var EPUB_UNAVAILABLE = 'EPUB indisponible : le lecteur ne peut pas afficher ce livre ici.';
    var COMIC_UNREADABLE = 'BD illisible : l’archive est corrompue ou n’a pas pu être lue.';
    var COMIC_EMPTY = 'BD illisible : aucune image dans cette archive.';

    // ─── Pure helpers ────────────────────────────────────────────────────────

    function storageKey(token) {
        return 'asc-rd:' + token;
    }

    function bookKind(name) {
        var m = /\.([A-Za-z0-9]+)$/.exec(String(name));
        var ext = m ? m[1].toLowerCase() : '';
        if (ext === 'cbz' || ext === 'cbr') return 'comic';
        return ext === 'pdf' || ext === 'epub' ? ext : null;
    }

    function withParam(url, param) {
        var u = String(url);
        return u + (u.indexOf('?') === -1 ? '?' : '&') + param;
    }

    function comicPageUrl(url, n) {
        return withParam(url, 'page=' + n);
    }

    function comicCountUrl(url) {
        return withParam(url, 'pages=1');
    }

    function nameOf(b) {
        return typeof b === 'string' ? b : (b && b.name);
    }

    // Digit runs compare as numbers, the rest case-insensitively (like PHP strnatcasecmp).
    function natCompare(a, b) {
        var ta = String(a).toLowerCase().match(/\d+|\D+/g) || [];
        var tb = String(b).toLowerCase().match(/\d+|\D+/g) || [];
        var n = Math.min(ta.length, tb.length);
        for (var i = 0; i < n; i++) {
            var x = ta[i];
            var y = tb[i];
            if (x === y) continue;
            if (/^\d/.test(x) && /^\d/.test(y)) {
                var d = Number(x) - Number(y);
                if (d !== 0) return d < 0 ? -1 : 1;
                continue;
            }
            return x < y ? -1 : 1;
        }
        return ta.length === tb.length ? 0 : (ta.length < tb.length ? -1 : 1);
    }

    function sortBooks(books) {
        return books.slice().sort(function (a, b) {
            return natCompare(nameOf(a), nameOf(b));
        });
    }

    function sortBooksBy(books, key, asc) {
        var dir = asc === false ? -1 : 1;
        return books.slice().sort(function (a, b) {
            var d;
            if (key === 'size') d = (Number(a.size) || 0) - (Number(b.size) || 0);
            else if (key === 'date') d = (Number(a.mtime) || 0) - (Number(b.mtime) || 0);
            else d = natCompare(nameOf(a), nameOf(b));
            return d === 0 ? 0 : (d < 0 ? -dir : dir);
        });
    }

    function fold(str) {
        return String(str).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    }

    function filterBooks(books, query) {
        var q = fold(String(query === undefined || query === null ? '' : query).trim());
        if (!q) return books.slice();
        return books.filter(function (b) { return fold(nameOf(b)).indexOf(q) !== -1; });
    }

    // French decimal comma; same units as fmt_size() in browser_render.php, rounding may differ.
    function formatSize(bytes) {
        var n = Number(bytes);
        if (!isFinite(n) || n < 0) n = 0;
        function fix(v, d) { return v.toFixed(d).replace('.', ','); }
        if (n >= 1073741824) return fix(n / 1073741824, 2) + ' Go';
        if (n >= 1048576) return fix(n / 1048576, 1) + ' Mo';
        if (n >= 1024) return fix(n / 1024, 1) + ' Ko';
        return Math.floor(n) + ' o';
    }

    function formatDate(ts) {
        var n = Number(ts);
        if (!isFinite(n) || n <= 0) return '';
        var d = new Date(n * 1000);
        function p2(x) { return x < 10 ? '0' + x : String(x); }
        return p2(d.getDate()) + '/' + p2(d.getMonth() + 1) + '/' + d.getFullYear();
    }

    function clampPage(n, total) {
        var t = Math.floor(Number(total));
        if (!isFinite(t) || t < 1) return 1;
        var p = Math.floor(Number(n));
        if (isNaN(p) || p < 1) return 1;
        return p > t ? t : p;
    }

    function fractionOf(page, total) {
        var t = Number(total);
        if (!isFinite(t) || t < 1) return 0;
        var f = Number(page) / t;
        if (isNaN(f)) return 0;
        return Math.min(1, Math.max(0, f));
    }

    function bookStatus(entry) {
        if (!entry || typeof entry !== 'object') return 'new';
        return Number(entry.fraction) >= DONE_AT ? 'done' : 'reading';
    }

    function percent(fraction) {
        var f = Number(fraction);
        if (isNaN(f)) return 0;
        return Math.min(100, Math.max(0, Math.round(f * 100)));
    }

    function emptyState() {
        return { v: 1, last: null, files: {} };
    }

    function loadState(storage, token) {
        try {
            var raw = storage.getItem(storageKey(token));
            if (!raw) return emptyState();
            var s = JSON.parse(raw);
            if (!s || typeof s !== 'object' || s.v !== 1) return emptyState();
            if (!s.files || typeof s.files !== 'object' || Array.isArray(s.files)) return emptyState();
            return { v: 1, last: typeof s.last === 'string' ? s.last : null, files: s.files };
        } catch (e) {
            return emptyState();
        }
    }

    function saveProgress(storage, token, file, patch, now) {
        var state = loadState(storage, token);
        var entry = {};
        var old = state.files[file];
        var k;
        if (old && typeof old === 'object') {
            for (k in old) if (Object.prototype.hasOwnProperty.call(old, k)) entry[k] = old[k];
        }
        for (k in patch) {
            if (Object.prototype.hasOwnProperty.call(patch, k) && patch[k] !== undefined) entry[k] = patch[k];
        }
        entry.ts = now;
        state.files[file] = entry;
        state.last = file;
        try {
            storage.setItem(storageKey(token), JSON.stringify(state));
        } catch (e) { /* quota or private mode: reading still works */ }
        return state;
    }

    function clearProgress(storage, token, file) {
        var state = loadState(storage, token);
        delete state.files[file];
        if (state.last === file) state.last = null;
        try {
            storage.setItem(storageKey(token), JSON.stringify(state));
        } catch (e) { /* quota or private mode */ }
        return state;
    }

    function shouldSave(lastSaveTs, now, minGapMs) {
        var gap = minGapMs === undefined ? SAVE_GAP_MS : minGapMs;
        var last = Number(lastSaveTs);
        if (!isFinite(last) || !last) return true;
        return now - last >= gap;
    }

    function resumeTarget(state, file) {
        var entry = state && state.files && state.files[file];
        if (bookStatus(entry) !== 'reading') return null;
        var fraction = Number(entry.fraction);
        if (!isFinite(fraction)) fraction = 0;
        var target = { fraction: fraction, percent: percent(fraction) };
        if (typeof entry.page === 'number' && isFinite(entry.page)) target.page = entry.page;
        if (typeof entry.cfi === 'string' && entry.cfi) target.cfi = entry.cfi;
        return target;
    }

    function lastRead(state, books) {
        if (!state || typeof state.last !== 'string') return null;
        for (var i = 0; i < books.length; i++) {
            if (nameOf(books[i]) === state.last) return state.last;
        }
        return null;
    }

    // Comic double page: the cover and wide (panoramic) pages stand alone; any other page pairs with the
    // next one unless that one is wide or missing. wide = {page: true}; without it: (1), (2,3), (4,5)...
    function spreadFor(page, pages, wide) {
        var t = clampPage(pages, pages);
        var p = clampPage(page, t);
        var w = wide || {};
        var a = 1;
        for (;;) {
            var b = a > 1 && !w[a] && a + 1 <= t && !w[a + 1] ? a + 1 : null;
            if (p === a || p === b) return [a, b];
            a = (b || a) + 1;
        }
    }

    // Lower page of the spread one step away; the same page at either end of the book.
    function stepSpread(page, pages, dir, wide) {
        var s = spreadFor(page, pages, wide);
        if (dir < 0) return s[0] === 1 ? 1 : spreadFor(s[0] - 1, pages, wide)[0];
        var n = (s[1] || s[0]) + 1;
        return n > clampPage(pages, pages) ? s[0] : n;
    }

    // Pages side by side at one shared height, the row fitting in availW x availH, then scaled by zoom.
    function fitSpread(dims, availW, availH, zoom) {
        var ratio = 0;
        for (var i = 0; i < dims.length; i++) {
            if (!(dims[i][0] > 0 && dims[i][1] > 0)) return null;
            ratio += dims[i][0] / dims[i][1];
        }
        if (!ratio) return null;
        var h = Math.min(availH, availW / ratio) * (zoom > 0 ? zoom : 1);
        return dims.map(function (d) {
            return { width: Math.floor(d[0] / d[1] * h), height: Math.floor(h) };
        });
    }

    function comicZoomStep(zoom, dir) {
        var z = Number(zoom) || 1;
        var i;
        if (dir > 0) {
            for (i = 0; i < COMIC_ZOOMS.length; i++) if (COMIC_ZOOMS[i] > z + 1e-9) return COMIC_ZOOMS[i];
            return COMIC_ZOOMS[COMIC_ZOOMS.length - 1];
        }
        for (i = COMIC_ZOOMS.length - 1; i >= 0; i--) if (COMIC_ZOOMS[i] < z - 1e-9) return COMIC_ZOOMS[i];
        return COMIC_ZOOMS[0];
    }

    // Reader shortcut for a key. Zoomed, arrows and Space pan first and only turn the page from an edge
    // the view was already at (one press of resistance). edges = {top, bottom, left, right}: true when at that edge.
    /** Stored sharpness 0..100; the old on/off values '1' and '0' map to the default and to off. */
    function sharpLevel(raw) {
        if (raw === null || raw === undefined || raw === '' || raw === '1') return SHARP_DEFAULT;
        var n = Math.round(Number(raw));
        return isFinite(n) ? Math.min(100, Math.max(0, n)) : SHARP_DEFAULT;
    }

    /** 3x3 unsharp kernel for feConvolveMatrix; its weights sum to 1, so flat areas keep their tone. */
    function sharpKernel(level) {
        var a = Math.round(sharpLevel(level) * 0.012 * 1000) / 1000;
        return ['0', -a, '0', -a, 1 + 4 * a, -a, '0', -a, '0'].join(' ');
    }

    /** Stored EPUB text size in percent: 80..160 by steps of 10, 100 when missing or invalid. */
    function epubSize(raw) {
        if (raw === null || raw === undefined || raw === '') return EPUB_SIZE_DEFAULT;
        var n = Number(raw);
        if (!isFinite(n)) return EPUB_SIZE_DEFAULT;
        return Math.min(160, Math.max(80, Math.round(n / 10) * 10));
    }

    // Passed whole to foliate's setStyles, which replaces the previous styles.
    function epubCss(theme, size, siteColors) {
        var c = (Object.prototype.hasOwnProperty.call(EPUB_THEMES, theme) && EPUB_THEMES[theme]) || siteColors || {};
        return 'html,body{background:' + (c.bg || '#fff') + ' !important;color:' + (c.fg || '#000') + ' !important}'
            + 'a:link,a:visited{color:' + (c.link || '#06c') + ' !important}'
            + 'html{font-size:' + epubSize(size) + '% !important}';
    }

    function pinchZoom(startZoom, startDist, dist) {
        var lo = COMIC_ZOOMS[0];
        var hi = COMIC_ZOOMS[COMIC_ZOOMS.length - 1];
        var z = Number(startZoom) || 1;
        if (startDist > 0 && dist > 0) z = z * dist / startDist;
        return Math.min(hi, Math.max(lo, z));
    }

    function keyAction(key, shift, zoomed, edges) {
        var at = edges || {};
        if (key === '+' || key === '=') return 'zoom-in';
        if (key === '-') return 'zoom-out';
        if (key === '0') return 'zoom-reset';
        if (key === ' ' || key === 'Spacebar') {
            if (shift) return zoomed && !at.top ? 'scroll-up' : 'prev';
            return zoomed && !at.bottom ? 'scroll-down' : 'next';
        }
        if (shift) return null;
        switch (key) {
            case 'PageUp': return 'prev';
            case 'PageDown': return 'next';
            case 'Home': return 'first';
            case 'End': return 'last';
            case 'ArrowUp': return zoomed ? 'scroll-up' : null;
            case 'ArrowDown': return zoomed ? 'scroll-down' : null;
            case 'ArrowLeft': return zoomed && !at.left ? 'scroll-left' : 'prev';
            case 'ArrowRight': return zoomed && !at.right ? 'scroll-right' : 'next';
        }
        return null;
    }

    function neighbor(books, name, dir) {
        var sorted = sortBooks(books);
        for (var i = 0; i < sorted.length; i++) {
            if (nameOf(sorted[i]) === name) {
                var next = sorted[i + (dir < 0 ? -1 : 1)];
                return next === undefined ? null : nameOf(next);
            }
        }
        return null;
    }

    // True on WebKit, which needs allow-scripts in the chapter iframe for key and touch events
    // (https://bugs.webkit.org/show_bug.cgi?id=218086). Not feature-detectable: every iOS browser is
    // WebKit, iPadOS 13+ reports MacIntel with touch points, desktop Safari has no Chrome/Edge/Android token.
    function needsScriptSandbox(userAgent, platform, maxTouchPoints) {
        var ua = String(userAgent || '');
        if (/iPhone|iPad|iPod/.test(ua)) return true;
        if (platform === 'MacIntel' && maxTouchPoints > 1) return true;
        return /AppleWebKit/.test(ua) && !/Chrome|Chromium|CriOS|Edg|Android/.test(ua);
    }

    function withoutScripts(value) {
        return String(value).split(/\s+/).filter(function (t) {
            return t && t.toLowerCase() !== 'allow-scripts';
        }).join(' ');
    }

    // foliate sets sandbox="allow-same-origin allow-scripts" on detached iframes inside closed shadow roots,
    // so the only hook is the prototype. Counted so nested holders share one patch.
    var sandboxGuard = { depth: 0, proto: null, hadOwn: false, prev: null, hook: null };

    function lockIframeSandbox(win) {
        var g = sandboxGuard;
        if (g.depth++ > 0) return;
        var proto = win.HTMLIFrameElement.prototype;
        var prev = proto.setAttribute;
        var hook = function setAttribute(name, value) {
            // Inert once released, in case another script wrapped it and kept it in its chain.
            if (g.hook === hook && String(name).toLowerCase() === 'sandbox') value = withoutScripts(value);
            return prev.call(this, name, value);
        };
        g.proto = proto;
        g.hadOwn = Object.prototype.hasOwnProperty.call(proto, 'setAttribute');
        g.prev = prev;
        g.hook = hook;
        proto.setAttribute = hook;
    }

    function unlockIframeSandbox() {
        var g = sandboxGuard;
        if (g.depth === 0 || --g.depth > 0) return;
        if (g.proto.setAttribute === g.hook) {
            if (g.hadOwn) g.proto.setAttribute = g.prev;
            else delete g.proto.setAttribute;
        }
        g.proto = g.prev = g.hook = null;
    }

    function frameRunsScripts(frameDoc) {
        var f = null;
        try { f = frameDoc.defaultView.frameElement; } catch (e) { /* unreachable frame */ }
        if (!f || !f.hasAttribute('sandbox')) return true;
        return /(^|\s)allow-scripts(\s|$)/i.test(f.getAttribute('sandbox'));
    }

    // ─── DOM ─────────────────────────────────────────────────────────────────

    function el(doc, tag, cls, attrs) {
        var node = doc.createElement(tag);
        if (cls) node.className = cls;
        if (attrs) {
            Object.keys(attrs).forEach(function (k) { node.setAttribute(k, attrs[k]); });
        }
        return node;
    }

    function tid(node, id) {
        node.setAttribute('data-testid', id);
        return node;
    }

    function button(doc, label, text, id) {
        var b = el(doc, 'button', 'rd-btn', { type: 'button', 'aria-label': label });
        b.textContent = text;
        return tid(b, id);
    }

    function statusText(status, pct, recent) {
        if (status === 'done') return 'Terminé';
        if (status === 'reading') return pct + ' %';
        return recent ? 'Nouveau' : '';
    }

    // Names of the books added in the last NEW_DAYS; none when every book is that recent (a fresh share).
    function recentBooks(books, nowSec) {
        var limit = nowSec - NEW_DAYS * 86400;
        var out = {};
        var n = 0;
        books.forEach(function (b) {
            if (Number(b.mtime) > limit) { out[b.name] = true; n++; }
        });
        return n && n === books.length ? {} : out;
    }

    // currentScript is only set while the script runs, not when mount() fires later.
    var scriptSrc = typeof document !== 'undefined' && document.currentScript ? document.currentScript.src : '';

    function libBase() {
        return scriptSrc ? scriptSrc.replace(/[^\/]*$/, '') + 'reader/' : '/html/js/reader/';
    }

    function pickStorage(win) {
        try {
            var s = win.localStorage;
            s.getItem('asc-rd:probe');
            return s;
        } catch (e) {
            return null;
        }
    }

    function init(opts) {
        var rootEl = opts.root;
        var doc = rootEl.ownerDocument;
        var win = doc.defaultView;
        var token = opts.token || '';
        var books = sortBooks(opts.books || []);
        var storage = opts.storage === undefined ? pickStorage(win) : opts.storage;
        var base = opts.libBase || libBase();
        // Set when the host page has its own listing: Back leaves the reader instead of showing the library.
        var exitUrl = opts.exitUrl || null;
        var byName = {};
        books.forEach(function (b) { byName[b.name] = b; });

        // Without storage an in-memory stub keeps the same code path alive.
        var mem = {};
        var store = storage || {
            getItem: function (k) { return Object.prototype.hasOwnProperty.call(mem, k) ? mem[k] : null; },
            setItem: function (k, v) { mem[k] = v; }
        };
        function readState() { return storage ? loadState(storage, token) : emptyState(); }
        // One page by default: the reader turns the spread on when it helps, and the choice is kept.
        var spreadPref = false;
        try { spreadPref = store.getItem(SPREAD_KEY) === '1'; } catch (e) { /* default: one page */ }
        var sharpPref = SHARP_DEFAULT;
        try { sharpPref = sharpLevel(store.getItem(SHARP_KEY)); } catch (e) { /* default level */ }
        var epubSizePref = EPUB_SIZE_DEFAULT;
        try { epubSizePref = epubSize(store.getItem(EPUB_SIZE_KEY)); } catch (e) { /* default size */ }
        var epubThemePref = 'site';
        try {
            var storedTheme = store.getItem(EPUB_THEME_KEY);
            if (Object.prototype.hasOwnProperty.call(EPUB_THEMES, storedTheme)) epubThemePref = storedTheme;
        } catch (e) { /* site colours */ }

        rootEl.textContent = '';
        rootEl.classList.add('rd-reader');

        // ── Library
        var errorBox = tid(el(doc, 'div', 'rd-error', { role: 'alert' }), 'rd-error');
        errorBox.hidden = true;
        var filterInput = tid(el(doc, 'input', 'browser-search rd-filter', {
            type: 'search', placeholder: 'Filtrer\u2026', autocomplete: 'off', spellcheck: 'false', 'aria-label': 'Filtrer les livres'
        }), 'rd-filter');
        var countEl = tid(el(doc, 'span', 'rd-count', { 'aria-live': 'polite' }), 'rd-count');
        var sortBar = el(doc, 'div', 'browser-sortbar rd-sortbar');
        var sortKey = 'name';
        var sortAsc = true;
        var sortBtns = {};
        [['name', 'Nom', 'rd-sort-name'], ['size', 'Taille', 'rd-sort-size'], ['date', 'Modifié', 'rd-sort-date']].forEach(function (c) {
            var b = el(doc, 'button', 'bsort', { type: 'button' });
            b.appendChild(doc.createTextNode(c[1] + ' '));
            var arrow = el(doc, 'span', 'sort-arrow');
            b.appendChild(arrow);
            tid(b, c[2]);
            b.addEventListener('click', function () {
                if (sortKey === c[0]) sortAsc = !sortAsc;
                else { sortKey = c[0]; sortAsc = c[0] === 'name'; }
                renderLibrary();
            });
            sortBtns[c[0]] = { btn: b, arrow: arrow };
            sortBar.appendChild(b);
        });
        var tools = el(doc, 'div', 'rd-tools');
        tools.appendChild(filterInput);
        tools.appendChild(countEl);
        var library = tid(el(doc, 'section', 'rd-library', { 'aria-label': 'Bibliothèque' }), 'rd-library');
        var continueBtn = tid(el(doc, 'button', 'rd-btn rd-continue', { type: 'button' }), 'rd-continue');
        var list = el(doc, 'ul', 'rd-book-list');
        library.appendChild(continueBtn);
        library.appendChild(tools);
        library.appendChild(sortBar);
        library.appendChild(list);

        // ── Viewer
        var viewer = tid(el(doc, 'section', 'rd-viewer', { 'aria-label': 'Lecture' }), 'rd-viewer');
        viewer.hidden = true;
        var bar = el(doc, 'div', 'rd-bar');
        var backBtn = button(doc, exitUrl ? 'Retour au dossier' : 'Retour à la bibliothèque', '', 'rd-back');
        backBtn.classList.add('rd-back-btn');
        backBtn.appendChild(doc.createTextNode('← '));
        backBtn.appendChild(el(doc, 'span', 'rd-btn-label')).textContent = exitUrl ? 'Dossier' : 'Bibliothèque';
        var titleEl = tid(el(doc, 'h2', 'rd-title'), 'rd-title');
        var prevBtn = button(doc, 'Page précédente', '‹', 'rd-prev');
        var nextBtn = button(doc, 'Page suivante', '›', 'rd-next');
        var pageBox = el(doc, 'span', 'rd-pages');
        var pageInput = tid(el(doc, 'input', 'rd-page-input', {
            type: 'number', min: '1', inputmode: 'numeric', 'aria-label': 'Numéro de page'
        }), 'rd-page-input');
        var pageCount = tid(el(doc, 'span', 'rd-page-count'), 'rd-page-count');
        pageBox.appendChild(pageInput);
        pageBox.appendChild(pageCount);
        var zoomOut = button(doc, 'Zoom arrière', '−', 'rd-zoom-out');
        var zoomIn = button(doc, 'Zoom avant', '+', 'rd-zoom-in');
        var zoomBox = el(doc, 'span', 'rd-zoom');
        zoomBox.appendChild(zoomOut);
        zoomBox.appendChild(zoomIn);
        var spreadBtn = tid(el(doc, 'button', 'rd-btn rd-spread-btn', {
            type: 'button', 'aria-label': 'Double page', 'aria-pressed': 'true'
        }), 'rd-spread-toggle');
        spreadBtn.hidden = true;
        var sharpBtn = tid(el(doc, 'button', 'rd-btn rd-sharp-btn', {
            type: 'button', 'aria-label': 'Réglage de la netteté', 'aria-expanded': 'false', 'aria-controls': 'rd-sharp-panel'
        }), 'rd-sharp-toggle');
        sharpBtn.textContent = 'Net';
        sharpBtn.hidden = true;
        var sharpPanel = tid(el(doc, 'div', 'rd-sharp-panel', { id: 'rd-sharp-panel' }), 'rd-sharp-panel');
        sharpPanel.hidden = true;
        var sharpLabel = sharpPanel.appendChild(el(doc, 'label', 'rd-sharp-label', { 'for': 'rd-sharp-range' }));
        sharpLabel.textContent = 'Netteté';
        var sharpRange = tid(el(doc, 'input', 'rd-sharp-range', {
            id: 'rd-sharp-range', type: 'range', min: '0', max: '100', step: '5'
        }), 'rd-sharp-range');
        sharpPanel.appendChild(sharpRange);
        var sharpValue = sharpPanel.appendChild(el(doc, 'output', 'rd-sharp-value', { 'for': 'rd-sharp-range' }));
        var epubBtn = tid(el(doc, 'button', 'rd-btn rd-epub-btn', {
            type: 'button', 'aria-label': 'Réglages du texte', 'aria-expanded': 'false', 'aria-controls': 'rd-epub-panel'
        }), 'rd-epub-toggle');
        epubBtn.textContent = 'Aa';
        epubBtn.hidden = true;
        var epubPanel = tid(el(doc, 'div', 'rd-epub-panel', { id: 'rd-epub-panel' }), 'rd-epub-panel');
        epubPanel.hidden = true;
        var epubSizeRow = epubPanel.appendChild(el(doc, 'div', 'rd-epub-row'));
        var epubSizeLabel = epubSizeRow.appendChild(el(doc, 'label', 'rd-epub-label', { 'for': 'rd-epub-size' }));
        epubSizeLabel.textContent = 'Taille';
        var epubRange = tid(el(doc, 'input', 'rd-epub-size', {
            id: 'rd-epub-size', type: 'range', min: '80', max: '160', step: '10'
        }), 'rd-epub-size');
        epubSizeRow.appendChild(epubRange);
        var epubSizeValue = epubSizeRow.appendChild(el(doc, 'output', 'rd-epub-value', { 'for': 'rd-epub-size' }));
        var epubThemeRow = epubPanel.appendChild(el(doc, 'div', 'rd-epub-row rd-epub-themes', { role: 'group', 'aria-label': 'Thème' }));
        var epubThemeBtns = [['site', 'Site'], ['light', 'Clair'], ['sepia', 'Sépia'], ['dark', 'Sombre']].map(function (t) {
            var b = tid(el(doc, 'button', 'rd-btn rd-epub-theme', { type: 'button', 'data-theme': t[0], 'aria-pressed': 'false' }), 'rd-epub-theme-' + t[0]);
            b.textContent = t[1];
            return epubThemeRow.appendChild(b);
        });
        var fsBtn = tid(el(doc, 'button', 'rd-btn rd-fs-btn', {
            type: 'button', 'aria-label': 'Plein écran', 'aria-pressed': 'false'
        }), 'rd-fullscreen');
        var SVG_NS = 'http://www.w3.org/2000/svg';
        var fsIcon = doc.createElementNS(SVG_NS, 'svg');
        ['viewBox', '0 0 24 24', 'width', '20', 'height', '20', 'fill', 'none', 'stroke', 'currentColor',
            'stroke-width', '2', 'stroke-linecap', 'round', 'stroke-linejoin', 'round', 'aria-hidden', 'true'
        ].forEach(function (v, i, a) { if (i % 2 === 0) fsIcon.setAttribute(v, a[i + 1]); });
        var fsPath = doc.createElementNS(SVG_NS, 'path');
        fsPath.setAttribute('d', 'M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5');
        fsIcon.appendChild(fsPath);
        fsBtn.appendChild(fsIcon);
        var progressEl = tid(el(doc, 'span', 'rd-progress', { 'aria-live': 'polite' }), 'rd-progress');
        [backBtn, titleEl, prevBtn, pageBox, nextBtn, zoomBox, spreadBtn, sharpBtn, epubBtn, fsBtn, progressEl, sharpPanel, epubPanel].forEach(function (n) { bar.appendChild(n); });

        var banner = tid(el(doc, 'div', 'rd-banner', { role: 'status', 'aria-label': 'Reprise de lecture' }), 'rd-resume-banner');
        banner.hidden = true;
        var bannerText = el(doc, 'span', 'rd-banner-text');
        var resumeYes = button(doc, 'Reprendre la lecture', 'Reprendre', 'rd-resume-yes');
        var resumeNo = button(doc, 'Recommencer depuis le début', 'Recommencer', 'rd-resume-restart');
        banner.appendChild(bannerText);
        banner.appendChild(resumeYes);
        banner.appendChild(resumeNo);

        var stage = tid(el(doc, 'div', 'rd-stage'), 'rd-stage');
        stage.tabIndex = -1;
        var canvas = tid(el(doc, 'canvas', 'rd-canvas', { role: 'img', 'aria-label': 'Page du document' }), 'rd-canvas');
        var epubHost = tid(el(doc, 'div', 'rd-epub-host'), 'rd-epub-host');
        // The lower page is always the left one in a double page.
        var comicBox = el(doc, 'div', 'rd-comic');
        comicBox.hidden = true;
        var comicImg = tid(el(doc, 'img', 'rd-comic-img', { alt: 'Page de la BD', draggable: 'false' }), 'rd-comic-img');
        var comicImg2 = tid(el(doc, 'img', 'rd-comic-img', { alt: 'Page de la BD', draggable: 'false' }), 'rd-comic-img-2');
        comicImg2.hidden = true;
        comicBox.appendChild(comicImg);
        comicBox.appendChild(comicImg2);
        var loadingEl = tid(el(doc, 'div', 'rd-loading', { role: 'status', 'aria-live': 'polite' }), 'rd-loading');
        loadingEl.hidden = true;
        loadingEl.appendChild(el(doc, 'span', 'rd-spinner', { 'aria-hidden': 'true' }));
        var loadingText = loadingEl.appendChild(el(doc, 'span', 'rd-loading-text'));
        stage.appendChild(canvas);
        stage.appendChild(comicBox);
        stage.appendChild(epubHost);
        stage.appendChild(loadingEl);

        var handle = tid(el(doc, 'button', 'rd-btn rd-bar-handle', { type: 'button', 'aria-label': 'Afficher les contrôles' }), 'rd-bar-handle');
        var handleIcon = doc.createElementNS(SVG_NS, 'svg');
        ['viewBox', '0 0 24 24', 'width', '20', 'height', '20', 'fill', 'none', 'stroke', 'currentColor',
            'stroke-width', '2', 'stroke-linecap', 'round', 'aria-hidden', 'true'
        ].forEach(function (v, i, a) { if (i % 2 === 0) handleIcon.setAttribute(v, a[i + 1]); });
        var handlePath = doc.createElementNS(SVG_NS, 'path');
        handlePath.setAttribute('d', 'M4 7h16M4 12h16M4 17h16');
        handleIcon.appendChild(handlePath);
        handle.appendChild(handleIcon);

        viewer.appendChild(bar);
        viewer.appendChild(handle);
        viewer.appendChild(banner);
        viewer.appendChild(stage);

        filterInput.addEventListener('input', renderLibrary);
        rootEl.appendChild(errorBox);
        rootEl.appendChild(library);
        rootEl.appendChild(viewer);
        // Kept out of display:none subtrees: some browsers drop filters referenced from there.
        var sharpSvg = rootEl.appendChild(doc.createElementNS(SVG_NS, 'svg'));
        sharpSvg.setAttribute('width', '0');
        sharpSvg.setAttribute('height', '0');
        sharpSvg.setAttribute('aria-hidden', 'true');
        sharpSvg.style.position = 'absolute';
        var sharpFilter = sharpSvg.appendChild(doc.createElementNS(SVG_NS, 'filter'));
        sharpFilter.setAttribute('id', 'rd-sharp-filter');
        sharpFilter.setAttribute('color-interpolation-filters', 'sRGB');
        var sharpMatrix = sharpFilter.appendChild(doc.createElementNS(SVG_NS, 'feConvolveMatrix'));
        sharpMatrix.setAttribute('order', '3');
        sharpMatrix.setAttribute('kernelMatrix', sharpKernel(sharpPref));
        sharpMatrix.setAttribute('preserveAlpha', 'true');

        // ── Reading session (one book at a time)
        var cur = null;
        var seq = 0;
        var lastSaveTs = 0;
        var pending = null;
        var pendingTimer = null;

        function showError(msg) {
            errorBox.textContent = msg;
            errorBox.hidden = false;
        }

        function showLoading(text) {
            loadingText.textContent = text;
            loadingEl.hidden = false;
        }

        function hideLoading() { loadingEl.hidden = true; }

        function clearError() {
            errorBox.hidden = true;
            errorBox.textContent = '';
        }

        function renderLibrary() {
            var state = readState();
            list.textContent = '';
            Object.keys(sortBtns).forEach(function (k) {
                var on = k === sortKey;
                sortBtns[k].btn.classList.toggle('active', on);
                sortBtns[k].btn.setAttribute('aria-pressed', on ? 'true' : 'false');
                sortBtns[k].arrow.classList.toggle('active', on);
                sortBtns[k].arrow.textContent = on ? (sortAsc ? '\u2191' : '\u2193') : '\u2195';
            });
            var shown = sortBooksBy(filterBooks(books, filterInput.value), sortKey, sortAsc);
            var recent = recentBooks(books, Date.now() / 1000);
            countEl.textContent = shown.length + ' / ' + books.length;
            shown.forEach(function (b) {
                var entry = state.files[b.name];
                var status = bookStatus(entry);
                var pct = percent(entry && entry.fraction);
                var li = el(doc, 'li', 'rd-book-item');
                var open = tid(el(doc, 'button', 'rd-book', {
                    type: 'button', 'data-name': b.name, 'data-kind': b.kind, 'data-status': status
                }), 'rd-book');
                var kindEl = el(doc, 'span', 'rd-book-kind');
                kindEl.textContent = b.kind.toUpperCase();
                var nameEl = el(doc, 'span', 'rd-book-name');
                nameEl.textContent = b.name.replace(/\.[A-Za-z0-9]+$/, '');
                var sizeEl = tid(el(doc, 'span', 'rd-book-size'), 'rd-book-size');
                sizeEl.textContent = formatSize(b.size);
                var dateEl = el(doc, 'span', 'rd-book-date');
                dateEl.textContent = formatDate(b.mtime);
                var statusEl = el(doc, 'span', 'rd-book-status');
                statusEl.textContent = statusText(status, pct, recent[b.name]);
                open.appendChild(kindEl);
                open.appendChild(nameEl);
                open.appendChild(sizeEl);
                open.appendChild(dateEl);
                open.appendChild(statusEl);
                open.addEventListener('click', function () { openBook(b.name); });
                var dl = el(doc, 'a', 'rd-book-dl', { href: b.download || b.url, download: '', 'aria-label': 'Télécharger ' + b.name });
                dl.textContent = '↓';
                li.appendChild(open);
                li.appendChild(dl);
                list.appendChild(li);
            });
            var last = lastRead(state, books);
            if (last) {
                continueBtn.hidden = false;
                continueBtn.textContent = 'Reprendre ' + last.replace(/\.[A-Za-z0-9]+$/, '');
                continueBtn.onclick = function () { openBook(last); };
            } else {
                continueBtn.hidden = true;
                continueBtn.onclick = null;
            }
        }

        // ── Saving: first write goes through, later ones wait for the 1 s gap.
        function flush() {
            if (pendingTimer) { win.clearTimeout(pendingTimer); pendingTimer = null; }
            if (!pending) return;
            var p = pending;
            pending = null;
            lastSaveTs = Date.now();
            saveProgress(store, token, p.file, p.patch, lastSaveTs);
        }

        function persist(patch) {
            if (!cur || cur.hold) return;
            pending = { file: cur.name, patch: Object.assign({ kind: cur.kind }, pending && pending.file === cur.name ? pending.patch : {}, patch) };
            if (shouldSave(lastSaveTs, Date.now())) {
                flush();
            } else if (!pendingTimer) {
                pendingTimer = win.setTimeout(flush, SAVE_GAP_MS);
            }
        }

        function setProgressText(fraction) {
            progressEl.textContent = percent(fraction) + ' %';
        }

        // ── PDF
        function pdfPageLabel() {
            var comic = cur.kind === 'comic';
            var last = comic && cur.spread ? (spreadFor(cur.page, cur.pages, cur.wide)[1] || cur.page) : cur.page;
            pageInput.value = String(cur.page);
            pageInput.max = String(cur.pages);
            pageCount.textContent = (last !== cur.page ? cur.page + '-' + last + ' ' : '') + '/ ' + cur.pages;
            prevBtn.disabled = cur.page <= 1;
            nextBtn.disabled = last >= cur.pages;
            zoomOut.disabled = cur.zoom <= (comic ? COMIC_ZOOMS[0] : ZOOM_MIN);
            zoomIn.disabled = cur.zoom >= (comic ? COMIC_ZOOMS[COMIC_ZOOMS.length - 1] : ZOOM_MAX);
            stage.classList.toggle('rd-zoomed', cur.zoom > 1);
        }

        async function stopRender() {
            if (cur && cur.task) {
                var t = cur.task;
                cur.task = null;
                try { t.cancel(); } catch (e) { /* already finished */ }
                try { await t.promise; } catch (e) { /* cancelled */ }
            }
        }

        // Height the whole page must fit in: the stage itself in fullscreen, else the CSS cap (100dvh - 11rem).
        // On a phone only the one-line bar sits above the stage (the host hides its header), so measure it.
        function stagePad() { return viewer.classList.contains('rd-fs') ? 0 : 2; }

        function fitHeight() {
            if (viewer.classList.contains('rd-fs')) return Math.max(200, stage.clientHeight);
            var rem = parseFloat(win.getComputedStyle(doc.documentElement).fontSize) || 16;
            // Reserve what really sits above the page, so it fits short screens too (a phone in landscape).
            var top = bar.getBoundingClientRect().bottom + (win.pageYOffset || 0) + 0.5 * rem;
            var reserve = Math.min(11 * rem, top);
            // And the host's margin under the stage (capped: a host may list files below it).
            var below = doc.body.getBoundingClientRect().bottom + (parseFloat(win.getComputedStyle(doc.body).marginBottom) || 0)
                - stage.getBoundingClientRect().bottom;
            return Math.max(160, win.innerHeight - reserve - Math.min(2 * rem, Math.max(0, below)) - 2);
        }

        // A zoomed page pans inside the stage, which must not grow with it (and the CSS cap is only a guess).
        function capStage(availH) {
            stage.style.maxHeight = viewer.classList.contains('rd-fs') ? '' : (availH + 2) + 'px';
        }

        async function renderPdf() {
            var me = cur;
            syncSpreadUi();
            var ticket = ++me.renderId;
            await stopRender();
            if (cur !== me || ticket !== me.renderId) return;
            try {
                var page = await me.doc.getPage(me.page);
                if (cur !== me || ticket !== me.renderId) return;
                var natural = page.getViewport({ scale: 1 });
                var avail = Math.max(200, stage.clientWidth - stagePad());
                var availH = fitHeight();
                var scale = Math.min(avail / natural.width, availH / natural.height) * me.zoom;
                var dpr = win.devicePixelRatio || 1;
                me.fitW = stage.clientWidth;
                me.fitH = availH;
                me.fitDpr = dpr;
                capStage(availH);
                var vp = page.getViewport({ scale: scale });
                var ratio = Math.min(dpr, Math.sqrt(MAX_CANVAS_PIXELS / (vp.width * vp.height)));
                canvas.width = Math.floor(vp.width * ratio);
                canvas.height = Math.floor(vp.height * ratio);
                canvas.style.width = Math.floor(vp.width) + 'px';
                canvas.style.height = Math.floor(vp.height) + 'px';
                placeHandle();
                var ctx = canvas.getContext('2d');
                var task = page.render({
                    canvas: canvas,
                    canvasContext: ctx,
                    viewport: page.getViewport({ scale: scale * ratio })
                });
                me.task = task;
                await task.promise;
                if (me.task === task) me.task = null;
                if (cur !== me) return;
                hideLoading();
                if (ticket === me.renderId) prefetchPdf(me, me.page + 1);
            } catch (e) {
                if (e && e.name === 'RenderingCancelledException') return;
                if (cur !== me) return;
                hideLoading();
                showError('Impossible d’afficher cette page.');
            }
        }

        // getOperatorList pulls page n's bytes into the worker cache (and parses it there, off the main thread).
        // It cannot be aborted: a jump only drops it, and a new one waits for it to settle (one in flight, no queue).
        // A tiny offscreen render was tried first: it blocked clicks for 0.7-1 s (main-thread image downscale).
        async function prefetchPdf(me, n) {
            if (n > me.pages || me.prefetched[n] || me.pre) return;
            var slot = { n: n, dropped: false };
            me.pre = slot;
            try {
                var page = await me.doc.getPage(n);
                if (cur === me && !slot.dropped) {
                    await page.getOperatorList();
                    me.prefetched[n] = true;
                }
            } catch (e) { /* the real render fetches again */ }
            if (me.pre === slot) me.pre = null;
        }

        // PDF and comics share paging, saved state and controls; only the page drawing differs.
        function pagedReady() {
            return !!cur && ((cur.kind === 'pdf' && !!cur.doc) || (cur.kind === 'comic' && cur.pages > 0));
        }

        function goPdf(n, fromUser) {
            if (!pagedReady()) return;
            if (fromUser) dismissBanner();
            cur.page = clampPage(n, cur.pages);
            var last = cur.page;
            if (cur.kind === 'comic') {
                // The zoom stays from page to page; the new page starts at its top-left corner (below).
                cur.spread = spreadWanted();
                if (cur.spread) {
                    var pair = spreadFor(cur.page, cur.pages, cur.wide);
                    cur.page = pair[0];
                    last = pair[1] || pair[0];
                }
            }
            if (cur.pre && cur.pre.n !== cur.page) cur.pre.dropped = true;
            pdfPageLabel();
            // The saved page is the lower one; the fraction counts the last page seen, so the book can reach "done".
            var f = fractionOf(last, cur.pages);
            setProgressText(f);
            stage.scrollTop = 0;
            stage.scrollLeft = 0;
            // A page taller than the screen scrolls the document (phones): the new page starts at its top too.
            var top = stage.getBoundingClientRect().top;
            if (top < 0) win.scrollBy(0, top);
            // Opening at page 1 without moving must not create a "reading" entry.
            if (cur.page > 1 || readState().files[cur.name]) persist({ page: cur.page, pages: cur.pages, fraction: f });
            if (cur.kind === 'comic') renderComic();
            else renderPdf();
        }

        // ── Comic (CBZ/CBR): the server sends one page image at a time.
        // Orientation comes from the reading area, not the screen: a landscape phone and a wide desktop both qualify.
        function landscape() { return stage.clientWidth > fitHeight(); }

        function spreadWanted() { return !!cur && cur.kind === 'comic' && spreadPref && landscape(); }

        function syncSpreadUi() {
            var comic = !!cur && cur.kind === 'comic' && cur.pages > 0;
            // Comic PDFs are scans too: the same sharpening applies to the PDF canvas.
            sharpBtn.hidden = !(comic || pagedReady());
            if (sharpBtn.hidden) closeSharpPanel();
            applySharp();
            spreadBtn.hidden = !(comic && landscape());
            spreadBtn.setAttribute('aria-pressed', spreadPref ? 'true' : 'false');
            spreadBtn.textContent = spreadPref ? '2 pages' : '1 page';
        }

        function shownImgs() { return cur.shown && cur.shown[1] ? [comicImg, comicImg2] : [comicImg]; }

        function fitComic() {
            var imgs = shownImgs();
            var availH = fitHeight();
            var sizes = fitSpread(imgs.map(function (i) { return [i.naturalWidth, i.naturalHeight]; }),
                Math.max(200, stage.clientWidth - stagePad()), availH, cur.zoom);
            if (!sizes) return;
            cur.fitW = stage.clientWidth;
            cur.fitH = availH;
            imgs.forEach(function (img, i) {
                img.style.width = sizes[i].width + 'px';
                img.style.height = sizes[i].height + 'px';
            });
            capStage(availH);
            placeHandle();
        }

        function renderComic() {
            var me = cur;
            var ticket = ++me.renderId;
            var pair = me.spread ? spreadFor(me.page, me.pages, me.wide) : [me.page, null];
            var imgs = pair[1] ? [comicImg, comicImg2] : [comicImg];
            var left = imgs.length;
            var failed = false;
            me.shown = pair;
            syncSpreadUi();
            showLoading('Chargement…');
            clearError();
            if (!pair[1]) {
                comicImg2.onload = comicImg2.onerror = null;
                comicImg2.removeAttribute('src');
                comicImg2.removeAttribute('data-page');
                comicImg2.hidden = true;
            }
            imgs.forEach(function (img, i) {
                img.onload = function () {
                    if (cur === me && img.naturalWidth > img.naturalHeight) me.wide[pair[i]] = true;
                    if (cur !== me || ticket !== me.renderId || failed || --left > 0) return;
                    // A page found wide while shown in a pair: regroup. Pairs never hold a known wide page, so this ends.
                    if (pair[1] && (me.wide[pair[0]] || me.wide[pair[1]])) { goPdf(me.page, false); return; }
                    comicBox.hidden = false;
                    comicImg2.hidden = !pair[1];
                    fitComic();
                    hideLoading();
                    var after = (pair[1] || pair[0]) + 1;
                    var before = pair[0] - 1;
                    var next = after > me.pages ? [] : me.spread ? spreadFor(after, me.pages, me.wide) : [after];
                    var prev = before < 1 ? [] : me.spread ? spreadFor(before, me.pages, me.wide) : [before];
                    prefetchComic(me, next.concat(prev));
                };
                img.onerror = function () {
                    if (cur !== me || ticket !== me.renderId || failed) return;
                    failed = true;
                    comicBox.hidden = true;
                    hideLoading();
                    showError('Impossible d’afficher cette page.');
                };
                img.setAttribute('data-page', String(pair[i]));
                img.src = comicPageUrl(me.url, pair[i]);
            });
        }

        // The next and previous pages (or spreads): a new prefetch replaces (and cancels) the previous one.
        function prefetchComic(me, pages) {
            (me.next || []).forEach(function (i) { i.onload = null; i.removeAttribute('src'); });
            me.next = pages.filter(function (n) { return n; }).map(function (n) {
                var i = new win.Image();
                i.onload = function () {
                    if (!(i.naturalWidth > i.naturalHeight) || me.wide[n]) return;
                    me.wide[n] = true;
                    // The pairing just changed: redraw now (images are cached), or Prev/Next would skip or repeat a page.
                    if (cur !== me || !me.spread || !me.shown || comicBox.hidden) return;
                    var g = spreadFor(me.page, me.pages, me.wide);
                    if (g[0] !== me.shown[0] || g[1] !== me.shown[1]) goPdf(me.page, false);
                };
                i.src = comicPageUrl(me.url, n);
                return i;
            });
        }

        // Brings the point under (cx, cy) - the stage centre by default - to the middle of the stage,
        // or keeps it under the pointer when `stay` is set (wheel).
        function setComicZoom(z, cx, cy, stay) {
            if (!cur || cur.kind !== 'comic' || comicBox.hidden) return;
            var sr = stage.getBoundingClientRect();
            var br = comicBox.getBoundingClientRect();
            if (cx === undefined) {
                cx = sr.left + stage.clientWidth / 2;
                cy = sr.top + stage.clientHeight / 2;
            }
            var fx = Math.min(1, Math.max(0, (cx - br.left) / Math.max(1, br.width)));
            var fy = Math.min(1, Math.max(0, (cy - br.top) / Math.max(1, br.height)));
            cur.zoom = z;
            pdfPageLabel();
            fitComic();
            if (z <= 1) {
                stage.scrollLeft = 0;
                stage.scrollTop = 0;
            } else {
                var nb = comicBox.getBoundingClientRect();
                var ns = stage.getBoundingClientRect();
                stage.scrollLeft += nb.left + fx * nb.width - (stay ? cx : ns.left + stage.clientWidth / 2);
                stage.scrollTop += nb.top + fy * nb.height - (stay ? cy : ns.top + stage.clientHeight / 2);
            }
        }

        function comicZoomed() { return !!cur && cur.kind === 'comic' && cur.zoom > 1; }

        async function openComic(me) {
            showLoading('Chargement…');
            var res = await win.fetch(comicCountUrl(me.url), { credentials: 'same-origin' });
            var data = res.ok ? await res.json() : null;
            if (cur !== me) return;
            var pages = data && Math.floor(Number(data.pages));
            if (!pages || pages < 1) {
                var err = new Error('comic unreadable');
                err.comicMessage = data && Number(data.pages) === 0 ? COMIC_EMPTY : COMIC_UNREADABLE;
                throw err;
            }
            me.pages = pages;
            me.page = 1;
            me.spread = spreadWanted();
            pdfPageLabel();
            setProgressText(fractionOf(1, me.pages));
            renderComic();
        }

        async function openPdf(me, url) {
            showLoading('Chargement…');
            var pdfjs;
            try {
                pdfjs = await import(base + 'pdfjs/pdf.min.mjs');
            } catch (e) {
                var old = new Error('pdfjs import failed');
                old.oldBrowser = true;
                throw old;
            }
            if (cur !== me) return;
            pdfjs.GlobalWorkerOptions.workerSrc = base + 'pdfjs/pdf.worker.min.mjs';
            // eval is off by default in the page CSP: skip the probe that would log a violation.
            // Range mode only: fetch the bytes of the pages shown, never the whole file in the background.
            var task = pdfjs.getDocument({ url: me.url, isEvalSupported: false, disableAutoFetch: true, disableStream: true });
            me.loading = task;
            task.onProgress = function (p) {
                // Range reads keep reporting after the document opens: only the opening wait is shown.
                if (cur !== me || me.doc || !p || !(p.total > 0)) return;
                showLoading('Chargement… ' + Math.min(100, Math.floor(p.loaded / p.total * 100)) + ' %');
            };
            var pdf = await task.promise;
            if (cur !== me) { pdf.destroy(); return; }
            me.doc = pdf;
            me.prefetched = {};
            me.pages = pdf.numPages;
            me.page = 1;
            me.zoom = 1;
            canvas.hidden = false;
            pdfPageLabel();
            setProgressText(fractionOf(1, me.pages));
            renderPdf();
        }

        // ── EPUB
        // foliate renders chapters in blob: iframes; a CSP without frame-src blob: blocks them.
        function blobFramesAllowed() {
            return new Promise(function (resolve) {
                var url = URL.createObjectURL(new Blob(['<!doctype html>'], { type: 'text/html' }));
                var frame = doc.createElement('iframe');
                frame.hidden = true;
                frame.setAttribute('sandbox', 'allow-same-origin');
                var done = false;
                function end(ok) {
                    if (done) return;
                    done = true;
                    doc.removeEventListener('securitypolicyviolation', onViolation);
                    win.clearTimeout(timer);
                    frame.remove();
                    URL.revokeObjectURL(url);
                    resolve(ok);
                }
                function onViolation(e) { if (/frame|child/.test(e.violatedDirective || '')) end(false); }
                var timer = win.setTimeout(function () { end(false); }, 3000);
                doc.addEventListener('securitypolicyviolation', onViolation);
                // A blocked frame still fires load, but its document is not reachable.
                frame.addEventListener('load', function () {
                    var ok = false;
                    try { ok = !!frame.contentDocument; } catch (e) { /* cross-origin error page */ }
                    end(ok);
                });
                frame.src = url;
                rootEl.appendChild(frame);
            });
        }

        var EPUB_POLICY = "script-src 'none'; object-src 'none'; frame-src 'none'; base-uri 'none'";
        var XHTML_NS = 'http://www.w3.org/1999/xhtml';
        var BLANK_PAGE = '<html xmlns="' + XHTML_NS + '"><head><meta http-equiv="Content-Security-Policy" content="' +
            EPUB_POLICY + '"/></head><body></body></html>';

        // Re-parsed rather than patched as text: a regex can land in a comment, and XHTML allows
        // a <script> before <head>. A meta CSP only counts inside head and for what follows it.
        function lockDocument(data, type) {
            if (typeof data !== 'string') return null;
            var xml = /xhtml/i.test(type);
            var d = new win.DOMParser().parseFromString(data, xml ? 'application/xhtml+xml' : 'text/html');
            var html = d.documentElement;
            if (!html || html.localName !== 'html' || (xml && d.getElementsByTagName('parsererror').length)) return null;
            // An xml-stylesheet (XSLT) would build a new document without our meta.
            Array.prototype.slice.call(d.childNodes).forEach(function (n) {
                if (n.nodeType === 7) d.removeChild(n);
            });
            var head = d.head || d.createElementNS(XHTML_NS, 'head');
            html.insertBefore(head, html.firstChild);
            var meta = d.createElementNS(XHTML_NS, 'meta');
            meta.setAttribute('http-equiv', 'Content-Security-Policy');
            meta.setAttribute('content', EPUB_POLICY);
            head.insertBefore(meta, head.firstChild);
            return new win.XMLSerializer().serializeToString(d);
        }

        function svgSize(svg) {
            var tag = (svg.match(/<svg\b[^>]*>/i) || [''])[0];
            var num = /^\s*(\d+(?:\.\d+)?)(?:px)?\s*$/;
            var vb = (tag.match(/\sviewBox\s*=\s*["']([^"']*)["']/i) || [])[1];
            var p = vb ? vb.trim().split(/[\s,]+/) : [];
            if (p.length === 4 && num.test(p[2]) && num.test(p[3])) return [p[2].match(num)[1], p[3].match(num)[1]];
            var w = (tag.match(/\swidth\s*=\s*["']([^"']*)["']/i) || [])[1];
            var h = (tag.match(/\sheight\s*=\s*["']([^"']*)["']/i) || [])[1];
            if (w && h && num.test(w) && num.test(h)) return [w.match(num)[1], h.match(num)[1]];
            return null;
        }

        // An SVG shown through <img> runs no script at all; as a frame document it would (no meta CSP in SVG).
        // Limit: an image runs no fetch either, so raster images the SVG links to are not drawn.
        function svgPage(svg) {
            if (typeof svg !== 'string') return BLANK_PAGE;
            var size = svgSize(svg);
            return '<html xmlns="' + XHTML_NS + '"><head><meta http-equiv="Content-Security-Policy" content="' + EPUB_POLICY + '"/>' +
                (size ? '<meta name="viewport" content="width=' + size[0] + ', height=' + size[1] + '"/>' : '') +
                '<style>html,body{margin:0;height:100%}body{display:flex;align-items:center;justify-content:center}' +
                'img{display:block;max-width:100%;max-height:100vh}</style></head><body><img alt="" src="data:image/svg+xml;charset=utf-8,' +
                encodeURIComponent(svg) + '"/></body></html>';
        }

        // Chapters only: every (X)HTML document is locked; other spine types never reach a frame raw.
        function guardResource(d, spine) {
            var type = String(d.type);
            if (/html/i.test(type)) {
                d.data = Promise.resolve(d.data).then(function (data) {
                    return lockDocument(data, type) || BLANK_PAGE;
                });
                return;
            }
            if (!spine[d.name]) return;
            if (/^image\/(png|jpeg|gif|webp|avif)$/i.test(type)) return;
            d.data = /^image\/svg\+xml$/i.test(type) ? Promise.resolve(d.data).then(svgPage) : BLANK_PAGE;
            d.type = 'application/xhtml+xml';
        }

        async function openEpub(me, url) {
            if (!(await blobFramesAllowed())) throw new Error('blob frames blocked');
            if (cur !== me) return;
            await import(base + 'foliate/view.js');
            // A ranged request is not counted against max_downloads (api/public_stream.php); 206 still carries the whole file.
            var res = await fetch(url, { credentials: 'same-origin', headers: { Range: 'bytes=0-' } });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            var blob = await res.blob();
            if (cur !== me) return;
            var file = new File([blob], me.name, { type: 'application/epub+zip' });
            var view = doc.createElement('foliate-view');
            view.className = 'rd-epub-view';
            epubHost.appendChild(view);
            me.view = view;
            var nav = win.navigator || {};
            var strict = !needsScriptSandbox(nav.userAgent, nav.platform, nav.maxTouchPoints);
            // Held until closeBook: foliate creates a new iframe for each chapter it shows.
            if (strict) {
                lockIframeSandbox(win);
                me.sandboxLocked = true;
            }
            await view.open(file);
            if (cur !== me) return;
            // foliate keeps inline scripts and its iframes are same-origin: forbid script in every chapter.
            var transform = view.book && view.book.transformTarget;
            if (!transform) throw new Error('no transform hook');
            var spine = {};
            (view.book.sections || []).forEach(function (s) { if (s && s.id) spine[s.id] = true; });
            transform.addEventListener('data', function (ev) {
                if (ev.detail) guardResource(ev.detail, spine);
            });
            try {
                var cs = win.getComputedStyle(rootEl);
                me.siteColors = {
                    bg: cs.getPropertyValue('--as-surface').trim(),
                    fg: cs.getPropertyValue('--as-text').trim(),
                    link: cs.getPropertyValue('--as-accent').trim()
                };
            } catch (e) { /* default colors */ }
            applyEpubStyles();
            epubBtn.hidden = false;
            var first = new Promise(function (resolve) {
                view.addEventListener('relocate', function once() {
                    view.removeEventListener('relocate', once);
                    resolve();
                });
            });
            view.addEventListener('relocate', function (e) {
                var d = e.detail || {};
                var f = typeof d.fraction === 'number' ? d.fraction : 0;
                setProgressText(f);
                // A first relocate at the start must not turn a never-read book into "reading 0 %".
                if (f < 0.005 && !readState().files[me.name]) return;
                persist({ cfi: d.cfi, fraction: f });
            });
            // foliate's default is window.open(href) for any scheme, javascript: included, with opener access.
            view.addEventListener('external-link', function (e) {
                e.preventDefault();
                var href = e.detail && e.detail.href;
                if (/^(https?|mailto):/i.test(String(href))) win.open(href, '_blank', 'noopener');
            });
            view.addEventListener('load', function (e) {
                var d = e.detail && e.detail.doc;
                // Fail closed if the sandbox patch was bypassed (another script, a foliate update).
                if (d && strict && frameRunsScripts(d)) {
                    // Deferred past foliate's own frame callbacks on this document; the timer covers hidden tabs (no rAF).
                    var shut = function () {
                        if (cur !== me) return;
                        showLibrary();
                        showError(EPUB_UNAVAILABLE);
                    };
                    win.requestAnimationFrame(function () { win.setTimeout(shut, 0); });
                    win.setTimeout(shut, 250);
                    return;
                }
                if (d) {
                    d.addEventListener('keydown', onKey);
                }
            });
            var timer;
            var timeout = new Promise(function (resolve, reject) {
                timer = win.setTimeout(function () { reject(new Error('timeout')); }, EPUB_OPEN_TIMEOUT_MS);
            });
            try {
                await Promise.race([Promise.all([view.init({ showTextStart: true }), first]), timeout]);
            } finally {
                win.clearTimeout(timer);
            }
        }

        // ── Open / close
        function dismissBanner() {
            if (!cur) return;
            banner.hidden = true;
            cur.hold = false;
        }

        function closeBook() {
            flush();
            var me = cur;
            cur = null;
            seq++;
            hideLoading();
            doc.documentElement.classList.remove('rd-reading');
            setPageZoomLock(false);
            if (tapTimer) { win.clearTimeout(tapTimer); tapTimer = null; }
            pan = null;
            releaseWake();
            stopCursorIdle();
            if (!me) return;
            if (me.task) { try { me.task.cancel(); } catch (e) { /* done */ } }
            if (me.loading) { try { me.loading.destroy(); } catch (e) { /* done */ } }
            if (me.doc) { try { me.doc.destroy(); } catch (e) { /* done */ } }
            if (me.view) {
                try { me.view.book.destroy(); } catch (e) { /* frees chapter blob URLs */ }
                try { me.view.close(); } catch (e) { /* done */ }
                me.view.remove();
            }
            if (me.sandboxLocked) {
                me.sandboxLocked = false;
                unlockIframeSandbox();
            }
            canvas.width = canvas.height = 0;
            (me.next || []).forEach(function (i) { i.removeAttribute('src'); });
            [comicImg, comicImg2].forEach(function (i) {
                i.onload = i.onerror = null;
                i.removeAttribute('src');
                i.removeAttribute('data-page');
            });
            comicImg2.hidden = true;
            comicBox.hidden = true;
            spreadBtn.hidden = true;
            sharpBtn.hidden = true;
            closeSharpPanel();
            epubBtn.hidden = true;
            closeEpubPanel();
            stage.style.maxHeight = '';
        }

        function showLibrary() {
            var closed = cur && cur.name;
            exitFs();
            closeBook();
            clearError();
            viewer.hidden = true;
            library.hidden = false;
            renderLibrary();
            var target = null;
            list.querySelectorAll('.rd-book').forEach(function (n) {
                if (n.getAttribute('data-name') === closed) target = n;
            });
            target = target || list.querySelector('.rd-book');
            if (target) target.focus();
        }

        async function openBook(name) {
            var b = byName[name];
            if (!b) return;
            closeBook();
            clearError();
            var me = {
                name: name, kind: b.kind, url: b.url, hold: false,
                renderId: 0, page: 1, pages: 0, zoom: 1, task: null, doc: null, view: null, next: null, wide: {}
            };
            cur = me;
            var mySeq = ++seq;
            holdWake();

            var target = resumeTarget(readState(), name);
            // 0 % (after "Recommencer") has nothing to resume, for PDF and EPUB alike.
            if (target && target.percent === 0) target = null;
            // Opening at the start must not overwrite the saved spot before the reader chooses.
            me.hold = !!target;
            titleEl.textContent = name.replace(/\.[A-Za-z0-9]+$/, '');
            var isPdf = b.kind === 'pdf';
            var isComic = b.kind === 'comic';
            var isPaged = isPdf || isComic;
            doc.documentElement.classList.add('rd-reading');
            setPageZoomLock(isComic);
            viewer.setAttribute('data-kind', b.kind);
            canvas.hidden = true;
            comicBox.hidden = true;
            epubHost.hidden = isPaged;
            stage.classList.toggle('rd-stage-epub', !isPaged);
            stage.classList.toggle('rd-stage-comic', isComic);
            pageBox.hidden = !isPaged;
            zoomBox.hidden = !isPaged;
            progressEl.textContent = '';
            library.hidden = true;
            viewer.hidden = false;
            banner.hidden = true;

            try {
                if (isPdf) await openPdf(me, b.url);
                else if (isComic) await openComic(me);
                else await openEpub(me, b.url);
            } catch (e) {
                if (cur !== me || seq !== mySeq) return;
                if (isComic) {
                    // Stay in the viewer: the message sits above it and Back still works.
                    hideLoading();
                    showError((e && e.comicMessage) || COMIC_UNREADABLE);
                    return;
                }
                showLibrary();
                showError(e && e.oldBrowser
                    ? 'Ce navigateur est trop ancien pour la liseuse PDF. Télécharger le fichier.'
                    : isPdf
                    ? 'PDF indisponible : le fichier n’a pas pu être chargé.'
                    : EPUB_UNAVAILABLE);
                return;
            }
            if (cur !== me) return;
            // Focus the page, not Back: Space then turns the page instead of leaving the book.
            stage.focus({ preventScroll: true });

            if (target) {
                bannerText.textContent = 'Reprendre à ' + target.percent + ' % ?';
                banner.hidden = false;
                resumeYes.onclick = function () {
                    dismissBanner();
                    if (isPaged) goPdf(target.page || 1, false);
                    else if (target.cfi && me.view) me.view.goTo(target.cfi).catch(function () { /* stale location */ });
                };
                resumeNo.onclick = function () {
                    dismissBanner();
                    if (pendingTimer) { win.clearTimeout(pendingTimer); pendingTimer = null; }
                    pending = null;
                    clearProgress(store, token, name);
                    renderLibrary();
                    if (isPaged) goPdf(1, false);
                };
            }
        }

        // ── Controls
        function step(dir) {
            if (!cur) return;
            if (cur.kind === 'pdf' || cur.kind === 'comic') {
                if (!pagedReady()) return;
                var to = cur.kind === 'comic' && cur.spread ? stepSpread(cur.page, cur.pages, dir, cur.wide) : cur.page + dir;
                if (clampPage(to, cur.pages) !== cur.page) goPdf(to, true);
            }
            else if (cur.view) {
                dismissBanner();
                (dir < 0 ? cur.view.prev() : cur.view.next()).catch(function () { /* book edge */ });
            }
        }

        // dir 0 goes back to fit. The centre of the visible area stays in the centre.
        function zoom(dir) {
            if (cur && cur.kind === 'comic') { setComicZoom(dir ? comicZoomStep(cur.zoom, dir) : 1); return; }
            if (!cur || cur.kind !== 'pdf' || !cur.doc) return;
            var z = dir ? Math.round((cur.zoom + dir * ZOOM_STEP) * 100) / 100 : 1;
            z = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, z));
            if (z === cur.zoom) return;
            var me = cur;
            var fx = (stage.scrollLeft + stage.clientWidth / 2) / Math.max(1, stage.scrollWidth);
            var fy = (stage.scrollTop + stage.clientHeight / 2) / Math.max(1, stage.scrollHeight);
            cur.zoom = z;
            pdfPageLabel();
            var id = me.renderId + 1;
            renderPdf().then(function () {
                if (cur !== me || me.renderId !== id) return;
                stage.scrollLeft = fx * stage.scrollWidth - stage.clientWidth / 2;
                stage.scrollTop = fy * stage.scrollHeight - stage.clientHeight / 2;
            });
        }

        function stageEdges() {
            var maxL = stage.scrollWidth - stage.clientWidth;
            var maxT = stage.scrollHeight - stage.clientHeight;
            return {
                top: stage.scrollTop <= 1, bottom: stage.scrollTop >= maxT - 1,
                left: stage.scrollLeft <= 1, right: stage.scrollLeft >= maxL - 1
            };
        }

        function onKey(e) {
            if (!cur || viewer.hidden) return;
            if (e.key === 'Tab' && isFs()) {
                revealBar();
                if (!bar.contains(doc.activeElement)) {
                    e.preventDefault();
                    (e.shiftKey ? fsBtn : backBtn).focus();
                }
                return;
            }
            if (e.altKey || e.ctrlKey || e.metaKey) return;
            if (e.key === 'Escape') {
                if (e.shiftKey) return;
                if (isFs() && !viewer.classList.contains('rd-bar-hidden')) { e.preventDefault(); hideBar(); }
                else if (pseudo) { e.preventDefault(); exitFs(); }
                return;
            }
            var t = e.target;
            var tag = t && t.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
            // Space pages the book even from a bar button clicked with the mouse; only Back, the handle and links keep it.
            if ((e.key === ' ' || e.key === 'Spacebar') && (tag === 'A' || t === backBtn || t === handle)) return;
            if (e.key === 'f' && !e.shiftKey) { e.preventDefault(); toggleFs(); return; }
            var paged = pagedReady();
            var act = keyAction(e.key, e.shiftKey, paged && cur.zoom > 1, paged ? stageEdges() : null);
            if (!act || (/^zoom/.test(act) && !paged)) return;
            var dy = Math.max(40, Math.round(stage.clientHeight * KEY_SCROLL));
            var dx = Math.max(40, Math.round(stage.clientWidth * KEY_SCROLL));
            if (act === 'scroll-up' || act === 'scroll-down') {
                // No vertical overflow in the stage: let the page scroll natively.
                if (stage.scrollHeight - stage.clientHeight <= 1) return;
                e.preventDefault();
                stage.scrollBy(0, act === 'scroll-up' ? -dy : dy);
                return;
            }
            e.preventDefault();
            if (act === 'scroll-left' || act === 'scroll-right') stage.scrollBy(act === 'scroll-left' ? -dx : dx, 0);
            else if (act === 'next' || act === 'prev') step(act === 'next' ? 1 : -1);
            else if (act === 'first' || act === 'last') jumpEnd(act === 'last');
            else zoom(act === 'zoom-in' ? 1 : act === 'zoom-out' ? -1 : 0);
        }

        function jumpEnd(last) {
            if (!cur) return;
            if (cur.kind === 'pdf' || cur.kind === 'comic') {
                if (!pagedReady()) return;
                var to = last ? cur.pages : 1;
                if (cur.kind === 'comic' && cur.spread) to = spreadFor(to, cur.pages, cur.wide)[0];
                if (cur.page !== to) goPdf(to, true);
            } else if (cur.view) {
                dismissBanner();
                try { cur.view.goToFraction(last ? 1 : 0).catch(function () { /* edge */ }); } catch (e) { /* no api */ }
            }
        }

        // ── Fullscreen: a fixed overlay (Chrome's own native exit cross would sit on top of the bar).
        var pseudo = false;
        var savedOverflow = null;
        var savedViewport = null;
        var viewportCover = false;
        var viewportNoZoom = false;
        var hideTimer = null;

        function isFs() { return pseudo; }

        function enterPseudo() {
            if (pseudo) return;
            pseudo = true;
            savedOverflow = [doc.documentElement.style.overflow, doc.body.style.overflow];
            doc.documentElement.style.overflow = 'hidden';
            doc.body.style.overflow = 'hidden';
            syncFs();
        }

        function leavePseudo() {
            if (!pseudo) return;
            pseudo = false;
            if (savedOverflow) {
                doc.documentElement.style.overflow = savedOverflow[0];
                doc.body.style.overflow = savedOverflow[1];
                savedOverflow = null;
            }
            syncFs();
        }

        function enterFs() { enterPseudo(); }

        function exitFs() { leavePseudo(); }

        function toggleFs() { if (isFs()) exitFs(); else enterFs(); }

        // viewport-fit=cover is only needed (and only safe for the other layouts) while the viewer owns the screen.
        // A comic also turns the browser's pinch zoom off: on real phones touch-action alone let the page zoom.
        function syncViewport() {
            var meta = doc.querySelector('meta[name="viewport"]');
            if (!meta) return;
            if (savedViewport === null) {
                if (!viewportCover && !viewportNoZoom) return;
                savedViewport = meta.getAttribute('content') || '';
            }
            var content = savedViewport;
            if (viewportCover && !/viewport-fit/.test(content)) content += ', viewport-fit=cover';
            if (viewportNoZoom) content += ', maximum-scale=1, user-scalable=no';
            meta.setAttribute('content', content);
            if (!viewportCover && !viewportNoZoom) savedViewport = null;
        }
        function setViewportCover(on) { viewportCover = on; syncViewport(); }
        function setPageZoomLock(on) { viewportNoZoom = on; syncViewport(); }

        function barFocused() {
            try { return !!bar.querySelector(':focus-visible'); } catch (e) { return false; }
        }

        function hideBar() {
            if (hideTimer) { win.clearTimeout(hideTimer); hideTimer = null; }
            viewer.classList.add('rd-bar-hidden');
            placeHandle();
        }

        function armHide() {
            if (hideTimer) { win.clearTimeout(hideTimer); hideTimer = null; }
            hideTimer = win.setTimeout(function () {
                hideTimer = null;
                var hot = false;
                try { hot = bar.matches(':hover'); } catch (e) { /* no :hover */ }
                if (hot || barFocused()) { armHide(); return; }
                hideBar();
            }, FS_HIDE_MS);
        }

        // In fullscreen the bar lives off the page and only overlays it on request.
        function revealBar() {
            if (!viewer.classList.contains('rd-fs')) return;
            viewer.classList.remove('rd-bar-hidden');
            armHide();
        }

        // The handle sits in the stage's top-left corner: in free space around the page when there is some, else a faint overlay.
        function placeHandle() {
            if (!viewer.classList.contains('rd-fs') || !viewer.classList.contains('rd-bar-hidden')) return;
            var sr = stage.getBoundingClientRect();
            var vr = viewer.getBoundingClientRect();
            var top = 0;
            var left = 0;
            var pageEl = cur && cur.kind === 'comic' ? comicBox : canvas;
            if (cur && (cur.kind === 'pdf' || cur.kind === 'comic') && !pageEl.hidden) {
                var cr = pageEl.getBoundingClientRect();
                top = cr.top - sr.top;
                left = cr.left - sr.left;
            }
            var free = top >= HANDLE_FREE_PX || left >= HANDLE_FREE_PX;
            var gap = free ? 4 : 0;
            handle.style.left = Math.max(0, sr.left - vr.left + gap) + 'px';
            handle.style.top = Math.max(0, sr.top - vr.top + gap) + 'px';
            handle.classList.toggle('rd-bar-handle-over', !free);
        }

        var wasFs = false;
        function syncFs() {
            var on = isFs();
            viewer.classList.toggle('rd-fs', on);
            viewer.classList.toggle('rd-pseudo-fs', pseudo);
            fsBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
            fsBtn.setAttribute('aria-label', on ? 'Quitter le plein écran' : 'Plein écran');
            fsPath.setAttribute('d', on
                ? 'M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5'
                : 'M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5');
            setViewportCover(on);
            if (on && !wasFs) {
                hideBar();
                wakeCursor();
            } else if (!on) {
                if (hideTimer) { win.clearTimeout(hideTimer); hideTimer = null; }
                viewer.classList.remove('rd-bar-hidden');
                stopCursorIdle();
            }
            wasFs = on;
            scheduleRefit();
        }

        // The cursor hides over a still fullscreen page; it never brings the bar back.
        var cursorTimer = null;
        function stopCursorIdle() {
            if (cursorTimer) { win.clearTimeout(cursorTimer); cursorTimer = null; }
            viewer.classList.remove('rd-idle');
        }
        function wakeCursor() {
            stopCursorIdle();
            if (!viewer.classList.contains('rd-fs')) return;
            cursorTimer = win.setTimeout(function () {
                cursorTimer = null;
                viewer.classList.add('rd-idle');
            }, CURSOR_IDLE_MS);
        }
        viewer.addEventListener('pointermove', function (e) {
            if (e.pointerType === 'mouse') wakeCursor();
        });

        // Keeps the screen on while a book is open; silently does nothing where unsupported or refused.
        var wakeLock = null;
        var wakeAsked = false;
        function holdWake() {
            var wl = win.navigator && win.navigator.wakeLock;
            if (!cur || !wl || wakeAsked || (wakeLock && !wakeLock.released)) return;
            wakeAsked = true;
            try {
                Promise.resolve(wl.request('screen')).then(function (lock) {
                    wakeAsked = false;
                    wakeLock = lock;
                    // The book closed while the request was pending.
                    if (!cur) releaseWake();
                }, function () { wakeAsked = false; });
            } catch (e) { wakeAsked = false; }
        }
        function releaseWake() {
            var lock = wakeLock;
            wakeLock = null;
            if (!lock) return;
            try { Promise.resolve(lock.release()).catch(function () { /* already released */ }); } catch (e) { /* already released */ }
        }

        fsBtn.addEventListener('click', toggleFs);
        handle.addEventListener('click', function () {
            revealBar();
            fsBtn.focus();
        });
        bar.addEventListener('focusin', revealBar);

        stage.addEventListener('scroll', function () {
            placeHandle();
        });

        // Side thirds turn pages, the middle toggles the bar in fullscreen. Mouse and touch both end in `click`; swipes never do.
        var downAt = null;
        function zoneOf(e) {
            var r = stage.getBoundingClientRect();
            var f = (e.clientX - r.left) / Math.max(1, r.width);
            return f < ZONE_SIDE ? -1 : (f > 1 - ZONE_SIDE ? 1 : 0);
        }
        function zoneActive() { return pagedReady(); }
        function tapZone(z) {
            if (!zoneActive()) return;
            if (z) step(z);
            // The bar only comes back from its handle: a tap in the middle can hide it, never show it.
            else if (isFs() && !viewer.classList.contains('rd-bar-hidden')) hideBar();
        }
        // Zoomed comic: the mouse drags the page around (touch pans natively, see .rd-stage-comic.rd-zoomed).
        var pan = null;
        stage.addEventListener('pointerdown', function (e) {
            downAt = { x: e.clientX, y: e.clientY };
            if (e.pointerType !== 'mouse' || e.button !== 0 || !comicZoomed()) return;
            pan = { id: e.pointerId, x: e.clientX, y: e.clientY, l: stage.scrollLeft, t: stage.scrollTop };
            try { stage.setPointerCapture(e.pointerId); } catch (err) { /* pointer already gone */ }
            e.preventDefault();
        });
        stage.addEventListener('pointermove', function (e) {
            if (pan && e.pointerId === pan.id) {
                stage.scrollLeft = pan.l - (e.clientX - pan.x);
                stage.scrollTop = pan.t - (e.clientY - pan.y);
                return;
            }
            if (e.pointerType !== 'mouse') return;
            stage.style.cursor = comicZoomed() ? 'grab' : zoneActive() && zoneOf(e) ? 'pointer' : '';
        });
        function endPan(e) { if (pan && e.pointerId === pan.id) pan = null; }
        stage.addEventListener('pointerup', endPan);
        // The wheel zooms (comic around the pointer, PDF by its steps), one step per notch (a touchpad's small deltas add up).
        // Ctrl+wheel (and touchpad pinch, sent as Ctrl+wheel) stays the browser's own zoom.
        var wheelSum = 0;
        stage.addEventListener('wheel', function (e) {
            if (e.ctrlKey || e.metaKey || !pagedReady()) return;
            if (cur.kind === 'pdf') {
                e.preventDefault();
                wheelSum += e.deltaY * (e.deltaMode === 1 ? 40 : e.deltaMode === 2 ? 800 : 1);
                if (Math.abs(wheelSum) < WHEEL_STEP_PX) return;
                var pdfDir = wheelSum < 0 ? 1 : -1;
                wheelSum = 0;
                zoom(pdfDir);
                return;
            }
            if (cur.kind !== 'comic' || comicBox.hidden) return;
            e.preventDefault();
            wheelSum += e.deltaY * (e.deltaMode === 1 ? 40 : e.deltaMode === 2 ? 800 : 1);
            if (Math.abs(wheelSum) < WHEEL_STEP_PX) return;
            var dir = wheelSum < 0 ? 1 : -1;
            wheelSum = 0;
            var z = comicZoomStep(cur.zoom, dir);
            if (z !== cur.zoom) setComicZoom(z, e.clientX, e.clientY, true);
        }, { passive: false });
        stage.addEventListener('pointercancel', endPan);
        // Comics wait DOUBLE_TAP_MS before a single tap acts, so the first tap of a double tap never turns the page.
        var tapTimer = null;
        var lastTap = null;
        stage.addEventListener('click', function (e) {
            if (!pagedReady()) return;
            if (Date.now() - multiTouchAt < MULTI_TOUCH_QUIET_MS) return;
            if (e.target && e.target.closest && e.target.closest('input,textarea,select,button,a,label')) return;
            if (downAt && Math.hypot(e.clientX - downAt.x, e.clientY - downAt.y) > CLICK_SLOP_PX) return;
            if (cur.kind === 'comic') {
                var near = lastTap && Math.hypot(e.clientX - lastTap.x, e.clientY - lastTap.y) <= HANDLE_SIZE;
                if (tapTimer) {
                    win.clearTimeout(tapTimer);
                    tapTimer = null;
                    if (near) {
                        lastTap = null;
                        setComicZoom(cur.zoom > 1 ? 1 : COMIC_TAP_ZOOM, e.clientX, e.clientY);
                        return;
                    }
                    tapZone(lastTap.z);
                }
                var tap = lastTap = { x: e.clientX, y: e.clientY, z: zoneOf(e) };
                tapTimer = win.setTimeout(function () {
                    tapTimer = null;
                    lastTap = null;
                    tapZone(tap.z);
                }, DOUBLE_TAP_MS);
                return;
            }
            if (!zoneActive()) return;
            var sel = win.getSelection && win.getSelection();
            if (sel && String(sel).length && sel.anchorNode && stage.contains(sel.anchorNode)) return;
            tapZone(zoneOf(e));
        });

        // Horizontal swipe turns PDF pages; EPUB swipes are handled by foliate itself.
        // Two fingers on a comic zoom the reader, not the browser page; such a gesture is never a swipe or a tap.
        var swipe = null;
        var pinch = null;
        var multiTouchAt = 0;
        function touchGap(e) {
            var a = e.touches[0], b = e.touches[1];
            return Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
        }
        function applyPinch() {
            var p = pinch;
            if (!p) return;
            p.raf = 0;
            if (p.want) { setComicZoom(p.want.z, p.want.x, p.want.y, true); p.want = null; }
        }
        function endPinch() {
            var p = pinch;
            if (!p) return;
            if (p.raf) win.cancelAnimationFrame(p.raf);
            applyPinch();
            pinch = null;
            if (cur && cur.kind === 'comic' && cur.zoom > 1 && cur.zoom < PINCH_SNAP) setComicZoom(1);
        }
        stage.addEventListener('touchstart', function (e) {
            if (e.touches.length > 1) {
                multiTouchAt = Date.now();
                if (swipe) swipe.dead = true;
                if (tapTimer) { win.clearTimeout(tapTimer); tapTimer = null; lastTap = null; }
                if (e.touches.length === 2 && !pinch && cur && cur.kind === 'comic' && !comicBox.hidden) {
                    pinch = { z: cur.zoom, d: touchGap(e), want: null, raf: 0 };
                }
                return;
            }
            var t = e.touches[0];
            var tag = e.target && e.target.closest ? e.target.closest('input,textarea,select,button,a') : null;
            swipe = { x: t.clientX, y: t.clientY, dead: !!tag };
        }, { passive: true });
        stage.addEventListener('touchmove', function (e) {
            if (e.touches.length < 2) return;
            multiTouchAt = Date.now();
            if (!pinch) return;
            // A native pan already under way makes touchmove uncancelable: zooming then would fight the scroll.
            if (!e.cancelable) return;
            e.preventDefault();
            var a = e.touches[0], b = e.touches[1];
            pinch.want = { z: pinchZoom(pinch.z, pinch.d, touchGap(e)), x: (a.clientX + b.clientX) / 2, y: (a.clientY + b.clientY) / 2 };
            if (!pinch.raf) pinch.raf = win.requestAnimationFrame(applyPinch);
        }, { passive: false });
        // Fingers on the bar or the margins must not pinch the page either; gesturestart is Safari's pinch.
        viewer.addEventListener('touchmove', function (e) {
            if (e.touches.length > 1 && cur && cur.kind === 'comic') e.preventDefault();
        }, { passive: false });
        viewer.addEventListener('gesturestart', function (e) {
            if (cur && cur.kind === 'comic') e.preventDefault();
        });
        stage.addEventListener('touchcancel', function () { swipe = null; endPinch(); });
        stage.addEventListener('touchend', function (e) {
            var s = swipe;
            if (e.touches.length < 2) endPinch();
            if (e.touches.length > 0) { if (s) s.dead = true; return; }
            swipe = null;
            // Zoomed, a drag only pans: the side zones (taps) still turn the page.
            if (!s || s.dead || !pagedReady() || cur.zoom > 1) return;
            if (stage.scrollWidth - stage.clientWidth > 1) return;
            var t = e.changedTouches[0];
            var dx = t.clientX - s.x;
            var dy = t.clientY - s.y;
            if (Math.abs(dx) < SWIPE_MIN_PX || Math.abs(dx) < Math.abs(dy) * 1.5) return;
            step(dx < 0 ? 1 : -1);
        });

        prevBtn.addEventListener('click', function () { step(-1); });
        nextBtn.addEventListener('click', function () { step(1); });
        zoomIn.addEventListener('click', function () { zoom(1); });
        zoomOut.addEventListener('click', function () { zoom(-1); });
        function applySharp() {
            var on = sharpPref > 0;
            sharpMatrix.setAttribute('kernelMatrix', sharpKernel(sharpPref));
            comicBox.classList.toggle('rd-sharp', on);
            canvas.classList.toggle('rd-sharp', on);
            sharpBtn.classList.toggle('rd-sharp-off', !on);
            sharpRange.value = String(sharpPref);
            sharpValue.textContent = on ? String(sharpPref) : 'off';
        }
        function closeSharpPanel() {
            sharpPanel.hidden = true;
            sharpBtn.setAttribute('aria-expanded', 'false');
        }
        sharpBtn.addEventListener('click', function () {
            var open = sharpPanel.hidden;
            sharpPanel.hidden = !open;
            sharpBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) sharpRange.focus();
        });
        sharpRange.addEventListener('input', function () {
            sharpPref = sharpLevel(sharpRange.value);
            applySharp();
        });
        sharpRange.addEventListener('change', function () {
            try { store.setItem(SHARP_KEY, String(sharpPref)); } catch (e) { /* quota or private mode */ }
        });
        sharpRange.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { e.stopPropagation(); closeSharpPanel(); sharpBtn.focus(); }
        });
        function applyEpubStyles() {
            epubRange.value = String(epubSizePref);
            epubSizeValue.textContent = epubSizePref + ' %';
            epubThemeBtns.forEach(function (b) {
                b.setAttribute('aria-pressed', b.getAttribute('data-theme') === epubThemePref ? 'true' : 'false');
            });
            // The margins around the chapter frame take the page colour too.
            epubHost.style.background = EPUB_THEMES[epubThemePref] ? EPUB_THEMES[epubThemePref].bg : '';
            if (!cur || !cur.view) return;
            try { cur.view.renderer.setStyles(epubCss(epubThemePref, epubSizePref, cur.siteColors)); } catch (e) { /* default styles */ }
        }
        function closeEpubPanel() {
            epubPanel.hidden = true;
            epubBtn.setAttribute('aria-expanded', 'false');
        }
        epubBtn.addEventListener('click', function () {
            var open = epubPanel.hidden;
            epubPanel.hidden = !open;
            epubBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) epubRange.focus();
        });
        epubRange.addEventListener('input', function () {
            epubSizePref = epubSize(epubRange.value);
            applyEpubStyles();
        });
        epubRange.addEventListener('change', function () {
            try { store.setItem(EPUB_SIZE_KEY, String(epubSizePref)); } catch (e) { /* quota or private mode */ }
        });
        epubThemeBtns.forEach(function (b) {
            b.addEventListener('click', function () {
                epubThemePref = b.getAttribute('data-theme');
                applyEpubStyles();
                try { store.setItem(EPUB_THEME_KEY, epubThemePref); } catch (e) { /* quota or private mode */ }
            });
        });
        epubPanel.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { e.stopPropagation(); closeEpubPanel(); epubBtn.focus(); }
        });
        doc.addEventListener('pointerdown', function (e) {
            if (!sharpPanel.hidden && !sharpPanel.contains(e.target) && e.target !== sharpBtn) closeSharpPanel();
            if (!epubPanel.hidden && !epubPanel.contains(e.target) && e.target !== epubBtn) closeEpubPanel();
        });
        spreadBtn.addEventListener('click', function () {
            spreadPref = !spreadPref;
            try { store.setItem(SPREAD_KEY, spreadPref ? '1' : '0'); } catch (e) { /* quota or private mode */ }
            syncSpreadUi();
            if (pagedReady() && cur.kind === 'comic') goPdf(cur.page, false);
        });
        backBtn.addEventListener('click', function () {
            if (!exitUrl) { showLibrary(); return; }
            flush();
            win.location.assign(exitUrl);
        });
        pageInput.addEventListener('change', function () {
            if (cur && (cur.kind === 'pdf' || cur.kind === 'comic')) goPdf(pageInput.value, true);
        });
        doc.addEventListener('keydown', onKey);
        win.addEventListener('pagehide', flush);
        doc.addEventListener('visibilitychange', function () {
            if (doc.visibilityState === 'hidden') flush();
            // The browser drops the wake lock with a hidden tab.
            else if (doc.visibilityState === 'visible') holdWake();
        });
        win.addEventListener('pagehide', exitFs);
        // Fit-to-width follows the container; same page, same scroll position. EPUB reflows itself (foliate observes its host).
        var resizeTimer = null;
        function refitPdf() {
            var me = cur;
            if (me && me.kind === 'comic' && me.pages > 0) {
                syncSpreadUi();
                if (spreadWanted() !== !!me.spread) goPdf(me.page, false);
                else if (!comicBox.hidden && (stage.clientWidth !== me.fitW || fitHeight() !== me.fitH)) fitComic();
                return;
            }
            if (!me || me.kind !== 'pdf' || !me.doc) return;
            var w = stage.clientWidth;
            if (!w || me.fitW === undefined || (w === me.fitW && fitHeight() === me.fitH && (win.devicePixelRatio || 1) === me.fitDpr)) return;
            var fy = stage.scrollTop / Math.max(1, stage.scrollHeight);
            var fx = stage.scrollLeft / Math.max(1, stage.scrollWidth);
            var p = renderPdf();
            var id = me.renderId;
            p.then(function () {
                if (cur !== me || me.renderId !== id) return;
                stage.scrollTop = fy * stage.scrollHeight;
                stage.scrollLeft = fx * stage.scrollWidth;
            });
        }
        function scheduleRefit() {
            win.clearTimeout(resizeTimer);
            resizeTimer = win.setTimeout(refitPdf, REFIT_MS);
        }
        win.addEventListener('resize', scheduleRefit);
        win.addEventListener('orientationchange', scheduleRefit);
        if (typeof win.ResizeObserver === 'function') new win.ResizeObserver(scheduleRefit).observe(stage);

        renderLibrary();
        return { open: openBook, close: showLibrary, flush: flush };
    }

    // ─── Auto-mount on pages that carry the container ────────────────────────

    if (typeof document !== 'undefined') {
        var mount = function () {
            var rootEl = document.getElementById('asc-reader');
            var data = document.getElementById('asc-reader-data');
            if (!rootEl || !data) return;
            var books;
            try { books = JSON.parse(data.textContent); } catch (e) { return; }
            if (!Array.isArray(books)) return;
            books = books.filter(function (b) { return b && typeof b.name === 'string' && bookKind(b.name); });
            init({ root: rootEl, token: rootEl.getAttribute('data-token') || '', books: books });
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', mount);
        } else {
            mount();
        }
    }

    return {
        storageKey: storageKey,
        bookKind: bookKind,
        comicPageUrl: comicPageUrl,
        comicCountUrl: comicCountUrl,
        sortBooks: sortBooks,
        sortBooksBy: sortBooksBy,
        filterBooks: filterBooks,
        recentBooks: recentBooks,
        statusText: statusText,
        formatSize: formatSize,
        clampPage: clampPage,
        fractionOf: fractionOf,
        bookStatus: bookStatus,
        percent: percent,
        loadState: loadState,
        saveProgress: saveProgress,
        clearProgress: clearProgress,
        shouldSave: shouldSave,
        resumeTarget: resumeTarget,
        lastRead: lastRead,
        neighbor: neighbor,
        spreadFor: spreadFor,
        stepSpread: stepSpread,
        fitSpread: fitSpread,
        comicZoomStep: comicZoomStep,
        keyAction: keyAction,
        sharpLevel: sharpLevel,
        sharpKernel: sharpKernel,
        epubSize: epubSize,
        epubCss: epubCss,
        pinchZoom: pinchZoom,
        needsScriptSandbox: needsScriptSandbox,
        init: init
    };
}));
