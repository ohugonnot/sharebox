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
    var PULL_PX = 40;
    var ZONE_SIDE = 0.35;
    var CLICK_SLOP_PX = 10;
    var EPUB_UNAVAILABLE = 'EPUB indisponible : le lecteur ne peut pas afficher ce livre ici.';

    // ─── Pure helpers ────────────────────────────────────────────────────────

    function storageKey(token) {
        return 'asc-rd:' + token;
    }

    function bookKind(name) {
        var m = /\.([A-Za-z0-9]+)$/.exec(String(name));
        var ext = m ? m[1].toLowerCase() : '';
        return ext === 'pdf' || ext === 'epub' ? ext : null;
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

    function statusText(status, pct) {
        if (status === 'done') return 'Terminé';
        if (status === 'reading') return pct + ' %';
        return 'Nouveau';
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
        var byName = {};
        books.forEach(function (b) { byName[b.name] = b; });

        // Without storage an in-memory stub keeps the same code path alive.
        var mem = {};
        var store = storage || {
            getItem: function (k) { return Object.prototype.hasOwnProperty.call(mem, k) ? mem[k] : null; },
            setItem: function (k, v) { mem[k] = v; }
        };
        function readState() { return storage ? loadState(storage, token) : emptyState(); }

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
        var backBtn = button(doc, 'Retour à la bibliothèque', '', 'rd-back');
        backBtn.classList.add('rd-back-btn');
        backBtn.appendChild(doc.createTextNode('← '));
        backBtn.appendChild(el(doc, 'span', 'rd-btn-label')).textContent = 'Bibliothèque';
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
        [backBtn, titleEl, prevBtn, pageBox, nextBtn, zoomBox, fsBtn, progressEl].forEach(function (n) { bar.appendChild(n); });

        var banner = tid(el(doc, 'div', 'rd-banner', { role: 'status', 'aria-label': 'Reprise de lecture' }), 'rd-resume-banner');
        banner.hidden = true;
        var bannerText = el(doc, 'span', 'rd-banner-text');
        var resumeYes = button(doc, 'Reprendre la lecture', 'Reprendre', 'rd-resume-yes');
        var resumeNo = button(doc, 'Recommencer depuis le début', 'Recommencer', 'rd-resume-restart');
        banner.appendChild(bannerText);
        banner.appendChild(resumeYes);
        banner.appendChild(resumeNo);

        var stage = el(doc, 'div', 'rd-stage');
        var canvas = tid(el(doc, 'canvas', 'rd-canvas', { role: 'img', 'aria-label': 'Page du document' }), 'rd-canvas');
        var epubHost = tid(el(doc, 'div', 'rd-epub-host'), 'rd-epub-host');
        var loadingEl = tid(el(doc, 'div', 'rd-loading', { role: 'status', 'aria-live': 'polite' }), 'rd-loading');
        loadingEl.hidden = true;
        loadingEl.appendChild(el(doc, 'span', 'rd-spinner', { 'aria-hidden': 'true' }));
        var loadingText = loadingEl.appendChild(el(doc, 'span', 'rd-loading-text'));
        stage.appendChild(canvas);
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
                statusEl.textContent = statusText(status, pct);
                open.appendChild(kindEl);
                open.appendChild(nameEl);
                open.appendChild(sizeEl);
                open.appendChild(dateEl);
                open.appendChild(statusEl);
                open.addEventListener('click', function () { openBook(b.name); });
                var dl = el(doc, 'a', 'rd-book-dl', { href: b.url, download: '', 'aria-label': 'Télécharger ' + b.name });
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
            pageInput.value = String(cur.page);
            pageInput.max = String(cur.pages);
            pageCount.textContent = '/ ' + cur.pages;
            prevBtn.disabled = cur.page <= 1;
            nextBtn.disabled = cur.page >= cur.pages;
            zoomOut.disabled = cur.zoom <= ZOOM_MIN;
            zoomIn.disabled = cur.zoom >= ZOOM_MAX;
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
        function stagePad() { return viewer.classList.contains('rd-fs') ? 0 : 2; }

        function fitHeight() {
            if (viewer.classList.contains('rd-fs')) return Math.max(200, stage.clientHeight);
            var rem = parseFloat(win.getComputedStyle(doc.documentElement).fontSize) || 16;
            return Math.max(300, win.innerHeight - 11 * rem - 2);
        }

        async function renderPdf() {
            var me = cur;
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

        function goPdf(n, fromUser) {
            if (!cur || cur.kind !== 'pdf' || !cur.doc) return;
            if (fromUser) dismissBanner();
            cur.page = clampPage(n, cur.pages);
            if (cur.pre && cur.pre.n !== cur.page) cur.pre.dropped = true;
            pdfPageLabel();
            var f = fractionOf(cur.page, cur.pages);
            setProgressText(f);
            stage.scrollTop = 0;
            lastScrollTop = 0;
            // Opening at page 1 without moving must not create a "reading" entry.
            if (cur.page > 1 || readState().files[cur.name]) persist({ page: cur.page, pages: cur.pages, fraction: f });
            renderPdf();
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
                var bg = cs.getPropertyValue('--as-surface') || '#fff';
                var fg = cs.getPropertyValue('--as-text') || '#000';
                var ac = cs.getPropertyValue('--as-accent') || '#06c';
                view.renderer.setStyles('html,body{background:' + bg + ' !important;color:' + fg + ' !important}a:link,a:visited{color:' + ac + ' !important}');
            } catch (e) { /* default colors */ }
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
                    attachReveal(d);
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
                renderId: 0, page: 1, pages: 0, zoom: 1, task: null, doc: null, view: null
            };
            cur = me;
            var mySeq = ++seq;

            var target = resumeTarget(readState(), name);
            // 0 % (after "Recommencer") has nothing to resume, for PDF and EPUB alike.
            if (target && target.percent === 0) target = null;
            // Opening at the start must not overwrite the saved spot before the reader chooses.
            me.hold = !!target;
            titleEl.textContent = name.replace(/\.[A-Za-z0-9]+$/, '');
            var isPdf = b.kind === 'pdf';
            canvas.hidden = true;
            epubHost.hidden = isPdf;
            stage.classList.toggle('rd-stage-epub', !isPdf);
            pageBox.hidden = !isPdf;
            zoomBox.hidden = !isPdf;
            progressEl.textContent = '';
            library.hidden = true;
            viewer.hidden = false;
            banner.hidden = true;

            try {
                if (isPdf) await openPdf(me, b.url);
                else await openEpub(me, b.url);
            } catch (e) {
                if (cur !== me || seq !== mySeq) return;
                showLibrary();
                showError(e && e.oldBrowser
                    ? 'Ce navigateur est trop ancien pour la liseuse PDF. Télécharger le fichier.'
                    : isPdf
                    ? 'PDF indisponible : le fichier n’a pas pu être chargé.'
                    : EPUB_UNAVAILABLE);
                return;
            }
            if (cur !== me) return;
            backBtn.focus();

            if (target) {
                bannerText.textContent = 'Reprendre à ' + target.percent + ' % ?';
                banner.hidden = false;
                resumeYes.onclick = function () {
                    dismissBanner();
                    if (isPdf) goPdf(target.page || 1, false);
                    else if (target.cfi && me.view) me.view.goTo(target.cfi).catch(function () { /* stale location */ });
                };
                resumeNo.onclick = function () {
                    dismissBanner();
                    if (pendingTimer) { win.clearTimeout(pendingTimer); pendingTimer = null; }
                    pending = null;
                    clearProgress(store, token, name);
                    renderLibrary();
                    if (isPdf) goPdf(1, false);
                };
            }
        }

        // ── Controls
        function step(dir) {
            if (!cur) return;
            if (cur.kind === 'pdf') {
                if (cur.doc && clampPage(cur.page + dir, cur.pages) !== cur.page) goPdf(cur.page + dir, true);
            }
            else if (cur.view) {
                dismissBanner();
                (dir < 0 ? cur.view.prev() : cur.view.next()).catch(function () { /* book edge */ });
            }
        }

        function zoom(dir) {
            if (!cur || cur.kind !== 'pdf' || !cur.doc) return;
            var z = Math.round((cur.zoom + dir * ZOOM_STEP) * 100) / 100;
            cur.zoom = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, z));
            pdfPageLabel();
            renderPdf();
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
            if (e.key === ' ' || e.key === 'Spacebar') {
                if (tag === 'BUTTON' || tag === 'A') return;
                e.preventDefault();
                step(e.shiftKey ? -1 : 1);
                return;
            }
            if (e.shiftKey) return;
            if (e.key === 'ArrowLeft' || e.key === 'PageUp') { e.preventDefault(); step(-1); }
            else if (e.key === 'ArrowRight' || e.key === 'PageDown') { e.preventDefault(); step(1); }
            else if (e.key === 'Home' || e.key === 'End') { e.preventDefault(); jumpEnd(e.key === 'End'); }
            else if (e.key === 'f') { e.preventDefault(); toggleFs(); }
        }

        function jumpEnd(last) {
            if (!cur) return;
            if (cur.kind === 'pdf') {
                if (cur.doc && cur.page !== (last ? cur.pages : 1)) goPdf(last ? cur.pages : 1, true);
            } else if (cur.view) {
                dismissBanner();
                try { cur.view.goToFraction(last ? 1 : 0).catch(function () { /* edge */ }); } catch (e) { /* no api */ }
            }
        }

        // ── Fullscreen: a fixed overlay (Chrome's own native exit cross would sit on top of the bar).
        var pseudo = false;
        var savedOverflow = null;
        var savedViewport = null;
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
        function setViewportCover(on) {
            var meta = doc.querySelector('meta[name="viewport"]');
            if (!meta) return;
            if (on && savedViewport === null) {
                savedViewport = meta.getAttribute('content') || '';
                if (!/viewport-fit/.test(savedViewport)) meta.setAttribute('content', savedViewport + ', viewport-fit=cover');
            } else if (!on && savedViewport !== null) {
                meta.setAttribute('content', savedViewport);
                savedViewport = null;
            }
        }

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
            if (cur && cur.kind === 'pdf' && !canvas.hidden) {
                var cr = canvas.getBoundingClientRect();
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
            } else if (!on) {
                if (hideTimer) { win.clearTimeout(hideTimer); hideTimer = null; }
                viewer.classList.remove('rd-bar-hidden');
            }
            wasFs = on;
            scheduleRefit();
        }

        fsBtn.addEventListener('click', toggleFs);
        handle.addEventListener('click', function () {
            revealBar();
            fsBtn.focus();
        });
        bar.addEventListener('focusin', revealBar);

        // Reveal gestures: scroll or wheel up, swipe down, mouse near the top edge.
        var lastScrollTop = 0;
        var pull = null;
        function attachReveal(node) {
            node.addEventListener('wheel', function (e) { if (e.deltaY < 0) revealBar(); }, { passive: true });
            node.addEventListener('touchstart', function (e) {
                pull = e.touches.length === 1 ? { x: e.touches[0].clientX, y: e.touches[0].clientY } : null;
            }, { passive: true });
            node.addEventListener('touchmove', function (e) {
                if (!pull || e.touches.length !== 1) { pull = null; return; }
                var dy = e.touches[0].clientY - pull.y;
                var dx = e.touches[0].clientX - pull.x;
                if (dy > PULL_PX && dy > Math.abs(dx) * 1.5) { pull = null; revealBar(); }
            }, { passive: true });
        }
        attachReveal(viewer);
        stage.addEventListener('scroll', function () {
            if (stage.scrollTop < lastScrollTop - 2) revealBar();
            lastScrollTop = stage.scrollTop;
            placeHandle();
        });

        // Side thirds turn pages, the middle toggles the bar in fullscreen. Mouse and touch both end in `click`; swipes never do.
        var downAt = null;
        function zoneOf(e) {
            var r = stage.getBoundingClientRect();
            var f = (e.clientX - r.left) / Math.max(1, r.width);
            return f < ZONE_SIDE ? -1 : (f > 1 - ZONE_SIDE ? 1 : 0);
        }
        function zoneActive() { return !!cur && cur.kind === 'pdf' && !!cur.doc && cur.zoom <= 1; }
        stage.addEventListener('pointerdown', function (e) { downAt = { x: e.clientX, y: e.clientY }; });
        stage.addEventListener('pointermove', function (e) {
            if (e.pointerType !== 'mouse') return;
            stage.style.cursor = zoneActive() && zoneOf(e) ? 'pointer' : '';
        });
        stage.addEventListener('click', function (e) {
            if (!zoneActive()) return;
            if (e.target && e.target.closest && e.target.closest('input,textarea,select,button,a,label')) return;
            if (downAt && Math.hypot(e.clientX - downAt.x, e.clientY - downAt.y) > CLICK_SLOP_PX) return;
            var sel = win.getSelection && win.getSelection();
            if (sel && String(sel).length && sel.anchorNode && stage.contains(sel.anchorNode)) return;
            var z = zoneOf(e);
            if (z) step(z);
            else if (isFs()) {
                if (viewer.classList.contains('rd-bar-hidden')) revealBar(); else hideBar();
            }
        });

        // Horizontal swipe turns PDF pages; EPUB swipes are handled by foliate itself.
        var swipe = null;
        stage.addEventListener('touchstart', function (e) {
            if (e.touches.length > 1) { if (swipe) swipe.dead = true; return; }
            var t = e.touches[0];
            var tag = e.target && e.target.closest ? e.target.closest('input,textarea,select,button,a') : null;
            swipe = { x: t.clientX, y: t.clientY, dead: !!tag };
        }, { passive: true });
        stage.addEventListener('touchcancel', function () { swipe = null; });
        stage.addEventListener('touchend', function (e) {
            var s = swipe;
            if (e.touches.length > 0) { if (s) s.dead = true; return; }
            swipe = null;
            if (!s || s.dead || !cur || cur.kind !== 'pdf' || !cur.doc || cur.zoom > 1) return;
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
        backBtn.addEventListener('click', showLibrary);
        pageInput.addEventListener('change', function () {
            if (cur && cur.kind === 'pdf') goPdf(pageInput.value, true);
        });
        doc.addEventListener('keydown', onKey);
        win.addEventListener('pagehide', flush);
        doc.addEventListener('visibilitychange', function () {
            if (doc.visibilityState === 'hidden') flush();
        });
        win.addEventListener('pagehide', exitFs);
        // Fit-to-width follows the container; same page, same scroll position. EPUB reflows itself (foliate observes its host).
        var resizeTimer = null;
        function refitPdf() {
            var me = cur;
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
                lastScrollTop = stage.scrollTop;
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
        sortBooks: sortBooks,
        sortBooksBy: sortBooksBy,
        filterBooks: filterBooks,
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
        needsScriptSandbox: needsScriptSandbox,
        init: init
    };
}));
