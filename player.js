var REMUX_ENABLED = PLAYER_CONFIG.remuxEnabled;
function plog(tag, msg, data) {
    var ts = new Date().toLocaleTimeString('fr-FR', {hour:'2-digit',minute:'2-digit',second:'2-digit',fractionalSecondDigits:1});
    if (data !== undefined) console.log('%c[' + ts + '] %c' + tag + '%c ' + msg, 'color:#888', 'color:#f0a030;font-weight:bold', 'color:inherit', data);
    else console.log('%c[' + ts + '] %c' + tag + '%c ' + msg, 'color:#888', 'color:#f0a030;font-weight:bold', 'color:inherit');
}
(function() {
    // ── DOM ──────────────────────────────────────────────────────────────────
    var player      = document.getElementById('player');
    var hintWrap    = document.getElementById('hint');
    var hintText    = hintWrap.querySelector('.player-hint-text');
    var hint = {
        get textContent() { return hintText.textContent; },
        set textContent(v) { hintText.textContent = v; },
        get className()    { return hintWrap.className; },
        set className(v)   { hintWrap.className = v; }
    };
    var ctrlRow     = document.getElementById('ctrl-row');
    var playBtn     = document.getElementById('play-btn');
    var muteBtn     = document.getElementById('mute-btn');
    var volSlider   = document.getElementById('vol-slider');
    var speedBtn    = document.getElementById('speed-btn');
    var fsBtn       = document.getElementById('fs-btn');
    var zoomBtn     = document.getElementById('zoom-btn');
    var pipBtn      = document.getElementById('pip-btn');
    var modeBtn     = null;
    var seekBar     = document.getElementById('seek-bar');
    var seekFill    = document.getElementById('seek-fill');
    var seekThumb   = document.getElementById('seek-thumb');
    var seekBuffered= document.getElementById('seek-buffered');
    var seekTooltip = document.getElementById('seek-tooltip');
    var timeCurrent = document.getElementById('time-current');
    var timeTotal   = document.getElementById('time-total');
    var trackBar    = document.getElementById('track-bar');
    var playerCard  = player.closest('.player-card') || player.parentNode;
    var playerCtrl  = document.querySelector('.player-controls');
    var isVideo     = PLAYER_CONFIG.isVideo;
    var base        = PLAYER_CONFIG.baseUrl;
    var pp          = PLAYER_CONFIG.pp;
    var episodeNav  = PLAYER_CONFIG.episodeNav;
    var watchPath   = PLAYER_CONFIG.watchPath  || null;
    var watchCsrf   = PLAYER_CONFIG.watchCsrf  || null;
    var watchMarked = false;

    // ── Icônes SVG ───────────────────────────────────────────────────────────
    var svgPlay   = '<svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>';
    var svgPause  = '<svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>';
    var svgVol    = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>';
    var svgMute   = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>';
    var svgFs     = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>';
    var svgFsExit = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3v3a2 2 0 0 1-2 2H3m18 0h-3a2 2 0 0 1-2-2V3m0 18v-3a2 2 0 0 1 2-2h3M3 16h3a2 2 0 0 1 2 2v3"/></svg>';
    var svgPip    = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><rect x="12" y="9" width="8" height="6" rx="1" fill="currentColor" opacity=".3"/></svg>';

    // ── Click handlers épisodes ─────────────────────────────────────────────
    document.querySelectorAll('.ep-prev').forEach(function(btn) { btn.addEventListener('click', function(e) { e.preventDefault(); navigateEpisode('prev'); }); });
    document.querySelectorAll('.ep-next').forEach(function(btn) { btn.addEventListener('click', function(e) { e.preventDefault(); navigateEpisode('next'); }); });

    // ── Zoom vidéo ──────────────────────────────────────────────────────────
    var zoomModes  = ['contain', 'cover', 'fill'];
    var zoomLabels = ['Fit', 'Fill', 'Stretch'];
    var zoomIndex  = 0;

    // ── État partagé ──────────────────────────────────────────────────────────
    var S = {
        step: 'native', confirmed: '',   // machine d'état stream
        offset: 0,      duration: 0,     // position et durée totale
        audioIdx: 0,    quality: 720,    filter: 'none',  burnSub: -1,  isMP4: false,  isMKV: false,
        speed: 1,
        dragging: false, seekPending: false, rafPending: false,
        hasFailed: false, stallCount: 0,
        videoHeight: 0, seekGen: 0,
        // timers
        fsHideTimer: null, videoWidthTimer: null,
        seekDebounce: null, stallTimer: null, stallInterval: null
    };

    // ── Utilitaires ───────────────────────────────────────────────────────────
    function fmtTime(s) {
        s = Math.max(0, Math.floor(s));
        var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
        if (h > 0) return h + ':' + (m < 10 ? '0' : '') + m + ':' + (sec < 10 ? '0' : '') + sec;
        return m + ':' + (sec < 10 ? '0' : '') + sec;
    }
    function realTime() { return S.offset + (player.currentTime || 0); }
    // Safari iOS : utiliser HLS au lieu de fMP4 (Safari coupe les streams fMP4 sans Range support)
    // Détection iPad Desktop Mode : platform peut être "MacIntel" ou futur "macOS" → /^Mac/
    var isIOS = (/iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream)
             || (/^Mac/.test(navigator.platform || '') && navigator.maxTouchPoints > 1);
    var isSafari = /Safari/.test(navigator.userAgent) && !/Chrome/.test(navigator.userAgent);
    var useHLS = isIOS;
    function buildUrl(mode, audio, startSec) {
        if (mode === 'native') return base + '?' + pp + 'stream=1';
        // Sur Safari iOS, remplacer transcode par HLS
        var streamMode = (mode === 'transcode' && useHLS) ? 'hls' : mode;
        var url = base + '?' + pp + 'stream=' + streamMode + '&audio=' + (audio || 0);
        if (mode === 'transcode' || streamMode === 'hls') {
            url += '&quality=' + S.quality;
            if (S.filter && S.filter !== 'none') url += '&filter=' + S.filter;
            if (S.burnSub >= 0) url += '&burnSub=' + S.burnSub;
        }
        if (startSec > 0) url += '&start=' + startSec.toFixed(1);
        return url;
    }

    // ── Position et config mémorisées ───────────────────────────────────────
    var posKey   = 'player_seek_' + base + pp;
    var cfgKey   = 'player_cfg_'  + base + pp;
    var rawPos = parseFloat(lsGet(posKey, '0'));
    var savedPos = isVideo && isFinite(rawPos) && rawPos >= 0 ? rawPos : 0;
    var savedCfg = (function() { try { return JSON.parse(lsGet(cfgKey, 'null')); } catch(e) { return null; } })();
    function saveCfg() {
        lsSet(cfgKey, JSON.stringify({
            mode: S.confirmed || '',
            audio: S.audioIdx,
            quality: S.quality,
            filter: S.filter,
            burnSub: S.burnSub
        }));
    }
    function clearCfg() { lsSet(cfgKey, 'null'); }
    // ── Navigation épisodes ─────────────────────────────────────────────────
    function transferCfgTo(targetPp) {
        lsSet('player_cfg_' + base + targetPp, JSON.stringify({
            mode: S.confirmed || '',
            audio: S.audioIdx,
            quality: S.quality,
            burnSub: S.burnSub
        }));
        // Ne PAS set player_seek_ — on ne veut pas de faux resume
        // Transférer la piste sous-titre sélectionnée
        var curSub = lsGet('player_sub_' + base + pp, '');
        if (curSub) lsSet('player_sub_' + base + targetPp, curSub);
    }
    function navigateEpisode(direction) {
        var ep = direction === 'next' ? episodeNav.next : episodeNav.prev;
        if (!ep) return;
        plog('NAV', direction + ' → ' + ep.name + ' | mode=' + (S.confirmed||S.step) + ' audio=' + S.audioIdx + ' quality=' + S.quality);
        transferCfgTo(ep.pp);
        window.location.href = ep.url;
    }
    var originalTitle = document.title;
    function updateTitle() {
        if (!isVideo || S.duration <= 0) return;
        var icon = player.paused ? '\u23F8' : '\u25B6';
        document.title = icon + ' ' + fmtTime(realTime()) + ' / ' + fmtTime(S.duration) + ' \u2014 ' + originalTitle;
    }

    // ── Plein écran ───────────────────────────────────────────────────────────
    function isFs() { return !!(document.fullscreenElement || document.webkitFullscreenElement || (isIOS && player.webkitDisplayingFullscreen)); }
    function isLandscapeMobile() { return window.innerHeight <= 500 && window.innerWidth > window.innerHeight; }
    function isImmersive() { return isFs() || isLandscapeMobile(); }
    function toggleFs() {
        if (isIOS && player.webkitEnterFullscreen) {
            if (player.webkitDisplayingFullscreen) player.webkitExitFullscreen();
            else player.webkitEnterFullscreen();
            return;
        }
        if (!isFs()) (playerCard.requestFullscreen || playerCard.webkitRequestFullscreen || function(){}).call(playerCard);
        else (document.exitFullscreen || document.webkitExitFullscreen || function(){}).call(document);
    }
    var fsTitle = document.getElementById('fs-title');
    function showFsControls() {
        playerCtrl.classList.remove('fs-hidden');
        if (fsTitle) fsTitle.classList.remove('fs-hidden');
        playerCard.classList.remove('hide-cursor');
        clearTimeout(S.fsHideTimer);
        if (isImmersive() && !player.paused) S.fsHideTimer = setTimeout(function() { playerCtrl.classList.add('fs-hidden'); if (fsTitle) fsTitle.classList.add('fs-hidden'); playerCard.classList.add('hide-cursor'); }, 3000);
    }
    function onFsChange() {
        if (fsBtn) fsBtn.innerHTML = isFs() ? svgFsExit : svgFs;  // safe: static SVG constants
        if (isFs()) { player.style.height = ''; showFsControls(); }
        else { clearTimeout(S.fsHideTimer); playerCtrl.classList.remove('fs-hidden'); playerCard.classList.remove('hide-cursor'); applyZoom(); }
    }
    document.addEventListener('fullscreenchange',        onFsChange);
    document.addEventListener('webkitfullscreenchange',  onFsChange);
    player.addEventListener('webkitbeginfullscreen',     onFsChange);
    player.addEventListener('webkitendfullscreen',       onFsChange);
    playerCard.addEventListener('mousemove',  function() { if (isImmersive()) showFsControls(); });
    playerCard.addEventListener('click',      function() { if (isImmersive()) showFsControls(); });
    playerCard.addEventListener('touchstart', function() { if (isImmersive()) showFsControls(); }, {passive:true});
    player.addEventListener('pause', function() { clearTimeout(S.fsHideTimer); playerCtrl.classList.remove('fs-hidden'); if (fsTitle) fsTitle.classList.remove('fs-hidden'); playerCard.classList.remove('hide-cursor'); });

    // Paysage mobile : auto-hide controles comme en fullscreen
    window.addEventListener('resize', function() {
        if (isLandscapeMobile() && !player.paused) showFsControls();
        if (!isLandscapeMobile() && !isFs()) { clearTimeout(S.fsHideTimer); playerCtrl.classList.remove('fs-hidden'); if (fsTitle) fsTitle.classList.remove('fs-hidden'); playerCard.classList.remove('hide-cursor'); }
    });

    // ── localStorage (try/catch : private browsing peut throw) ───────────────
    function lsGet(k, def) { try { var v = localStorage.getItem(k); return v !== null ? v : def; } catch(e) { return def; } }
    function lsSet(k, v)   { try { localStorage.setItem(k, v); } catch(e) {} }

    // ── Zoom ─────────────────────────────────────────────────────────────────
    function applyZoom() {
        player.style.objectFit = zoomModes[zoomIndex];
        // En mode windowed, fixer la hauteur pour que cover/fill aient un effet
        if (!isFs()) {
            if (zoomIndex === 0) { player.style.height = ''; }
            else if (player.videoWidth > 0) { player.style.height = player.offsetHeight + 'px'; }
        }
    }
    function toggleZoom() {
        zoomIndex = (zoomIndex + 1) % zoomModes.length;
        // En windowed, relâcher la hauteur avant de la re-fixer pour recalculer
        if (!isFs() && zoomIndex === 0) player.style.height = '';
        applyZoom();
        lsSet('asc_zoom', zoomModes[zoomIndex]);
        volOsd.textContent = '\uD83D\uDD0D Zoom: ' + zoomLabels[zoomIndex];
        volOsd.classList.add('visible');
        clearTimeout(volOsdTimer);
        volOsdTimer = setTimeout(function() { volOsd.classList.remove('visible'); }, 1200);
    }
    (function initZoom() {
        var saved = lsGet('asc_zoom', 'contain');
        var idx = zoomModes.indexOf(saved);
        if (idx !== -1) zoomIndex = idx;
        // Appliquer après le chargement de la vidéo pour avoir les dimensions
        if (idx > 0) {
            player.addEventListener('loadedmetadata', function onMeta() {
                player.removeEventListener('loadedmetadata', onMeta);
                applyZoom();
            });
        }
        player.style.objectFit = zoomModes[zoomIndex];
    })();

    // ── Stream ────────────────────────────────────────────────────────────────
    function lockSize()   { if (isVideo && player.videoWidth > 0) player.style.minHeight = player.offsetHeight + 'px'; }
    function unlockSize() { player.style.minHeight = ''; }

    function startStream(resumeAt) {
        var mode = S.confirmed || S.step;
        plog('STREAM', 'startStream mode=' + mode + ' resumeAt=' + (resumeAt || 0).toFixed(1) + ' audio=' + S.audioIdx + ' quality=' + S.quality + ' burnSub=' + S.burnSub);
        watchMarked = false;
        // En mode natif, le navigateur gère le seek via player.currentTime (pas de &start= dans l'URL)
        if (mode === 'native' && resumeAt > 0) {
            plog('STREAM', 'native seek via currentTime → ' + resumeAt.toFixed(1));
            S.offset = 0;
            S.hasFailed = false;
            clearTimeout(S.videoWidthTimer);
            Subs.resetIdx();
            lockSize();
            updateModeUI();
            player.src = isVideo ? buildUrl(mode, S.audioIdx, 0) : base + '?' + pp + 'stream=1';
            player.load();
            player.playbackRate = S.speed;
            var seekTarget = resumeAt;
            player.addEventListener('loadedmetadata', function onMeta() {
                player.removeEventListener('loadedmetadata', onMeta);
                player.currentTime = seekTarget;
            });
            player.play().catch(function(e) { if (e && e.name === 'NotAllowedError') hint.textContent = 'Appuyer sur \u25B6 pour lire'; });
            return;
        }
        S.offset    = resumeAt || 0;
        S.hasFailed = false;
        clearTimeout(S.videoWidthTimer);
        Subs.resetIdx();
        lockSize();
        updateModeUI();
        player.src = isVideo ? buildUrl(mode, S.audioIdx, S.offset) : base + '?' + pp + 'stream=1';
        player.load();
        player.playbackRate = S.speed;
        player.play().catch(function(e) { if (e && e.name === 'NotAllowedError') hint.textContent = 'Appuyer sur \u25B6 pour lire'; });
    }

    // Choisit le mode optimal à partir du probe (avant de démarrer le stream)
    function canPlay(mime) { var t = document.createElement('video').canPlayType(mime); return t === 'probably' || t === 'maybe'; }

    function chooseModeFromProbe(d) {
        var _r = _chooseModeFromProbe(d);
        plog('PROBE', 'chooseModeFromProbe → ' + _r + ' (codec=' + (d && d.videoCodec || '?') + ' isMP4=' + (d && d.isMP4) + ' isMKV=' + (d && d.isMKV) + ')');
        return _r;
    }
    function _chooseModeFromProbe(d) {
        if (!d || !d.videoCodec) return 'native';
        var c  = d.videoCodec.toLowerCase();
        var ac = d.audio && d.audio.length > 0 ? (d.audio[0].codec || '').toLowerCase() : '';
        var nativeAudio = (ac === 'aac' || ac === 'mp3' || ac === 'opus' || ac === 'vorbis');
        if (c === 'h264') {
            if (d.isMP4 && nativeAudio) return 'native';
            return REMUX_ENABLED ? 'remux' : 'transcode';
        }
        if (c === 'vp9' || c === 'vp8') {
            if (d.isMKV && nativeAudio && canPlay('video/webm; codecs="vp9"')) return 'native';
            return 'transcode';
        }
        if (c === 'av1' || c === 'av01') {
            if (nativeAudio && (canPlay('video/webm; codecs="av01.0.05M.08"') || canPlay('video/mp4; codecs="av01"'))) return 'native';
            return 'transcode';
        }
        // HEVC : natif seulement si le navigateur supporte HEVC ET audio compatible
        // FLAC/AC3/DTS/TrueHD → transcode (le navigateur ne décode pas ces audios)
        if (c === 'hevc') {
            var hevcSupported = canPlay('video/mp4; codecs="hvc1"') || canPlay('video/mp4; codecs="hev1"');
            if (d.isMP4 && hevcSupported && nativeAudio) return 'native';
            return 'transcode';
        }
        return 'transcode';
    }

    function onFail() {
        if (S.hasFailed) return;
        S.hasFailed = true;
        var pos = realTime();
        plog('ERROR', 'onFail step=' + S.step + ' confirmed=' + S.confirmed + ' pos=' + pos.toFixed(1));
        // Cascade : native/remux → transcode → erreur définitive
        if (!S.confirmed && (S.step === 'native' || S.step === 'remux')) {
            S.step = S.confirmed = 'transcode';
            plog('ERROR', 'cascade → transcode');
            hint.textContent = 'Transcodage en cours...'; hint.className = 'player-hint transcoding';
            updateModeUI();
            startStream(pos);
        } else {
            hint.textContent = 'Lecture impossible. Utilisez le bouton T\u00E9l\u00E9charger.';
            hint.className = 'player-hint error';
        }
    }
    player.addEventListener('error', onFail);

    player.addEventListener('playing', function() {
        plog('EVENT', 'playing | mode=' + (S.confirmed || S.step) + ' offset=' + S.offset.toFixed(1) + ' ct=' + (player.currentTime || 0).toFixed(1) + ' realTime=' + realTime().toFixed(1));
        unlockSize();
        Subs.resetIdx();
        var mode = S.confirmed || S.step;
        if ((mode === 'native' || mode === 'remux') && isVideo && !S.confirmed) {
            S.videoWidthTimer = setTimeout(function() {
                if (player.videoWidth === 0) onFail();
                else { S.confirmed = mode; hint.textContent = ''; updateModeUI(); }
            }, mode === 'native' ? 2000 : 1500);
            return;
        }
        hint.textContent = '';
    });

    // ── Stall watchdog ────────────────────────────────────────────────────────
    // Timeout différencié : transcode HEVC/burnSub est lent à démarrer (décodage
    // depuis le keyframe précédent + filtre overlay). Un timeout trop court crée
    // une boucle de retries qui spawne plusieurs ffmpeg en parallèle.
    // remux  : 10s  (quasi zéro délai, copie vidéo)
    // transcode sans burnSub : 20s  (décode depuis keyframe)
    // transcode avec burnSub : 30s  (décode + overlay = très lourd sur 4K HEVC)
    function stallTimeout() {
        var base = S.confirmed === 'remux' ? 10000 : (useHLS ? 30000 : (S.burnSub >= 0 ? 30000 : 20000));
        return Math.min(base * Math.pow(2, Math.min(S.stallCount, 6)), 120000); // exponentiel, cap 2min
    }
    function startStallWatchdog() {
        clearStallWatchdog();
        if (!isVideo || S.confirmed === 'native') return;
        var elapsed = 0;
        S.stallInterval = setInterval(function() {
            if (!player.paused && player.readyState < 3) { hint.textContent = 'Chargement... ' + (++elapsed) + 's'; hint.className = 'player-hint'; }
        }, 1000);
        S.stallTimer = setTimeout(function() {
            clearStallWatchdog();
            if (player.readyState < 3 && !player.paused) {
                S.stallCount++;
                plog('STALL', 'watchdog retry #' + S.stallCount + ' timeout=' + stallTimeout() + 'ms readyState=' + player.readyState);
                hint.textContent = 'Retry #' + S.stallCount + '...'; hint.className = 'player-hint';
                startStream(realTime());
            }
        }, stallTimeout());
    }
    function clearStallWatchdog() {
        clearTimeout(S.stallTimer); clearInterval(S.stallInterval);
        S.stallTimer = S.stallInterval = null;
    }
    // Reset stallCount après 30s de lecture stable (évite les délais de 2min après une reprise réseau)
    var stableTimer = null;
    // Fallback durée si probe échoue et stream natif d'un vrai MP4
    player.addEventListener('loadedmetadata', function() {
        if (S.duration <= 0 && player.duration && isFinite(player.duration)) {
            S.duration = player.duration;
            timeTotal.textContent = fmtTime(S.duration);
            seekBar.style.display = 'flex';
        }
    });

    player.addEventListener('waiting', function() { plog('EVENT', 'waiting | stallCount=' + S.stallCount + ' ct=' + (player.currentTime||0).toFixed(1)); clearTimeout(stableTimer); stableTimer = null; startStallWatchdog(); });
    player.addEventListener('playing', function() {
        clearStallWatchdog();
        clearTimeout(stableTimer);
        stableTimer = setTimeout(function() { S.stallCount = 0; }, 30000);
    });
    player.addEventListener('pause', function() { clearStallWatchdog(); clearTimeout(stableTimer); stableTimer = null; });

    // ── Module sous-titres ────────────────────────────────────────────────────
    var Subs = {
        cues: [], types: [], urls: [],
        _div: null, _idx: 0, _gen: 0, _track: null, _trackUrl: null,
        resetIdx: function() { this._idx = this.cues.length ? this._find(realTime()) : 0; },
        _find: function(t) {
            var lo = 0, hi = this.cues.length;
            while (lo < hi) { var mid = (lo + hi) >> 1; if (this.cues[mid].end <= t) lo = mid + 1; else hi = mid; }
            return lo;
        },
        // <track> pour sous-titres en fullscreen natif iOS (DOM overlay invisible derrière le player natif)
        _syncTrack: function() {
            if (!isIOS) return;
            if (this._track) { this._track.remove(); this._track = null; }
            if (this._trackUrl) { URL.revokeObjectURL(this._trackUrl); this._trackUrl = null; }
            if (!this.cues.length) return;
            var lines = ['WEBVTT', ''];
            for (var i = 0; i < this.cues.length; i++) {
                var c = this.cues[i];
                var st = c.start - S.offset, en = c.end - S.offset;
                if (en <= 0) continue;
                if (st < 0) st = 0;
                lines.push(this._fmtVtt(st) + ' --> ' + this._fmtVtt(en));
                lines.push(c.text);
                lines.push('');
            }
            var blob = new Blob([lines.join('\n')], {type: 'text/vtt'});
            this._trackUrl = URL.createObjectURL(blob);
            this._track = document.createElement('track');
            this._track.kind = 'subtitles';
            this._track.label = 'Sous-titres';
            this._track.srclang = 'und';
            this._track.src = this._trackUrl;
            player.appendChild(this._track);
            this._track.track.mode = player.webkitDisplayingFullscreen ? 'showing' : 'hidden';
        },
        _fmtVtt: function(s) {
            var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
            return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m + ':' + (sec < 10 ? '0' : '') + sec.toFixed(3);
        },
        render: function() {
            if (!this._div) return;
            var t = realTime(), txt = '';
            if (this.cues.length) {
                while (this._idx < this.cues.length && this.cues[this._idx].end <= t) this._idx++;
                if (this._idx < this.cues.length && this.cues[this._idx].start <= t) txt = this.cues[this._idx].text;
            }
            var safe = txt.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                          .replace(/&lt;(\/?(b|i|u|em|strong|s))&gt;/gi,'<$1>');
            var html = txt ? '<span style="background:rgba(0,0,0,.78);color:#fff;padding:.2em .6em;border-radius:4px;line-height:1.4;display:inline-block;max-width:100%;word-break:break-word;white-space:pre-line">' + safe + '</span>' : '';
            if (this._div.innerHTML !== html) this._div.innerHTML = html;
        },
        load: function(idx) {
            plog('SUBS', 'load idx=' + idx + ' type=' + (this.types[idx]||'off') + ' wasBurning=' + (S.burnSub >= 0));
            var gen = ++this._gen;
            var wasBurning = S.burnSub >= 0, pos = realTime();
            this.cues = []; this._idx = 0; S.burnSub = -1;
            if (this._div) this._div.innerHTML = '';
            this._syncTrack();
            if (idx >= 0 && this.types[idx] === 'image') {
                S.burnSub = idx; S.confirmed = S.step = 'transcode';
                hint.textContent = 'Transcodage avec sous-titres...'; hint.className = 'player-hint transcoding';
                startStream(pos);
            } else if (idx >= 0 && this.urls[idx]) {
                if (wasBurning) startStream(pos);
                var self = this;
                // Le pré-cache background (lancé au probe) a probablement déjà rempli le cache
                // Si pas encore prêt, le serveur fait l'extraction complète (~30-50s sur gros fichiers)
                fetch(this.urls[idx], {credentials:'same-origin'})
                    .then(function(r) { return r.text(); })
                    .then(function(t) {
                        if (gen !== self._gen) { plog('SUBS', 'DISCARDED: gen=' + gen + ' current=' + self._gen); return; }
                        self.cues = parseVTT(t);
                        self._idx = self._find(realTime());
                        self._syncTrack();
                        plog('SUBS', 'loaded ' + self.cues.length + ' cues, idx=' + self._idx + ' time=' + realTime().toFixed(1));
                    })
                    .catch(function(e) { plog('SUBS', 'fetch error: ' + e); });
            } else {
                if (wasBurning) startStream(pos);
            }
        },
        initOverlay: function() {
            if (this._div) return;
            this._div = document.createElement('div');
            this._div.className = 'sub-overlay';
            player.parentNode.appendChild(this._div);
            var self = this;
            function pos() {
                var wr = player.parentNode.getBoundingClientRect(), vr = player.getBoundingClientRect();
                var vw = player.videoWidth, vh = player.videoHeight;
                var below = wr.bottom - vr.bottom, barH = 0, ch = vr.height;
                if (vw && vh && vr.width && vr.height) {
                    var ar = vw / vh, ear = vr.width / vr.height;
                    if (ar > ear) { ch = vr.width / ar; barH = (vr.height - ch) / 2; }
                }
                self._div.style.bottom    = (below + barH + ch * 0.08) + 'px';
                self._div.style.fontSize  = Math.max(13, Math.round(vr.width * 0.025)) + 'px';
            }
            pos();
            player.addEventListener('loadedmetadata', pos);
            player.addEventListener('resize', pos);
            document.addEventListener('fullscreenchange', function() { setTimeout(pos, 50); });
            document.addEventListener('webkitfullscreenchange', function() { setTimeout(pos, 50); });
            if (window.ResizeObserver) { this._ro = new ResizeObserver(pos); this._ro.observe(player); }
            else { this._resizeHandler = pos; window.addEventListener('resize', pos); }
            // iOS fullscreen natif : afficher/masquer le <track> (seul moyen d'avoir les sous-titres)
            if (isIOS) {
                var self = this;
                player.addEventListener('webkitbeginfullscreen', function() {
                    if (self.cues.length) self._syncTrack();
                    if (self._track) self._track.track.mode = 'showing';
                });
                player.addEventListener('webkitendfullscreen', function() {
                    if (self._track) self._track.track.mode = 'hidden';
                });
            }
        }
    };

    function vttTime(s) {
        var p = s.trim().split(':');
        return p.length === 3 ? +p[0]*3600 + +p[1]*60 + parseFloat(p[2]) : +p[0]*60 + parseFloat(p[1]);
    }
    function parseVTT(text) {
        var cues = [], blocks = text.replace(/\r\n|\r/g,'\n').split(/\n\n+/);
        for (var b = 0; b < blocks.length; b++) {
            var lines = blocks[b].trim().split('\n'), ti = -1;
            for (var l = 0; l < lines.length; l++) { if (lines[l].indexOf(' --> ') !== -1) { ti = l; break; } }
            if (ti < 0) continue;
            var parts = lines[ti].split(' --> ');
            var txt = lines.slice(ti+1).join('\n').trim();
            if (txt) cues.push({ start: vttTime(parts[0]), end: vttTime(parts[1].split(' ')[0]), text: txt });
        }
        return cues;
    }

    // ── Seekbar ───────────────────────────────────────────────────────────────
    function updateSeekUI() {
        if (S.duration <= 0 || S.seekPending) return;
        var pos = realTime(), pct = Math.min(100, Math.max(0, pos / S.duration * 100));
        seekFill.style.width  = pct + '%';
        seekThumb.style.left  = pct + '%';
        timeCurrent.textContent = fmtTime(pos);
    }
    function updateBuffered() {
        if (S.duration <= 0 || !player.buffered || !player.buffered.length) return;
        seekBuffered.style.width = Math.min(100, (S.offset + player.buffered.end(player.buffered.length - 1)) / S.duration * 100) + '%';
    }
    function getFraction(e) {
        var rect = seekBar.getBoundingClientRect();
        return Math.max(0, Math.min(1, ((e.touches ? e.touches[0].clientX : e.clientX) - rect.left) / rect.width));
    }
    function seekToFraction(frac) {
        var t = Math.max(0, Math.min(S.duration, frac * S.duration));
        plog('SEEK', fmtTime(t) + ' (' + (frac*100).toFixed(0) + '%) mode=' + (S.confirmed||S.step));
        S.seekPending = true;
        var pct = t / S.duration * 100;
        seekFill.style.width = pct + '%'; seekThumb.style.left = pct + '%'; timeCurrent.textContent = fmtTime(t);
        clearTimeout(S.seekDebounce);
        if (S.confirmed === 'native') {
            S.seekPending = false; player.currentTime = t; hint.textContent = '';
            Subs.resetIdx();
        } else {
            S.seekDebounce = setTimeout(function() {
                startStream(t); S.seekPending = false;
                hint.textContent = 'Chargement \u00E0 ' + fmtTime(t) + '...'; hint.className = 'player-hint';
                // Correction rétroactive : le coarse seek atterrit sur keyframe K ≤ t.
                // On corrige S.offset = K pendant le démarrage du stream (avant le 1er frame).
                if (t > 0) {
                    var seekGen = ++S.seekGen;
                    fetch(base + '?' + pp + 'keyframe=' + t.toFixed(1))
                        .then(function(r) { return r.json(); })
                        .then(function(d) {
                            if (seekGen === S.seekGen && typeof d.pts === 'number' && d.pts >= 0) {
                                S.offset = d.pts; Subs.resetIdx();
                            }
                        })
                        .catch(function() {});
                }
            }, 300);
        }
    }
    player.addEventListener('timeupdate', function() {
        if (!S.dragging && !S.rafPending) {
            S.rafPending = true;
            requestAnimationFrame(function() { S.rafPending = false; updateSeekUI(); updateTitle(); });
        }
        updateBuffered(); Subs.render();
        // Watch history : marquer vu à 85% (une seule fois par lecture)
        if (!watchMarked && watchPath && watchCsrf && S.duration > 60) {
            if (realTime() / S.duration >= 0.85) {
                watchMarked = true;
                fetch('/share/ctrl.php?cmd=mark_watched', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({path: watchPath, duration: Math.round(S.duration), csrf_token: watchCsrf})
                }).catch(function(){});
            }
        }
    });
    seekBar.addEventListener('mousedown',  function(e) { if (!S.duration) return; S.dragging = true; seekBar.classList.add('dragging'); seekToFraction(getFraction(e)); });
    seekBar.addEventListener('touchstart', function(e) { if (!S.duration) return; S.dragging = true; seekBar.classList.add('dragging'); seekToFraction(getFraction(e)); }, {passive:true});
    document.addEventListener('mousemove', function(e) { if (S.dragging) seekToFraction(getFraction(e)); });
    document.addEventListener('touchmove', function(e) { if (S.dragging) { e.preventDefault(); seekToFraction(getFraction(e)); } }, {passive:false});
    document.addEventListener('mouseup',   function()  { if (S.dragging) { S.dragging = false; seekBar.classList.remove('dragging'); } });
    document.addEventListener('touchend',  function()  { if (S.dragging) { S.dragging = false; seekBar.classList.remove('dragging'); } });
    seekBar.addEventListener('mousemove', function(e) {
        if (!S.duration || !seekTooltip) return;
        var rect = seekBar.getBoundingClientRect(), frac = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
        seekTooltip.textContent = fmtTime(frac * S.duration);
        seekTooltip.style.left = (frac * 100) + '%'; seekTooltip.style.display = 'block';
    });
    seekBar.addEventListener('mouseleave', function() { if (seekTooltip) seekTooltip.style.display = 'none'; });

    // ── Volume ────────────────────────────────────────────────────────────────
    function updateVolUI() {
        var pct = (player.muted ? 0 : player.volume) * 100;
        if (volSlider) { volSlider.value = player.muted ? 0 : player.volume; volSlider.style.setProperty('--vol-pct', pct + '%'); }
        muteBtn.innerHTML = (player.muted || player.volume === 0) ? svgMute : svgVol;
    }
    function updateModeUI() {
        if (!modeBtn) return;
        var m = S.confirmed || S.step;
        var label = m === 'native' ? 'NATIF' : m === 'remux' ? 'REMUX' : 'x264\u00A0' + S.quality + 'p' + (S.burnSub >= 0 ? '\u00A0\u2605' : '');
        var cls   = m === 'remux' ? 'm-remux' : m === 'transcode' ? 'm-transcode' : 'm-native';
        modeBtn.textContent = label;
        modeBtn.className = 'mode-badge ' + cls;
    }

    // ── Probe → sélecteurs de piste ──────────────────────────────────────────
    function normLang(l) {
        var m = {fre:'fra',fr:'fra',en:'eng',jp:'jpn',jap:'jpn',de:'deu',ger:'deu',es:'spa',sp:'spa',it:'ita',pt:'por'};
        l = (l||'').toLowerCase();
        return m[l] !== undefined ? m[l] : l;
    }
    function applyProbe(d) {
        if (!d) return;
        if (d.isMP4) S.isMP4 = true;
        if (d.isMKV) S.isMKV = true;
        var hasControls = false;
        if (d.duration > 0) { S.duration = d.duration; timeTotal.textContent = fmtTime(S.duration); seekBar.style.display = 'flex'; }
        if (d.audio && d.audio.length > 1) {
            hasControls = true;
            var lbl = document.createElement('label'); lbl.textContent = 'Audio :';
            var sel = document.createElement('select'); sel.className = 'track-select';
            d.audio.forEach(function(a) { var o = document.createElement('option'); o.value = a.index; o.textContent = a.label; sel.appendChild(o); });
            sel.addEventListener('change', function() {
                S.audioIdx = parseInt(sel.value, 10) || 0; S.confirmed = S.step = 'transcode';
                plog('TRACK', 'audio changed → ' + S.audioIdx);
                hint.textContent = 'Changement de piste...'; hint.className = 'player-hint transcoding';
                saveCfg(); startStream(realTime());
            });
            if (!savedCfg) {
                var prefAudio = lsGet('pref_audio', '');
                if (prefAudio) {
                    for (var ai = 0; ai < d.audio.length; ai++) {
                        if (normLang(d.audio[ai].lang) === normLang(prefAudio)) {
                            S.audioIdx = d.audio[ai].index; sel.value = d.audio[ai].index; break;
                        }
                    }
                }
            }
            trackBar.append(lbl, sel);
        }
        if (d.videoHeight > 0) {
            S.videoHeight = d.videoHeight;
            var qs = [480, 576, 720, 1080].filter(function(q) { return q <= S.videoHeight; });
            if (qs.length) {
                if (!savedCfg || savedCfg.quality <= 0 || qs.indexOf(savedCfg.quality) === -1) {
                    var prefQ = parseInt(lsGet('pref_quality', '720'), 10);
                    S.quality = qs.indexOf(prefQ) !== -1 ? prefQ : (qs.indexOf(720) !== -1 ? 720 : qs[qs.length - 1]);
                }
                if (!savedCfg || !savedCfg.filter) {
                    S.filter = lsGet('pref_filter', 'none');
                } else {
                    S.filter = savedCfg.filter;
                }
                hasControls = true;
                var lbl3 = document.createElement('label'); lbl3.textContent = 'Qualit\u00E9 :';
                var sel3 = document.createElement('select'); sel3.className = 'track-select';
                qs.forEach(function(q) { var o = document.createElement('option'); o.value = q; o.textContent = q + 'p'; if (q === S.quality) o.selected = true; sel3.appendChild(o); });
                sel3.addEventListener('change', function() {
                    S.quality = parseInt(sel3.value, 10) || 720; S.confirmed = 'transcode';
                    plog('TRACK', 'quality changed → ' + S.quality + 'p');
                    hint.textContent = 'Transcodage ' + S.quality + 'p...'; hint.className = 'player-hint transcoding';
                    saveCfg(); startStream(realTime());
                });
                trackBar.append(lbl3, sel3);
                // Sélecteur de filtres
                var lbl4 = document.createElement('label'); lbl4.textContent = 'Filtre :';
                var sel4 = document.createElement('select'); sel4.className = 'track-select';
                var filters = [{v:'none',t:'Aucun'},{v:'hdr',t:'HDR→SDR'},{v:'anime',t:'Anime'},{v:'detail',t:'Détail'},{v:'night',t:'Nuit'},{v:'deinterlace',t:'Désentrelacé'}];
                filters.forEach(function(f) { var o = document.createElement('option'); o.value = f.v; o.textContent = f.t; if (f.v === S.filter) o.selected = true; sel4.appendChild(o); });
                sel4.addEventListener('change', function() {
                    S.filter = sel4.value || 'none'; S.confirmed = 'transcode';
                    plog('TRACK', 'filter changed → ' + S.filter);
                    hint.textContent = 'Changement de filtre...'; hint.className = 'player-hint transcoding';
                    saveCfg(); startStream(realTime());
                });
                trackBar.append(lbl4, sel4);
            }
        }
        if (d.subtitles && d.subtitles.length) {
            hasControls = true;
            d.subtitles.forEach(function(s) { Subs.urls.push(s.type === 'text' ? base + '?' + pp + 'subtitle=' + s.index : null); Subs.types.push(s.type || 'text'); });
            var lbl2 = document.createElement('label'); lbl2.textContent = 'Sous-titres :';
            var selSub = document.createElement('select'); selSub.className = 'track-select';
            var off = document.createElement('option'); off.value = '-1'; off.textContent = 'D\u00E9sactiv\u00E9s'; selSub.appendChild(off);
            d.subtitles.forEach(function(s, i) { var o = document.createElement('option'); o.value = i; o.textContent = s.label; selSub.appendChild(o); });
            // Restaurer le dernier sous-titre choisi pour ce fichier
            var subKey = 'player_sub_' + base + (pp ? pp : '');
            var savedSub = lsGet(subKey, '-1');
            var savedSubIdx = parseInt(savedSub, 10);
            if (!isNaN(savedSubIdx) && savedSubIdx >= 0 && savedSubIdx < d.subtitles.length) {
                selSub.value = savedSubIdx;
                Subs.load(savedSubIdx);
            } else {
                // player_sub_* n'est écrit que si l'utilisateur a choisi explicitement un sous-titre.
                // Absent = jamais choisi → appliquer la préférence globale (intentionnellement asymétrique
                // avec pref_audio qui vérifie !savedCfg, car savedCfg.audio est toujours écrit dès le 1er stream).
                var prefSubs = lsGet('pref_subs', 'off');
                if (prefSubs && prefSubs !== 'off') {
                    for (var si = 0; si < d.subtitles.length; si++) {
                        if (normLang(d.subtitles[si].lang) === normLang(prefSubs)) {
                            selSub.value = si; Subs.load(si); break;
                        }
                    }
                }
            }
            selSub.addEventListener('change', function() {
                var idx = parseInt(selSub.value, 10);
                if (isNaN(idx)) idx = -1;
                lsSet(subKey, idx);
                Subs.load(idx);
            });
            trackBar.append(lbl2, selSub);
        }
        if (hasControls) trackBar.style.display = 'flex';
    }

    // ── Contrôles vidéo ───────────────────────────────────────────────────────
    if (isVideo) {
        ctrlRow.style.display = 'flex';
        document.getElementById('seek-time').style.display = 'flex';
        // Play/pause
        var svgPauseIcon = '<svg viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>';
        var svgPlayIcon  = '<svg viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>';
        var playIconEl = document.getElementById('play-icon-overlay');
        var volOsd = document.getElementById('vol-osd');
        var volOsdTimer = null;
        function showVolOsd() {
            var pct = player.muted ? 0 : Math.round(player.volume * 100);
            var icon = player.muted || pct === 0 ? '\uD83D\uDD07' : pct < 50 ? '\uD83D\uDD09' : '\uD83D\uDD0A';
            volOsd.textContent = icon + ' ' + pct + '%';
            volOsd.classList.add('visible');
            clearTimeout(volOsdTimer);
            volOsdTimer = setTimeout(function() { volOsd.classList.remove('visible'); }, 1500);
        }
        function showSeekOsd(delta) {
            var icon = delta > 0 ? '\u23E9' : '\u23EA';
            volOsd.textContent = icon + ' ' + (delta > 0 ? '+' : '') + delta + 's';
            volOsd.classList.add('visible');
            clearTimeout(volOsdTimer);
            volOsdTimer = setTimeout(function() { volOsd.classList.remove('visible'); }, 800);
        }
        var popTimer = null;
        function showPlayIcon(pausing) {
            clearTimeout(popTimer);
            playIconEl.innerHTML = pausing ? svgPauseIcon : svgPlayIcon;
            playIconEl.classList.remove('pop-pause', 'pop-play', 'visible');
            void playIconEl.offsetWidth;
            playIconEl.classList.add(pausing ? 'pop-pause' : 'pop-play');
            if (!pausing) popTimer = setTimeout(function() { playIconEl.classList.remove('pop-play'); }, 450);
        }
        // Click/tap sur la zone vidéo : simple = pause/play, double = fullscreen+play/pause
        // Play/pause immédiat au premier tap (pas de setTimeout — requis pour iOS user gesture)
        // Double-tap détecté par timestamp : annule l'action du premier + toggle fullscreen
        var clickArea = document.getElementById('video-click-area');
        var lastClickTime = 0;
        var wasPausedBeforeTap = false;
        clickArea.addEventListener('click', function() {
            var now = Date.now();
            var isDouble = now - lastClickTime < 300;
            lastClickTime = isDouble ? 0 : now;
            if (isDouble) {
                // Double-tap : annuler le play/pause du premier tap, toggle fullscreen
                // Sur iOS : toggle fullscreen d'abord — le user gesture est consommé par play()/pause()
                // ce qui fait échouer webkitEnterFullscreen si on l'appelle après
                if (isIOS) { toggleFs(); return; }
                if (wasPausedBeforeTap) { player.pause(); showPlayIcon(true); }
                else { playIconEl.classList.remove('visible','pop-pause','pop-play'); player.play().catch(function(){}); }
                toggleFs();
            } else {
                // Single tap : play/pause immédiat (iOS exige play() dans le user gesture synchrone)
                wasPausedBeforeTap = player.paused;
                if (player.paused) { playIconEl.classList.remove('visible','pop-pause','pop-play'); player.play().catch(function(){}); }
                else               { player.pause(); showPlayIcon(true); }
            }
        });
        playBtn.addEventListener('click', function() {
            if (player.paused) { playIconEl.classList.remove('visible','pop-pause','pop-play'); player.play().catch(function(){}); }
            else               player.pause();
        });
        player.addEventListener('play',    function() { playBtn.innerHTML = svgPause; updateTitle(); });
        player.addEventListener('playing', function() { playBtn.innerHTML = svgPause; playIconEl.classList.remove('visible', 'pop-pause'); if (isFs()) showFsControls(); });
        player.addEventListener('pause',   function() { playBtn.innerHTML = svgPlay; playIconEl.innerHTML = svgPauseIcon; playIconEl.classList.remove('pop-pause','pop-play'); playIconEl.classList.add('visible'); updateTitle(); });
        player.addEventListener('waiting', function() { playBtn.innerHTML = svgPause; });
        player.addEventListener('ended',   function() { playBtn.innerHTML = svgPlay; document.title = originalTitle; });
        // Fullscreen
        if (fsBtn) fsBtn.addEventListener('click', toggleFs);
        // Zoom
        if (zoomBtn) zoomBtn.addEventListener('click', toggleZoom);
        // Picture-in-Picture (standard API + fallback iOS webkitPresentationMode)
        var hasPip = document.pictureInPictureEnabled;
        var hasIosPip = !hasPip && isIOS && typeof player.webkitSetPresentationMode === 'function';
        if (pipBtn && (hasPip || hasIosPip)) {
            pipBtn.style.display = '';
            pipBtn.addEventListener('click', function() {
                if (hasIosPip) {
                    player.webkitSetPresentationMode(player.webkitPresentationMode === 'picture-in-picture' ? 'inline' : 'picture-in-picture');
                } else {
                    if (document.pictureInPictureElement) document.exitPictureInPicture().catch(function(){});
                    else player.requestPictureInPicture().catch(function(){});
                }
            });
        }
        // Volume
        var savedVol = parseFloat(lsGet('player_volume', '1'));
        player.volume = isNaN(savedVol) ? 1 : Math.max(0, Math.min(1, savedVol));
        player.muted  = lsGet('player_muted', 'false') === 'true';
        updateVolUI();
        // iOS Safari ignore player.volume/muted via JS — cacher les contrôles volume
        if (isIOS) {
            muteBtn.style.display = 'none';
            var volWrap = muteBtn.closest('.vol-wrap') || (volSlider ? volSlider.parentNode : null);
            if (volWrap) volWrap.style.display = 'none';
        }
        muteBtn.addEventListener('click', function() {
            player.muted = !player.muted;
            lsSet('player_muted', player.muted);
            updateVolUI();
            showVolOsd();
        });
        var volSaveTimer = null;
        if (volSlider) volSlider.addEventListener('input', function() {
            player.volume = parseFloat(volSlider.value);
            player.muted  = player.volume === 0;
            updateVolUI();
            showVolOsd();
            clearTimeout(volSaveTimer);
            volSaveTimer = setTimeout(function() {
                lsSet('player_volume', player.volume);
                lsSet('player_muted',  player.muted);
            }, 500);
        });
        // Molette : volume (pas sur iOS — volume contrôlé par boutons physiques uniquement)
        if (!isIOS) playerCard.addEventListener('wheel', function(e) {
            e.preventDefault();
            var delta = e.deltaY < 0 ? 0.05 : -0.05;
            player.volume = Math.min(1, Math.max(0, player.volume + delta));
            player.muted = player.volume === 0;
            updateVolUI();
            showVolOsd();
            clearTimeout(volSaveTimer);
            volSaveTimer = setTimeout(function() {
                lsSet('player_volume', player.volume);
                lsSet('player_muted', player.muted);
            }, 500);
        }, { passive: false });
        // Vitesse
        var speeds = [0.5, 0.75, 1, 1.5, 2];
        var savedSpd = parseFloat(lsGet('player_speed', '1'));
        var speedIdx = speeds.indexOf(savedSpd); if (speedIdx < 0) speedIdx = speeds.indexOf(1);
        S.speed = speeds[speedIdx];
        if (speedBtn) { speedBtn.textContent = S.speed + '\u00D7'; speedBtn.addEventListener('click', function() {
            speedIdx = (speedIdx + 1) % speeds.length; S.speed = speeds[speedIdx];
            player.playbackRate = S.speed; speedBtn.textContent = S.speed + '\u00D7';
            lsSet('player_speed', S.speed);
        }); }
        // iOS Safari ignore playbackRate sur HLS — masquer le bouton vitesse
        if (isIOS && speedBtn) speedBtn.style.display = 'none';
        // Sauvegarde de position toutes les 5 s (30s min, 60s avant fin)
        setInterval(function() {
            if (player.paused || S.duration <= 0) return;
            var t = realTime();
            if (t > 30 && t < S.duration - 60) { lsSet(posKey, t.toFixed(0)); saveCfg(); }
            else if (t >= S.duration - 60)      { lsSet(posKey, '0'); clearCfg(); }
        }, 5000);
        player.addEventListener('ended', function() { lsSet(posKey, '0'); clearCfg(); });
        // Auto-next épisode
        var autoNextEl = null;
        player.addEventListener('ended', function() {
            if (!episodeNav.next) return;
            if (autoNextEl) autoNextEl.remove();
            var overlay = document.createElement('div');
            autoNextEl = overlay;
            overlay.className = 'autonext-overlay';
            var t1 = document.createElement('div'); t1.className = 'autonext-title'; t1.textContent = '\u00c9pisode suivant';
            var t2 = document.createElement('div'); t2.className = 'autonext-name'; t2.textContent = episodeNav.next.name;
            var t3 = document.createElement('div'); t3.className = 'autonext-countdown';
            var remaining = 8;
            t3.textContent = 'Lecture dans ' + remaining + 's\u2026';
            var acts = document.createElement('div'); acts.className = 'autonext-actions';
            var playNow = document.createElement('button'); playNow.className = 'an-play'; playNow.textContent = 'Lire maintenant';
            var cancel = document.createElement('button'); cancel.className = 'an-cancel'; cancel.textContent = 'Annuler';
            acts.appendChild(playNow); acts.appendChild(cancel);
            overlay.appendChild(t1); overlay.appendChild(t2); overlay.appendChild(t3); overlay.appendChild(acts);
            player.parentNode.appendChild(overlay);
            var cdt = setInterval(function() {
                remaining--;
                if (remaining <= 0) { clearInterval(cdt); navigateEpisode('next'); }
                else t3.textContent = 'Lecture dans ' + remaining + 's\u2026';
            }, 1000);
            playNow.addEventListener('click', function() { clearInterval(cdt); navigateEpisode('next'); });
            cancel.addEventListener('click', function() { clearInterval(cdt); overlay.remove(); });
        });
        window.addEventListener('pagehide', function() {
            var t = realTime();
            if (S.duration > 0 && t > 30 && t < S.duration - 60) { lsSet(posKey, t.toFixed(0)); saveCfg(); }
            clearStallWatchdog(); clearTimeout(stableTimer);
        });

        // Bouton Resync
        var resyncBtn = document.createElement('button');
        resyncBtn.className = 'player-btn'; resyncBtn.title = 'Resynchroniser son et image';
        resyncBtn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg> Resync';
        resyncBtn.addEventListener('click', function() {
            if (S.confirmed === 'native') { player.currentTime = Math.max(0, player.currentTime - 0.1); return; }
            hint.textContent = 'Resync...'; hint.className = 'player-hint'; startStream(realTime());
        });
        trackBar.appendChild(resyncBtn);
        // Badge mode courant — cliquable pour forcer un mode
        modeBtn = document.createElement('span');
        modeBtn.title = 'Cliquer pour changer le mode de lecture';
        updateModeUI();
        modeBtn.addEventListener('click', function() {
            var pos = realTime(), m = S.confirmed || S.step;
            // Cycle : native → [remux si MKV+activé] → x264-480p → x264-720p → x264-1080p → native
            var allQ = [480, 576, 720, 1080].filter(function(q) { return q <= (S.videoHeight || 1080); });
            if (m === 'native')         { S.step = S.confirmed = (REMUX_ENABLED && S.isMKV) ? 'remux' : 'transcode'; S.quality = allQ[0] || 480; }
            else if (m === 'remux')     { S.step = S.confirmed = 'transcode'; S.quality = allQ[0] || 480; }
            else {
                var qi = allQ.indexOf(S.quality);
                if (qi >= 0 && qi < allQ.length - 1) { S.quality = allQ[qi + 1]; }
                else { S.step = S.confirmed = 'native'; S.quality = 720; saveCfg(); startStream(pos); return; }
            }
            // Reset burnSub uniquement (les sous-titres texte survivent au changement de mode)
            if (S.burnSub >= 0) { S.burnSub = -1; Subs.cues = []; if (Subs._div) Subs._div.textContent = ''; }
            // Synchroniser le sélecteur de qualité
            var qSel = trackBar.querySelector('select.track-select');
            trackBar.querySelectorAll('select.track-select').forEach(function(sel) {
                if (sel.previousElementSibling && sel.previousElementSibling.textContent === 'Qualit\u00e9 :') {
                    sel.value = S.quality;
                }
            });
            hint.textContent = ''; updateModeUI(); saveCfg(); startStream(pos);
        });
        trackBar.appendChild(modeBtn); trackBar.style.display = 'flex';
        // Overlay raccourcis clavier (touche ?)
        var kbStyle = document.createElement('style');
        kbStyle.textContent = '#kb-overlay{position:fixed;inset:0;z-index:200;background:rgba(0,0,0,.75);display:flex;align-items:center;justify-content:center;-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px)}#kb-overlay.hidden{display:none}#kb-card{background:#1a1d28;border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:1.5rem 2rem;min-width:270px}#kb-card h3{font-size:.8rem;font-weight:700;color:#8b90a0;text-transform:uppercase;letter-spacing:.07em;margin-bottom:.85rem}.kb-row{display:flex;align-items:center;justify-content:space-between;padding:.27rem 0;border-bottom:1px solid rgba(255,255,255,.055);font-size:.81rem;gap:1.2rem}.kb-row:last-child{border-bottom:none}.kb-key{font-family:monospace;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);border-radius:4px;padding:.13rem .48rem;font-size:.72rem;color:#e8eaf0;white-space:nowrap;flex-shrink:0}.kb-desc{color:#8b90a0}';
        document.head.appendChild(kbStyle);
        var kbOverlay = document.createElement('div');
        kbOverlay.id = 'kb-overlay';
        kbOverlay.classList.add('hidden');
        var kbCard = document.createElement('div');
        kbCard.id = 'kb-card';
        var kbTitle = document.createElement('h3');
        kbTitle.textContent = 'Raccourcis clavier';
        kbCard.appendChild(kbTitle);
        var kbShortcuts = [['Espace / K','Lecture / Pause'],['← →','\u221210s / +10s'],['J / L','\u221230s / +30s'],
         ['\u2191 \u2193','Volume \u00B15\u00A0%'],['0\u20139','Aller \u00e0 N\u00d710\u00a0%'],
         ['F','Plein \u00e9cran'],['Z','Zoom (Fit/Fill/Stretch)'],['P','Picture-in-Picture'],['M','Muet'],['R','Resync son/image'],['?','Cette aide']];
        if (episodeNav.prev || episodeNav.next) kbShortcuts.push(['N / B','\u00c9pisode suivant / pr\u00e9c\u00e9dent']);
        kbShortcuts.forEach(function(r) {
            var row = document.createElement('div'); row.className = 'kb-row';
            var key = document.createElement('span'); key.className = 'kb-key'; key.textContent = r[0];
            var desc = document.createElement('span'); desc.className = 'kb-desc'; desc.textContent = r[1];
            row.appendChild(key); row.appendChild(desc); kbCard.appendChild(row);
        });
        kbOverlay.appendChild(kbCard);
        document.body.appendChild(kbOverlay);
        kbOverlay.addEventListener('click', function() { kbOverlay.classList.add('hidden'); });
        // Raccourcis clavier
        document.addEventListener('keydown', function(e) {
            if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA')) return;
            if (e.ctrlKey || e.metaKey || e.altKey) return;
            if (e.key === ' ' || e.key === 'k') {
                e.preventDefault();
                if (player.paused) { playIconEl.classList.remove('visible','pop-pause','pop-play'); player.play().catch(function(){}); }
                else { player.pause(); showPlayIcon(true); }
            }
            else if (e.key === 'ArrowLeft') {
                e.preventDefault();
                if (S.duration) { seekToFraction(Math.max(0, realTime() - 10) / S.duration); showSeekOsd(-10); }
            }
            else if (e.key === 'ArrowRight') {
                e.preventDefault();
                if (S.duration) { seekToFraction(Math.min(S.duration, realTime() + 10) / S.duration); showSeekOsd(10); }
            }
            else if (e.key === 'j' || e.key === 'J') {
                e.preventDefault();
                if (S.duration) { seekToFraction(Math.max(0, realTime() - 30) / S.duration); showSeekOsd(-30); }
            }
            else if (e.key === 'l' || e.key === 'L') {
                e.preventDefault();
                if (S.duration) { seekToFraction(Math.min(S.duration, realTime() + 30) / S.duration); showSeekOsd(30); }
            }
            else if (e.key === 'ArrowUp') {
                e.preventDefault();
                player.volume = Math.min(1, player.volume + 0.05);
                player.muted = false;
                lsSet('player_volume', player.volume);
                lsSet('player_muted', false);
                updateVolUI(); showVolOsd();
            }
            else if (e.key === 'ArrowDown') {
                e.preventDefault();
                player.volume = Math.max(0, player.volume - 0.05);
                player.muted = player.volume === 0;
                lsSet('player_volume', player.volume);
                lsSet('player_muted', player.muted);
                updateVolUI(); showVolOsd();
            }
            else if (e.key === 'f' || e.key === 'F') { e.preventDefault(); toggleFs(); }
            else if (e.key === 'm' || e.key === 'M') {
                e.preventDefault();
                player.muted = !player.muted;
                lsSet('player_muted', player.muted);
                updateVolUI(); showVolOsd();
            }
            else if (e.key >= '0' && e.key <= '9' && S.duration) {
                e.preventDefault();
                seekToFraction(parseInt(e.key) / 10);
            }
            else if (e.key === 'r' || e.key === 'R') {
                e.preventDefault();
                if (S.confirmed === 'native') { player.currentTime = Math.max(0, player.currentTime - 0.1); }
                else { hint.textContent = 'Resync...'; hint.className = 'player-hint'; startStream(realTime()); }
            }
            else if (e.key === 'p' || e.key === 'P') {
                e.preventDefault();
                if (hasIosPip) {
                    player.webkitSetPresentationMode(player.webkitPresentationMode === 'picture-in-picture' ? 'inline' : 'picture-in-picture');
                } else if (document.pictureInPictureEnabled) {
                    if (document.pictureInPictureElement) document.exitPictureInPicture().catch(function(){});
                    else player.requestPictureInPicture().catch(function(){});
                }
            }
            else if (e.key === 'z' || e.key === 'Z') {
                e.preventDefault();
                toggleZoom();
            }
            else if ((e.key === 'n' || e.key === 'N') && episodeNav.next) {
                e.preventDefault(); navigateEpisode('next');
            }
            else if ((e.key === 'b' || e.key === 'B') && episodeNav.prev) {
                e.preventDefault(); navigateEpisode('prev');
            }
            else if (e.key === '?') {
                e.preventDefault();
                kbOverlay.classList.toggle('hidden');
            }
            else if (e.key === 'Escape') {
                if (!kbOverlay.classList.contains('hidden')) { e.preventDefault(); kbOverlay.classList.add('hidden'); }
            }
        });
    }

    // ── Restauration config sauvegardée ────────────────────────────────────
    // Appelé AVANT applyProbe pour que les sélecteurs soient construits avec les bonnes valeurs.
    // Ne touche pas burnSub : celui-ci est restauré via player_sub_* dans applyProbe → Subs.load.
    function restoreCfg() {
        if (!savedCfg) return;
        plog('CONFIG', 'restoreCfg from localStorage', savedCfg);
        if (savedCfg.audio >= 0)   S.audioIdx = savedCfg.audio;
        if (savedCfg.quality > 0)  S.quality  = savedCfg.quality;
        if (savedCfg.mode)         { S.step = S.confirmed = savedCfg.mode; }
    }
    // Synchroniser les sélecteurs UI après applyProbe (qui les construit)
    function restoreCfgUI() {
        if (!savedCfg) return;
        var selects = trackBar.querySelectorAll('select.track-select');
        selects.forEach(function(sel) {
            if (sel.previousElementSibling && sel.previousElementSibling.textContent === 'Audio :') {
                var opt = sel.querySelector('option[value="' + S.audioIdx + '"]');
                if (opt) sel.value = S.audioIdx;
            }
            if (sel.previousElementSibling && sel.previousElementSibling.textContent === 'Qualit\u00e9 :') {
                var opt = sel.querySelector('option[value="' + S.quality + '"]');
                if (opt) sel.value = S.quality;
            }
        });
        updateModeUI();
    }

    // ── Démarrage ─────────────────────────────────────────────────────────────
    // Stratégie probe-first : on attend le probe pour choisir le bon mode d'emblée.
    // Si probe > 2s (cache froid, ffprobe lent) → fallback natif immédiat.
    // Sur cache chaud (SQLite) le probe revient en < 100ms → mode optimal sans faux départ.
    Subs.initOverlay();
    // Bandeau reprise
    var probeData = null;
    function showResumeBanner(pos, onResume) {
        var banner = document.createElement('div');
        banner.className = 'resume-banner';
        banner.textContent = 'Reprendre \u00e0 ' + fmtTime(pos) + '\u00a0?';
        var yesBtn = document.createElement('button');
        yesBtn.className = 'resume-yes';
        yesBtn.textContent = 'Reprendre';
        var noBtn = document.createElement('button');
        noBtn.className = 'resume-no';
        noBtn.textContent = 'D\u00e9but';
        banner.appendChild(yesBtn);
        banner.appendChild(noBtn);
        player.parentNode.appendChild(banner);
        yesBtn.addEventListener('click', function() { banner.remove(); onResume(pos); });
        noBtn.addEventListener('click', function() {
            banner.remove(); lsSet(posKey, '0'); clearCfg();
            // Réinitialiser au mode optimal du probe (pas la config sauvegardée)
            S.confirmed = ''; S.audioIdx = 0; S.quality = 720; S.burnSub = -1;
            if (probeData) S.step = chooseModeFromProbe(probeData);
            else S.step = 'native';
            updateModeUI();
            onResume(0);
        });
        setTimeout(function() { if (banner.parentNode) { banner.remove(); onResume(pos); } }, 8000);
    }
    if (isVideo) {
        plog('INIT', 'video startup | savedPos=' + savedPos + ' savedCfg=' + JSON.stringify(savedCfg) + ' episodeNav=' + JSON.stringify(episodeNav));
        hint.textContent = 'Analyse...'; hint.className = 'player-hint';
        var streamStarted = false;
        var fallbackAt = 0;
        var probeCtrl  = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var probeTimer = setTimeout(function() { if (probeCtrl) probeCtrl.abort(); }, 12000);
        // Fallback : démarrer en natif si le probe est trop lent
        var fallbackTimer = setTimeout(function() {
            if (!streamStarted) {
                plog('INIT', 'probe timeout → fallback natif');
                streamStarted = true; fallbackAt = Date.now(); hint.textContent = '';
                if (savedPos > 30) { showResumeBanner(savedPos, function(pos) { startStream(pos); }); }
                else { startStream(savedPos); }
            }
        }, 2000);
        fetch(base + '?' + pp + 'probe=1', probeCtrl ? {signal: probeCtrl.signal} : {})
            .then(function(r) { clearTimeout(probeTimer); return r.json(); })
            .then(function(d) {
                clearTimeout(fallbackTimer);
                probeData = d;
                plog('PROBE', 'received', {codec: d.videoCodec, h: d.videoHeight, dur: d.duration, isMP4: d.isMP4, isMKV: d.isMKV, audio: (d.audio||[]).length, subs: (d.subtitles||[]).length});
                if (savedCfg) restoreCfg();
                applyProbe(d);
                if (!streamStarted) {
                    // Probe arrivé à temps → choisir le mode optimal
                    streamStarted = true;
                    if (!savedCfg || !savedCfg.mode) S.step = chooseModeFromProbe(d);
                    if (savedCfg) restoreCfgUI();
                    // Auto-détection HDR : force 'hdr' si colorTransfer indique HDR (écrase localStorage)
                    if (d.colorTransfer && ['smpte2084', 'arib-std-b67', 'smpte428'].indexOf(d.colorTransfer) !== -1) {
                        S.filter = 'hdr';
                        // Mettre à jour le sélecteur UI
                        trackBar.querySelectorAll('select.track-select').forEach(function(sel) {
                            if (sel.previousElementSibling && sel.previousElementSibling.textContent === 'Filtre :') {
                                sel.value = 'hdr';
                            }
                        });
                        plog('HDR', 'auto-detected colorTransfer=' + d.colorTransfer + ', forcing filter=hdr');
                    }
                    hint.textContent = '';
                    if (savedPos > 30) {
                        plog('INIT', 'show resume banner at ' + fmtTime(savedPos));
                        showResumeBanner(savedPos, function(pos) { plog('RESUME', pos > 0 ? 'reprendre à ' + fmtTime(pos) : 'depuis le début'); startStream(pos); });
                    } else {
                        startStream(0);
                    }
                } else if (fallbackAt && Date.now() - fallbackAt < 5000) {
                    // Probe arrivé peu après le fallback natif — si le mode optimal est différent, restart proactif
                    var optimalMode = chooseModeFromProbe(d);
                    if (optimalMode !== 'native') {
                        plog('INIT', 'late probe restart → ' + optimalMode);
                        S.step = S.confirmed = optimalMode;
                        hint.textContent = optimalMode === 'transcode' ? 'Transcodage en cours...' : 'Remux en cours...';
                        hint.className = 'player-hint transcoding';
                        startStream(savedPos);
                    }
                }
                // Si stream déjà démarré (fallback), applyProbe a juste mis à jour l'UI
            })
            .catch(function() {
                clearTimeout(probeTimer); clearTimeout(fallbackTimer);
                if (!streamStarted) {
                    streamStarted = true; hint.textContent = '';
                    if (savedPos > 30) { showResumeBanner(savedPos, function(pos) { startStream(pos); }); }
                    else { startStream(savedPos); }
                }
            });
    } else {
        player.addEventListener('error', function() {
            hint.textContent = 'Format audio non support\u00e9 par votre navigateur. Utilisez T\u00e9l\u00e9charger.';
            hint.className = 'player-hint error';
        });
        startStream(0);
    }
})();
