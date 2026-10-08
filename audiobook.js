(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.AscAudiobook = api;
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var RATE_MIN = 0.5;
    var RATE_MAX = 3;
    var SLEEP_STEPS = [0, 15, 30, 60];
    var SAVE_EVERY_MS = 5000;

    // ─── Pure helpers ────────────────────────────────────────────────────────

    // Invalid or out-of-range input resets to 1. Rounding avoids float drift (0.1 steps).
    function clampRate(x) {
        var n = Number(x);
        if (!isFinite(n) || n < RATE_MIN || n > RATE_MAX) return 1;
        return Math.round(n * 10) / 10;
    }

    // One 0.1 step in the given direction, stopping at the bounds.
    function stepRate(rate, dir) {
        var next = Math.round((clampRate(rate) + (dir < 0 ? -0.1 : 0.1)) * 10) / 10;
        return Math.min(RATE_MAX, Math.max(RATE_MIN, next));
    }

    // Label = file name without extension, "_" as space. Never empty; no other shortening.
    function displayNames(names) {
        return names.map(function (n) {
            var label = n.replace(/\.[A-Za-z0-9]{1,5}$/, '').replace(/_/g, ' ').replace(/\s+/g, ' ').trim();
            return label || n;
        });
    }

    function progressKey(token) {
        return 'asc-ab:' + token;
    }

    function loadProgress(store, token) {
        try {
            var raw = store.getItem(progressKey(token));
            if (!raw) return null;
            var s = JSON.parse(raw);
            if (!s || typeof s !== 'object' || typeof s.track !== 'string' || !s.track) return null;
            var time = Number(s.time);
            if (!isFinite(time) || time < 0) time = 0;
            var rate = clampRate(s.rate);
            var listened = Array.isArray(s.listened)
                ? s.listened.filter(function (x) { return typeof x === 'string'; })
                : [];
            return { track: s.track, time: time, rate: rate, listened: listened, updated: Number(s.updated) || 0 };
        } catch (e) {
            return null;
        }
    }

    function saveProgress(store, token, state) {
        try {
            store.setItem(progressKey(token), JSON.stringify(state));
        } catch (e) { /* quota or private mode: playback still works */ }
    }

    function markListened(state, trackName) {
        var base = state || { track: trackName, time: 0, rate: 1, listened: [], updated: 0 };
        var listened = (base.listened || []).slice();
        if (listened.indexOf(trackName) === -1) listened.push(trackName);
        return {
            track: base.track,
            time: base.time,
            rate: base.rate,
            listened: listened,
            updated: base.updated
        };
    }

    function pad(n) { return n < 10 ? '0' + n : String(n); }

    function formatTime(sec) {
        if (!isFinite(sec) || sec < 0) sec = 0;
        sec = Math.floor(sec);
        var h = Math.floor(sec / 3600);
        var m = Math.floor((sec % 3600) / 60);
        var s = sec % 60;
        return h > 0 ? h + ':' + pad(m) + ':' + pad(s) : pad(m) + ':' + pad(s);
    }

    function formatRate(rate) {
        return rate.toFixed(1) + '\u00d7';
    }

    // ─── UI ──────────────────────────────────────────────────────────────────

    function el(tag, attrs, text) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
        if (text !== undefined) node.textContent = text;
        return node;
    }

    var SVG_OPEN = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';
    function icon(inner) { return SVG_OPEN + inner + '</svg>'; }
    var ICONS = {
        play: icon('<path d="M7 4.5v15l12-7.5z" fill="currentColor"/>'),
        pause: icon('<path d="M8 5v14M16 5v14"/>'),
        prev: icon('<path d="M19 5v14L9 12zM5 5v14"/>'),
        next: icon('<path d="M5 5v14l10-7zM19 5v14"/>'),
        back15: icon('<path d="M3 12a9 9 0 1 0 3-6.7M3 4v5h5"/><text x="12" y="15.5" text-anchor="middle" font-size="8" font-weight="700" fill="currentColor" stroke="none">15</text>'),
        fwd30: icon('<path d="M21 12a9 9 0 1 1-3-6.7M21 4v5h-5"/><text x="12" y="15.5" text-anchor="middle" font-size="8" font-weight="700" fill="currentColor" stroke="none">30</text>'),
        minus: icon('<path d="M5 12h14"/>'),
        plus: icon('<path d="M12 5v14M5 12h14"/>'),
        moon: icon('<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>'),
        check: icon('<path d="M5 12.5l4.5 4.5L19 7.5"/>'),
        download: icon('<path d="M12 4v12M7 11l5 5 5-5M5 20h14"/>')
    };

    function button(testid, label, svg, cls) {
        var b = el('button', { type: 'button', 'class': 'ab-btn' + (cls ? ' ' + cls : ''), 'data-testid': testid, 'aria-label': label });
        if (svg) b.innerHTML = svg;
        return b;
    }

    function defaultStorage() {
        try { return window.localStorage || null; } catch (e) { return null; }
    }

    function init(opts) {
        var rootEl = opts.root;
        var token = opts.token;
        var tracks = opts.tracks || [];
        var store = opts.storage !== undefined ? opts.storage : defaultStorage();
        if (!rootEl || !tracks.length) return null;
        var shareTitle = opts.title || rootEl.getAttribute('data-title') || document.title;
        var labels = displayNames(tracks.map(function (t) { return t.name; }));

        var saved = store ? loadProgress(store, token) : null;
        var savedIndex = -1;
        if (saved) {
            tracks.forEach(function (t, i) { if (t.name === saved.track) savedIndex = i; });
            if (savedIndex === -1) saved = null;
        }

        var state = {
            track: tracks[0].name,
            time: 0,
            rate: saved ? saved.rate : 1,
            listened: saved ? saved.listened.filter(function (n) {
                return tracks.some(function (t) { return t.name === n; });
            }) : [],
            updated: 0
        };
        var current = 0;
        var pendingSeek = null;
        var engaged = false;
        var seeking = false;
        var lastSave = 0;
        var sleepIndex = 0;
        var sleepTimer = null;
        var sleepTick = null;
        var sleepEnd = 0;

        // ── Build DOM
        rootEl.textContent = '';
        rootEl.classList.add('ab-player');

        var audio = el('audio', { 'data-testid': 'ab-audio', preload: 'metadata' });

        var banner = el('div', { 'data-testid': 'ab-resume-banner', 'class': 'ab-banner', role: 'status' });
        banner.hidden = true;
        var bannerText = el('span', { 'class': 'ab-banner-text' });
        var yesBtn = el('button', { type: 'button', 'class': 'ab-btn ab-btn-accent', 'data-testid': 'ab-resume-yes' }, 'Reprendre');
        var restartBtn = el('button', { type: 'button', 'class': 'ab-btn', 'data-testid': 'ab-resume-restart' }, 'Recommencer');
        var bannerBtns = el('span', { 'class': 'ab-banner-actions' });
        bannerBtns.appendChild(yesBtn);
        bannerBtns.appendChild(restartBtn);
        banner.appendChild(bannerText);
        banner.appendChild(bannerBtns);

        var checkTpl = el('span', { 'class': 'ab-check', 'aria-hidden': 'true' });
        checkTpl.innerHTML = ICONS.check;
        var dlTpl = el('a', { 'class': 'ab-track-dl' });
        dlTpl.innerHTML = ICONS.download;

        var list = el('ol', { 'class': 'ab-track-list', 'data-testid': 'ab-track-list' });
        var items = tracks.map(function (t, i) {
            var li = el('li', { 'class': 'ab-track', 'data-testid': 'ab-track', 'data-name': t.name, 'data-index': String(i), title: t.name });
            var pick = el('button', { type: 'button', 'class': 'ab-track-pick' });
            pick.appendChild(el('span', { 'class': 'ab-track-label' }, labels[i]));
            pick.appendChild(el('span', { 'class': 'ab-sr' }));
            var check = checkTpl.cloneNode(true);
            var dl = dlTpl.cloneNode(true);
            dl.setAttribute('href', t.url);
            dl.setAttribute('download', t.name);
            dl.setAttribute('aria-label', 'Télécharger ' + t.name);
            li.appendChild(pick);
            li.appendChild(check);
            li.appendChild(dl);
            list.appendChild(li);
            return li;
        });

        list.addEventListener('click', function (e) {
            var pick = e.target.closest ? e.target.closest('.ab-track-pick') : null;
            var li = pick && pick.parentNode;
            if (li && li.parentNode === list) selectTrack(Number(li.getAttribute('data-index')), true);
        });

        var dock = el('div', { 'class': 'ab-dock' });
        var errorBox = el('div', { 'class': 'ab-error', 'data-testid': 'ab-error', role: 'alert' });
        errorBox.hidden = true;
        var title = el('h2', { 'class': 'ab-title', 'data-testid': 'ab-title' });
        var count = el('span', { 'class': 'ab-count', 'data-testid': 'ab-count' });
        var head = el('div', { 'class': 'ab-head' });
        head.appendChild(title);
        head.appendChild(count);
        var live = el('div', { 'class': 'ab-sr', 'aria-live': 'polite' });
        var seek = el('input', {
            type: 'range', 'class': 'ab-seek', 'data-testid': 'ab-seek',
            min: '0', max: '0', step: '1', value: '0', 'aria-label': 'Position'
        });
        var timeEl = el('div', { 'class': 'ab-time', 'data-testid': 'ab-time' }, '00:00 / 00:00');

        var seekRow = el('div', { 'class': 'ab-seekrow' });
        seekRow.appendChild(seek);
        seekRow.appendChild(timeEl);

        var prevBtn = button('ab-prev', 'Piste précédente', ICONS.prev);
        var back15 = button('ab-back15', 'Reculer de 15 secondes', ICONS.back15);
        var playBtn = button('ab-play', 'Lecture', ICONS.play, 'ab-play');
        var fwd30 = button('ab-fwd30', 'Avancer de 30 secondes', ICONS.fwd30);
        var nextBtn = button('ab-next', 'Piste suivante', ICONS.next);
        var transport = el('div', { 'class': 'ab-transport' });
        [prevBtn, back15, playBtn, fwd30, nextBtn].forEach(function (b) { transport.appendChild(b); });

        var rateDown = button('ab-rate-down', 'Ralentir', ICONS.minus, 'ab-small');
        var rateBtn = el('button', { type: 'button', 'class': 'ab-btn ab-small ab-rate', 'data-testid': 'ab-rate' });
        var rateUp = button('ab-rate-up', 'Accélérer', ICONS.plus, 'ab-small');
        var rateGroup = el('div', { 'class': 'ab-rate-group' });
        [rateDown, rateBtn, rateUp].forEach(function (b) { rateGroup.appendChild(b); });
        var sleepBtn = el('button', { type: 'button', 'class': 'ab-btn ab-small ab-sleep', 'data-testid': 'ab-sleep' });
        var sleepIcon = el('span', { 'class': 'ab-sleep-icon', 'aria-hidden': 'true' });
        sleepIcon.innerHTML = ICONS.moon;
        var sleepText = el('span', { 'class': 'ab-sleep-text' });
        sleepBtn.appendChild(sleepIcon);
        sleepBtn.appendChild(sleepText);
        var options = el('div', { 'class': 'ab-options' });
        options.appendChild(rateGroup);
        options.appendChild(sleepBtn);

        [errorBox, head, seekRow, transport, options, live].forEach(function (n) { dock.appendChild(n); });
        [audio, banner, list, dock].forEach(function (n) { rootEl.appendChild(n); });

        // The list needs bottom space equal to the fixed dock, or the last chapter is hidden.
        function syncDock() { rootEl.style.setProperty('--ab-dock-h', dock.offsetHeight + 'px'); }
        if (typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(syncDock).observe(dock);
        } else {
            window.addEventListener('resize', syncDock);
        }
        syncDock();

        // ── State helpers
        function snapshot() {
            return {
                track: tracks[current].name,
                time: pendingSeek !== null ? pendingSeek : Math.floor(audio.currentTime || 0),
                rate: state.rate,
                listened: state.listened,
                updated: Date.now()
            };
        }

        function persist() {
            if (!store || !engaged) return;
            lastSave = Date.now();
            state = snapshot();
            saveProgress(store, token, state);
        }

        function renderList() {
            items.forEach(function (li, i) {
                var isCurrent = i === current;
                var isListened = state.listened.indexOf(tracks[i].name) !== -1;
                var pick = li.firstChild;
                li.classList.toggle('is-current', isCurrent);
                li.classList.toggle('is-listened', isListened);
                if (isCurrent) li.setAttribute('aria-current', 'true');
                else li.removeAttribute('aria-current');
                pick.lastChild.textContent = isListened ? ' (écouté)' : '';
            });
        }

        function renderTime() {
            var d = audio.duration;
            timeEl.textContent = formatTime(audio.currentTime) + ' / ' + formatTime(d);
            seek.setAttribute('aria-valuetext', formatTime(audio.currentTime) + ' sur ' + formatTime(d));
            if (!seeking) {
                seek.max = String(isFinite(d) ? Math.floor(d) : 0);
                seek.value = String(Math.floor(audio.currentTime || 0));
            }
        }

        function renderPlay() {
            var playing = !audio.paused;
            playBtn.innerHTML = playing ? ICONS.pause : ICONS.play;
            playBtn.setAttribute('aria-label', playing ? 'Pause' : 'Lecture');
        }

        function setDisabled(btn, off) {
            btn.disabled = off;
            btn.setAttribute('aria-disabled', off ? 'true' : 'false');
        }

        function renderRate() {
            var label = formatRate(state.rate);
            rateBtn.textContent = label;
            rateBtn.setAttribute('aria-label', 'Vitesse ' + label + ', remettre à 1×');
            setDisabled(rateDown, state.rate <= RATE_MIN);
            setDisabled(rateUp, state.rate >= RATE_MAX);
        }

        function renderSleep() {
            var m = SLEEP_STEPS[sleepIndex];
            var text = 'Veille : off';
            if (m) {
                var left = Math.max(0, Math.ceil((sleepEnd - Date.now()) / 1000));
                text = 'Veille : ' + (left < m * 60 ? formatTime(left) : m + ' min');
            }
            sleepText.textContent = text;
        }

        function applyRate() {
            audio.defaultPlaybackRate = state.rate;
            audio.playbackRate = state.rate;
        }

        function updatePosition() {
            if (!('mediaSession' in navigator) || !navigator.mediaSession.setPositionState) return;
            try {
                if (isFinite(audio.duration) && audio.duration > 0) {
                    navigator.mediaSession.setPositionState({
                        duration: audio.duration,
                        playbackRate: audio.playbackRate || 1,
                        position: Math.min(audio.currentTime || 0, audio.duration)
                    });
                }
            } catch (e) { /* unsupported values */ }
        }

        function updateMetadata() {
            if (!('mediaSession' in navigator) || typeof MediaMetadata === 'undefined') return;
            try {
                navigator.mediaSession.metadata = new MediaMetadata({
                    title: labels[current],
                    artist: shareTitle,
                    album: shareTitle
                });
            } catch (e) { /* ignore */ }
        }

        function showError(msg) {
            errorBox.textContent = msg;
            errorBox.hidden = !msg;
        }

        function scrollItem(li) {
            if (!li.scrollIntoView) return;
            var rect = li.getBoundingClientRect();
            var visibleBottom = window.innerHeight - dock.offsetHeight;
            if (rect.top < 0 || rect.bottom > visibleBottom) li.scrollIntoView({ block: 'center' });
        }

        // Shows a track in the dock without playing or saving anything.
        function loadTrack(i, startAt) {
            current = i;
            pendingSeek = startAt || null;
            showError('');
            audio.src = tracks[i].url;
            applyRate();
            title.textContent = labels[i];
            count.textContent = (i + 1) + '/' + tracks.length;
            seek.max = '0';
            seek.value = '0';
            renderList();
            renderTime();
            updateMetadata();
            scrollItem(items[i]);
        }

        function play() {
            var p = audio.play();
            if (p && p.catch) p.catch(function () { renderPlay(); });
        }

        function selectTrack(i, autoplay, startAt) {
            if (i < 0 || i >= tracks.length) return;
            engaged = true;
            banner.hidden = true;
            loadTrack(i, startAt);
            live.textContent = 'Piste ' + (i + 1) + ' sur ' + tracks.length + ' : ' + labels[i];
            if (autoplay) play();
            persist();
        }

        function toggle() {
            if (audio.paused) {
                engaged = true;
                banner.hidden = true;
                play();
            } else {
                audio.pause();
            }
        }

        function toggleTo(wantPlay) {
            if (wantPlay === audio.paused) toggle();
        }

        function skip(delta) {
            var d = audio.duration;
            var t = (audio.currentTime || 0) + delta;
            if (isFinite(d)) t = Math.min(t, d);
            audio.currentTime = Math.max(0, t);
        }

        function step(dir) {
            var i = current + dir;
            if (i < 0 || i >= tracks.length) return;
            selectTrack(i, !audio.paused);
        }

        function setRate(r) {
            state.rate = r;
            applyRate();
            renderRate();
            persist();
        }

        function stopSleepTimers() {
            if (sleepTimer) { clearTimeout(sleepTimer); sleepTimer = null; }
            if (sleepTick) { clearInterval(sleepTick); sleepTick = null; }
        }

        // Also checked on timeupdate: browsers throttle timers in background tabs.
        function sleepExpire() {
            stopSleepTimers();
            audio.pause();
            sleepIndex = 0;
            renderSleep();
        }

        function setSleep(index) {
            sleepIndex = index;
            stopSleepTimers();
            var m = SLEEP_STEPS[sleepIndex];
            if (m) {
                sleepEnd = Date.now() + m * 60 * 1000;
                sleepTimer = setTimeout(sleepExpire, m * 60 * 1000);
                sleepTick = setInterval(renderSleep, 1000);
            }
            renderSleep();
        }

        // ── Events
        audio.addEventListener('loadedmetadata', function () {
            applyRate();
            if (pendingSeek !== null) {
                try { audio.currentTime = pendingSeek; } catch (e) { /* ignore */ }
                pendingSeek = null;
            }
            renderTime();
            updatePosition();
        });
        audio.addEventListener('timeupdate', function () {
            renderTime();
            if (sleepIndex && Date.now() >= sleepEnd) sleepExpire();
            if (!audio.paused && Date.now() - lastSave >= SAVE_EVERY_MS) persist();
        });
        audio.addEventListener('play', function () { renderPlay(); updatePosition(); });
        audio.addEventListener('pause', function () { renderPlay(); persist(); });
        audio.addEventListener('seeked', updatePosition);
        audio.addEventListener('ratechange', updatePosition);
        audio.addEventListener('error', function () {
            showError('Piste illisible.');
            renderPlay();
        });
        audio.addEventListener('ended', function () {
            state = markListened(snapshot(), tracks[current].name);
            renderList();
            if (current < tracks.length - 1) {
                selectTrack(current + 1, true);
            } else {
                state = {
                    track: tracks[0].name,
                    time: 0,
                    rate: state.rate,
                    listened: tracks.map(function (t) { return t.name; }),
                    updated: Date.now()
                };
                if (store) saveProgress(store, token, state);
                loadTrack(0);
                engaged = false;
                renderPlay();
            }
        });

        seek.addEventListener('input', function () {
            seeking = true;
            timeEl.textContent = formatTime(Number(seek.value)) + ' / ' + formatTime(audio.duration);
        });
        seek.addEventListener('change', function () {
            seeking = false;
            audio.currentTime = Number(seek.value);
            persist();
        });

        playBtn.addEventListener('click', toggle);
        back15.addEventListener('click', function () { skip(-15); });
        fwd30.addEventListener('click', function () { skip(30); });
        prevBtn.addEventListener('click', function () { step(-1); });
        nextBtn.addEventListener('click', function () { step(1); });
        rateDown.addEventListener('click', function () { setRate(stepRate(state.rate, -1)); });
        rateUp.addEventListener('click', function () { setRate(stepRate(state.rate, 1)); });
        rateBtn.addEventListener('click', function () { setRate(1); });
        sleepBtn.addEventListener('click', function () {
            setSleep((sleepIndex + 1) % SLEEP_STEPS.length);
        });

        yesBtn.addEventListener('click', function () {
            toggleTo(true);
        });
        restartBtn.addEventListener('click', function () {
            state.listened = [];
            if (store) { try { store.removeItem(progressKey(token)); } catch (e) { /* ignore */ } }
            selectTrack(0, true);
        });

        function flush() { persist(); }
        window.addEventListener('pagehide', flush);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') flush();
        });

        // Keyboard shortcuts only where a keyboard is the main input.
        var desktop = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
        if (desktop) {
            document.addEventListener('keydown', function (e) {
                if (e.ctrlKey || e.metaKey || e.altKey) return;
                var t = e.target;
                var tag = t && t.tagName;
                if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (t && t.isContentEditable)) return;
                if (e.key === ' ') {
                    if (tag === 'BUTTON' || tag === 'A') return;
                    e.preventDefault();
                    toggle();
                } else if (e.key === 'ArrowLeft') {
                    e.preventDefault();
                    skip(-15);
                } else if (e.key === 'ArrowRight') {
                    e.preventDefault();
                    skip(30);
                }
            });
        }

        if ('mediaSession' in navigator) {
            var handlers = {
                play: function () { toggleTo(true); },
                pause: function () { toggleTo(false); },
                previoustrack: function () { step(-1); },
                nexttrack: function () { step(1); },
                seekbackward: function (d) { skip(-((d && d.seekOffset) || 15)); },
                seekforward: function (d) { skip((d && d.seekOffset) || 30); },
                seekto: function (d) {
                    if (d && typeof d.seekTime === 'number') audio.currentTime = d.seekTime;
                }
            };
            Object.keys(handlers).forEach(function (action) {
                try { navigator.mediaSession.setActionHandler(action, handlers[action]); } catch (e) { /* unsupported action */ }
            });
        }

        // ── Initial render: saved track is loaded in the dock, paused and not saved again
        loadTrack(saved ? savedIndex : 0, saved ? saved.time : 0);
        renderRate();
        renderSleep();
        renderPlay();

        if (saved && (savedIndex > 0 || saved.time > 0)) {
            bannerText.textContent = 'Reprendre à ' + labels[savedIndex] + ' \u2013 ' + formatTime(saved.time);
            banner.hidden = false;
        }

        return { audio: audio, select: selectTrack };
    }

    // ─── Auto-mount on pages that carry the container ────────────────────────

    if (typeof document !== 'undefined') {
        var mount = function () {
            var rootEl = document.getElementById('asc-audiobook');
            var data = document.getElementById('asc-audiobook-data');
            if (!rootEl || !data) return;
            var tracks;
            try { tracks = JSON.parse(data.textContent); } catch (e) { return; }
            init({ root: rootEl, token: rootEl.getAttribute('data-token') || '', tracks: tracks });
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', mount);
        } else {
            mount();
        }
    }

    return {
        progressKey: progressKey,
        loadProgress: loadProgress,
        saveProgress: saveProgress,
        markListened: markListened,
        formatTime: formatTime,
        clampRate: clampRate,
        stepRate: stepRate,
        displayNames: displayNames,
        init: init
    };
}));
