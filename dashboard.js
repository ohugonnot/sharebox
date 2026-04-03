/**
 * Dashboard ShareBox — Refresh automatique CPU/RAM/Disk/Réseau/Torrents
 * Vanilla JS, pas de dépendances sauf Chart.js (déjà chargé dans dashboard.php).
 */
'use strict';

const D = {
    sysTimer:              null,
    speedTimer:            null,
    torrentTimer:          null,
    quotaTimer:            null,
    pillTimer:             null,  // Toujours actif même accordéon fermé
    netChartLoaded:        false,
    netChart:              null,
    netRange:              '7d',
    torrentIntervalOpen:   10000,
    torrentIntervalClosed: 30000,
    pillInterval:          60000, // Refresh pills quand accordéon fermé
    quotaInterval:         300000, // Refresh quota toutes les 5 min
};

/* ============================================================
 * Formatage
 * ============================================================ */

function fmtPct(v)    { return Math.round(v) + '%'; }
function fmtTemp(c)   { return c != null ? Math.round(c) + '\u00b0C' : '\u2014'; }

/** Formate une valeur MB en "X.X GB" ou "X MB" */
function fmtMb(mb)    { return mb >= 1024 ? (mb / 1024).toFixed(1) + '\u00a0GB' : Math.round(mb) + '\u00a0MB'; }

/** Formate en GB/TB — compact, sans répétition d'unité (pour "X / Y GB") */
function fmtGbVal(gb) {
    return gb >= 1000 ? (gb / 1000).toFixed(1) : Math.round(gb).toString();
}
function fmtGbUnit(gb) { return gb >= 1000 ? 'TB' : 'GB'; }
function fmtGb(gb)    { return fmtGbVal(gb) + '\u00a0' + fmtGbUnit(gb); }

function fmtMbs(mbs)  { return mbs.toFixed(1) + '\u00a0MB/s'; }
function fmtTime(ts)  {
    const d = new Date(ts * 1000);
    return d.toLocaleDateString('fr-FR', { month: 'short', day: 'numeric' })
         + ' ' + d.getHours() + 'h';
}

/* ============================================================
 * Couleur dynamique selon seuil
 * ============================================================ */

function colorByThreshold(pct, warn, crit) {
    if (pct >= crit) return '#ef5350';
    if (pct >= warn) return '#f0a030';
    return '#66bb6a';
}

function colorByTemp(c, warn, crit) {
    if (c == null)   return '#555968';
    if (c >= crit)   return '#ef5350';
    if (c >= warn)   return '#f0a030';
    return '#66bb6a';
}

/* ============================================================
 * DOM helpers
 * ============================================================ */

function id(elId)           { return document.getElementById(elId); }
function setText(elId, txt) { const el = id(elId); if (el) el.textContent = txt; }
function setColor(elId, c)  { const el = id(elId); if (el) el.style.color = c; }

function updateBar(fillEl, pct, color) {
    if (!fillEl) return;
    fillEl.style.width      = Math.min(100, Math.max(0, pct)) + '%';
    fillEl.style.background = color;
}

/* ============================================================
 * Fetch
 * ============================================================ */

function fetchJSON(url, cb) {
    fetch(url)
        .then(r => r.ok ? r.json() : Promise.reject(r.status))
        .then(cb)
        .catch(() => {});
}

/* ============================================================
 * CPU / RAM / Disk
 * ============================================================ */

function fetchSysinfo() { fetchJSON('/share/api/sysinfo.php', updateSysinfo); }

function updateSysinfo(d) {
    updateCpu(d);
    updateRam(d);
    updateDisk(d);
    updatePillsFromSysinfo(d); // pills toujours à jour même accordéon ouvert
}

function updateCpu(d) {
    const pct   = d.cpu_active_pct || 0;
    const color = colorByThreshold(pct, 70, 90);
    const load  = (d.cpu_load || [0, 0, 0]).map(v => v.toFixed(2)).join(' ');
    const temp  = d.cpu_temp_c != null ? d.cpu_temp_c : null;
    const tc    = colorByTemp(temp, 70, 85);

    setText('dash-cpu-val',    fmtPct(pct));
    setText('dash-cpu-sub',    'load\u00a0' + load);
    setText('dash-cpu-iowait', 'I/O\u00a0' + fmtPct(d.cpu_iowait_pct || 0));
    setText('dash-cpu-idle',   'idle\u00a0' + fmtPct(d.cpu_idle_pct  || 0));
    updateBar(id('dash-cpu-bar'), pct, color);

    // Température CPU
    setText('dash-cpu-temp', fmtTemp(temp));
    setColor('dash-cpu-temp', tc);

}

function updateRam(d) {
    const total    = d.ram_total_mb || 1;
    const used     = d.ram_used_mb  || 0;
    const prog     = d.ram_prog_mb  || 0;
    const cache    = d.ram_cache_mb || 0;
    const free     = d.ram_free_mb  || 0;
    const usedPct  = used  / total * 100;
    const progPct  = prog  / total * 100;
    const cachePct = cache / total * 100;

    // Format compact "X.X\u00a0/\u00a0Y.Y\u00a0GB" — unité unique à la fin
    setText('dash-ram-val',      fmtMb(used) + '\u00a0/\u00a0' + fmtMb(total));
    setText('dash-ram-sub',      fmtPct(usedPct) + '\u00a0utilis\u00e9');
    setText('dash-ram-prog-lbl', 'prog\u00a0' + fmtMb(prog));
    setText('dash-ram-free-lbl', 'free\u00a0' + fmtMb(free));
    id('dash-ram-prog').style.width  = Math.min(100, progPct)  + '%';
    id('dash-ram-cache').style.width = Math.min(100, cachePct) + '%';
}

function updateDisk(d) {
    // Volumes auto-détectés
    const volumes = Array.isArray(d.volumes) ? d.volumes : [];
    const wrap    = id('dash-disk-volumes');
    if (wrap && volumes.length) {
        wrap.innerHTML = '';
        volumes.forEach(function(vol, i) {
            const pct   = (vol.used_gb || 0) / (vol.total_gb || 1) * 100;
            const color = colorByThreshold(pct, 80, 93);
            const totalGb = vol.total_gb || 0;
            const usedGb  = vol.used_gb  || 0;
            const isTB    = totalGb >= 1000;
            const unit    = isTB ? 'TB' : 'GB';
            const usedStr = isTB ? (usedGb / 1000).toFixed(1) : Math.round(usedGb).toString();
            const totStr  = isTB ? (totalGb / 1000).toFixed(1) : Math.round(totalGb).toString();

            if (i > 0) {
                const sep = document.createElement('div');
                sep.className = 'dash-temp-line';
                sep.style.marginBottom = '.3rem';
                wrap.appendChild(sep);
            }

            const lbl = document.createElement('div');
            lbl.className = 'dash-temp-label';
            lbl.style.marginBottom = '.25rem';
            lbl.textContent = vol.label || 'Disque';
            wrap.appendChild(lbl);

            const main = document.createElement('div');
            main.className = 'dash-metric-main';
            main.textContent = usedStr + '\u00a0/\u00a0' + totStr + '\u00a0' + unit;
            wrap.appendChild(main);

            const sub = document.createElement('div');
            sub.className = 'dash-metric-sub';
            sub.textContent = fmtGb(vol.free_gb || 0) + '\u00a0libre';
            wrap.appendChild(sub);

            const bar  = document.createElement('div');
            bar.className = 'dash-bar';
            const fill = document.createElement('div');
            fill.className = 'dash-bar-fill';
            fill.style.width      = Math.min(100, pct).toFixed(1) + '%';
            fill.style.background = color;
            bar.appendChild(fill);
            wrap.appendChild(bar);

            if (vol.io_pct != null) {
                const io = document.createElement('div');
                io.className = 'dash-subtitle';
                io.style.marginBottom = '.1rem';
                io.textContent = 'IO\u00a0' + fmtPct(vol.io_pct)
                    + '\u2002R\u00a0' + fmtMbs(vol.read_mbs || 0)
                    + '\u00a0W\u00a0' + fmtMbs(vol.write_mbs || 0);
                wrap.appendChild(io);
            }
        });
    }

    // Températures HDD
    const hddTemps = d.hdd_temps;
    const hddEl    = id('dash-disk-temps');
    const hddRow   = id('dash-disk-temps-row');
    if (hddEl && hddRow) {
        if (hddTemps && Object.keys(hddTemps).length) {
            const vals = Object.values(hddTemps);
            hddEl.textContent = vals.map(t => Math.round(t)).join('\u00b7') + '\u00b0C';
            hddRow.style.display = 'flex';
        } else {
            hddRow.style.display = 'none';
        }
    }
}

/* ============================================================
 * Réseau instantané
 * ============================================================ */

function fetchSpeed() { fetchJSON('/share/api/speed.php', updateNet); }

function updateNet(d) {
    const maxMbs = d.max_mbs  || 125;
    const up     = d.upload   || 0;
    const down   = d.download || 0;

    setText('dash-net-up',   '\u2191\u00a0' + fmtMbs(up));
    setText('dash-net-down', '\u2193\u00a0' + fmtMbs(down));
    updateBar(id('dash-net-up-bar'),   up   / maxMbs * 100, '#f78166');
    updateBar(id('dash-net-down-bar'), down / maxMbs * 100, '#58a6ff');
    updatePillNet(d);
}

/* ============================================================
 * Historique réseau 7j — Chart.js
 * ============================================================ */

function fetchHistory() { fetchJSON('/share/api/netspeed_history.php?range=' + D.netRange, initNetChart); }

function fmtNetLabel(ts) {
    const d = new Date(ts * 1000);
    switch (D.netRange) {
        case '24h': return d.toLocaleTimeString('fr', {hour: '2-digit', minute: '2-digit'});
        case '7d':  return d.toLocaleDateString('fr', {weekday: 'short', hour: '2-digit'}) + 'h';
        case '1m':  return d.toLocaleDateString('fr', {day: '2-digit', month: 'short'});
        case '1y':  return d.toLocaleDateString('fr', {month: 'short', year: '2-digit'});
        default:    return d.toLocaleString('fr');
    }
}

function initNetChart(d) {
    const pts   = d.points || [];
    const ctx   = id('netChart').getContext('2d');

    // Gradient upload (rouge → transparent)
    const gradUp = ctx.createLinearGradient(0, 0, 0, 160);
    gradUp.addColorStop(0,   'rgba(247,129,102,.45)');
    gradUp.addColorStop(1,   'rgba(247,129,102,.03)');

    // Gradient download (bleu → transparent)
    const gradDn = ctx.createLinearGradient(0, 0, 0, 160);
    gradDn.addColorStop(0,   'rgba(88,166,255,.50)');
    gradDn.addColorStop(1,   'rgba(88,166,255,.03)');

    const labels = pts.map(p => fmtNetLabel(p.ts));
    const ups    = pts.map(p => p.upload);
    const downs  = pts.map(p => p.download);

    if (D.netChart) {
        D.netChart.data.labels           = labels;
        D.netChart.data.datasets[0].data = ups;
        D.netChart.data.datasets[1].data = downs;
        D.netChart.update('none');
        return;
    }

    const commonDataset = {
        fill:        true,
        tension:     0.4,
        pointRadius: 0,
        borderWidth: 1.5,
        stepped:     false,
    };

    D.netChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    ...commonDataset,
                    label:           'Upload',
                    data:            ups,
                    borderColor:     '#f78166',
                    backgroundColor: gradUp,
                    order:           1,
                },
                {
                    ...commonDataset,
                    label:           'Download',
                    data:            downs,
                    borderColor:     '#58a6ff',
                    backgroundColor: gradDn,
                    order:           2,
                },
            ],
        },
        options: {
            maintainAspectRatio: false,
            animation:           false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'top',
                    align:    'end',
                    labels:   {
                        color:     '#8b90a0',
                        boxWidth:  10,
                        boxHeight: 3,
                        padding:   12,
                        font:      { size: 10 },
                    },
                },
                tooltip: {
                    backgroundColor: 'rgba(18,20,28,.92)',
                    borderColor:     'rgba(255,255,255,.08)',
                    borderWidth:     1,
                    padding:         8,
                    titleColor:      '#8b90a0',
                    bodyColor:       '#e8eaf0',
                    titleFont:       { size: 10 },
                    bodyFont:        { size: 11, weight: '600' },
                    callbacks: {
                        label: ctx => ' ' + ctx.dataset.label + '  ' + ctx.parsed.y.toFixed(1) + ' MB/s',
                    },
                },
            },
            scales: {
                x: {
                    grid:    { color: 'rgba(255,255,255,.04)', drawTicks: false },
                    border:  { display: false },
                    ticks:   {
                        color:         '#555968',
                        font:          { size: 9 },
                        maxTicksLimit: 7,
                        maxRotation:   0,
                        padding:       4,
                    },
                },
                y: {
                    min:     0,
                    grid:    { color: 'rgba(255,255,255,.06)', drawTicks: false },
                    border:  { display: false },
                    ticks:   {
                        color:       '#555968',
                        font:        { size: 9 },
                        padding:     6,
                        callback:    v => v + ' MB/s',
                    },
                },
            },
        },
    });
}

/* ============================================================
 * Quota bande passante mensuel
 * ============================================================ */

function hasQuota() { return !!id('dash-quota'); }
function fetchQuota() { if (hasQuota()) fetchJSON('/share/api/quota.php', updateQuota); }

function fmtBytes(b) {
    if (b >= 1024 ** 4) return (b / 1024 ** 4).toFixed(1) + '\u00a0TB';
    if (b >= 1024 ** 3) return (b / 1024 ** 3).toFixed(1) + '\u00a0GB';
    return (b / 1024 ** 2).toFixed(0) + '\u00a0MB';
}

function updateQuota(d) {
    if (d.error) return;

    const pct  = d.pct || 0;
    const arc  = id('dash-quota-arc');
    const proj = id('dash-quota-proj-arc');

    // Ring gauge — circumference = 2 * PI * 52 ≈ 326.73
    const circ = 326.73;
    if (arc) arc.style.strokeDashoffset = circ - (circ * Math.min(pct, 100) / 100);

    // Projection ring — circumference = 2 * PI * 46 ≈ 289.03
    const projCirc = 289.03;
    const projPct  = d.quota_bytes > 0 ? d.projection / d.quota_bytes * 100 : 0;
    if (proj) {
        proj.style.strokeDashoffset = projCirc - (projCirc * Math.min(projPct, 100) / 100);
        proj.style.stroke = projPct > 100 ? 'rgba(239,83,80,.25)' :
                            projPct > 85  ? 'rgba(240,160,48,.2)' :
                                            'rgba(255,255,255,.06)';
    }

    // Color the main ring
    const ringColor = pct >= 90 ? '#ef5350' :
                      pct >= 75 ? '#f0a030' :
                                  '#66bb6a';
    if (arc) arc.style.stroke = ringColor;

    setText('dash-quota-pct', Math.round(pct) + '%');
    setText('dash-quota-used', fmtBytes(d.total_bytes) + '\u00a0/\u00a0' + fmtBytes(d.quota_bytes));
    setText('dash-quota-tx', fmtBytes(d.tx_bytes));
    setText('dash-quota-rx', fmtBytes(d.rx_bytes));
    setText('dash-quota-daily', fmtBytes(d.daily_avg) + '/j');
    setText('dash-quota-proj', fmtBytes(d.projection));
    setText('dash-quota-left', fmtBytes(Math.max(0, d.quota_bytes - d.total_bytes)));
    setText('dash-quota-days', d.days_left + 'j');

    // Pill
    updatePillQuota(pct);
}

function updatePillQuota(pct) {
    const el = id('dash-pill-quota');
    if (!el) return;
    el.textContent = 'Quota\u00a0' + Math.round(pct) + '%';
    const sev = pct >= 90 ? 'crit' : pct >= 75 ? 'warn' : 'ok';
    el.classList.remove('is-ok', 'is-warn', 'is-crit');
    el.classList.add('is-' + sev);
}

/* ============================================================
 * Torrents
 * ============================================================ */

function hasTorrents() { return !!id('dash-downloads'); }
function fetchTorrents() { if (hasTorrents()) fetchJSON('/share/api/active_torrents.php', updateTorrents); }

function updateTorrents(d) {
    renderTorrentList('dash-dl-list', d.downloads || [], 'down');
    renderTorrentList('dash-ul-list', d.uploads   || [], 'up');
}

function renderTorrentList(elId, items, dir) {
    const el = id(elId);
    if (!el) return;
    el.textContent = '';

    if (!items.length) {
        const empty = document.createElement('div');
        empty.className   = 'dash-empty';
        empty.textContent = 'Aucun';
        el.appendChild(empty);
        return;
    }

    // Déjà trié côté serveur (desc), on affiche directement
    items.forEach(t => {
        const wrap = document.createElement('div');
        wrap.className = 'dash-torrent-item';

        const nameEl = document.createElement('div');
        nameEl.className   = 'dash-torrent-name';
        nameEl.title       = t.name;
        nameEl.textContent = t.name;

        const metaEl = document.createElement('div');
        metaEl.className   = 'dash-torrent-meta';
        metaEl.textContent = dir === 'down'
            ? '\u2193\u00a0' + fmtMbs(t.down_mbs) + '\u00a0\u2014\u00a0' + Math.round(t.progress) + '%'
            : '\u2191\u00a0' + fmtMbs(t.up_mbs);

        wrap.appendChild(nameEl);
        wrap.appendChild(metaEl);
        el.appendChild(wrap);
    });
}

/* ============================================================
 * Intervals torrents adaptatifs
 * ============================================================ */

function updateTorrentInterval() {
    if (!hasTorrents()) return;
    clearInterval(D.torrentTimer);
    const anyOpen = document.querySelector('#dash-downloads[open], #dash-uploads[open]');
    const ms      = anyOpen ? D.torrentIntervalOpen : D.torrentIntervalClosed;
    D.torrentTimer = setInterval(fetchTorrents, ms);
}

/* ============================================================
 * LocalStorage helpers
 * ============================================================ */

function lsGet(key)        { try { return localStorage.getItem(key); } catch(e) { return null; } }
function lsSet(key, val)   { try { localStorage.setItem(key, val);   } catch(e) {} }

/* ============================================================
 * Sévérité et couleur des pills
 * ============================================================ */

function pillSeverity(val, warn, crit) {
    if (val >= crit) return 'crit';
    if (val >= warn) return 'warn';
    return 'ok';
}

function worstSeverity(a, b) {
    const order = ['ok', 'warn', 'crit'];
    return order[Math.max(order.indexOf(a), order.indexOf(b))];
}

function setPillSeverity(elId, sev) {
    const el = id(elId);
    if (!el) return;
    el.classList.remove('is-ok', 'is-warn', 'is-crit');
    el.classList.add('is-' + sev);
}

/**
 * Met à jour les 3 pills sysinfo (CPU/RAM/HDD) depuis une réponse /api/sysinfo.
 * Appelé aussi bien par fetchForPills (accordéon fermé) que par updateSysinfo (ouvert).
 */
function updatePillsFromSysinfo(d) {
    const cpuPct  = d.cpu_active_pct || 0;
    const cpuTemp = d.cpu_temp_c;
    const ramPct  = (d.ram_used_mb  || 0) / (d.ram_total_mb  || 1) * 100;
    const volumes  = Array.isArray(d.volumes) ? d.volumes : [];
    const diskPct  = volumes.length
        ? Math.max.apply(null, volumes.map(function(v) { return (v.used_gb || 0) / (v.total_gb || 1) * 100; }))
        : (d.disk_used_gb || 0) / (d.disk_total_gb || 1) * 100;
    const busyPct = d.disk_io_pct || 0;

    // CPU : % + température
    id('dash-pill-cpu').textContent = 'CPU\u00a0' + fmtPct(cpuPct)
        + (cpuTemp != null ? '\u00a0' + fmtTemp(cpuTemp) : '');
    setPillSeverity('dash-pill-cpu', worstSeverity(
        pillSeverity(cpuPct,  70, 90),
        cpuTemp != null ? pillSeverity(cpuTemp, 70, 85) : 'ok'
    ));

    // RAM
    id('dash-pill-ram').textContent = 'RAM\u00a0' + fmtPct(ramPct);
    setPillSeverity('dash-pill-ram', pillSeverity(ramPct, 80, 90));

    // HDD : usage % + IO capacity %
    id('dash-pill-disk').textContent = 'HDD\u00a0' + fmtPct(diskPct)
        + '\u00a0io:' + fmtPct(busyPct);
    setPillSeverity('dash-pill-disk', worstSeverity(
        pillSeverity(diskPct, 80, 93),
        pillSeverity(busyPct, 75, 97)
    ));
}

/** Met à jour la pill réseau depuis une réponse /api/speed. */
function updatePillNet(d) {
    id('dash-pill-net').textContent = '\u2191' + fmtMbs(d.upload || 0)
        + '\u00a0\u2193' + fmtMbs(d.download || 0);
}

/* ============================================================
 * Refresh "pills only" — toujours actif même quand l'accordéon est fermé
 * ============================================================ */

function fetchForPills() {
    fetchJSON('/share/api/sysinfo.php', updatePillsFromSysinfo);
    fetchJSON('/share/api/speed.php',   updatePillNet);
    if (hasQuota()) fetchJSON('/share/api/quota.php', function(d) { if (!d.error) updatePillQuota(d.pct || 0); });
}

/* ============================================================
 * Démarrage / arrêt des timers détaillés (accordéon ouvert seulement)
 * ============================================================ */

function startDashTimers() {
    // Arrêter le timer pill léger — le full sysinfo le remplace (plus fréquent)
    clearInterval(D.pillTimer);
    fetchSysinfo();
    fetchSpeed();
    D.sysTimer   = setInterval(fetchSysinfo, 10000);
    D.speedTimer = setInterval(fetchSpeed,   10000);
    if (hasTorrents()) {
        fetchTorrents();
        D.torrentTimer = setInterval(fetchTorrents, D.torrentIntervalClosed);
    }
    if (hasQuota()) {
        fetchQuota();
        D.quotaTimer = setInterval(fetchQuota, D.quotaInterval);
    }
}

function stopDashTimers() {
    clearInterval(D.sysTimer);
    clearInterval(D.speedTimer);
    clearInterval(D.torrentTimer);
    clearInterval(D.quotaTimer);
    // Relancer le timer pill léger
    fetchForPills();
    D.pillTimer = setInterval(fetchForPills, D.pillInterval);
}

/* ============================================================
 * Init
 * ============================================================ */

(function init() {
    const dashSection = id('dash-section');
    if (!dashSection) return;

    // Fetch initial pour les pills (toujours, quelle que soit l'état de l'accordéon)
    fetchForPills();

    // Restaurer l'état depuis localStorage
    if (lsGet('sb_dash_open') === '1') {
        dashSection.setAttribute('open', '');
        startDashTimers();
    } else {
        // Accordéon fermé : démarrer le timer pill léger
        D.pillTimer = setInterval(fetchForPills, D.pillInterval);
    }

    dashSection.addEventListener('toggle', function () {
        lsSet('sb_dash_open', this.open ? '1' : '0');
        if (this.open) {
            startDashTimers();
        } else {
            stopDashTimers();
        }
    });

    // Graphe réseau — état persisté en localStorage, ouvert par défaut
    const graphSection = id('dash-net-graph-section');
    if (graphSection) {
        const savedGraph = lsGet('sb_dash_graph_open');
        if (savedGraph === '0') {
            graphSection.removeAttribute('open');
        } else {
            // Ouvert par défaut (ou si localStorage dit '1')
            graphSection.setAttribute('open', '');
            if (!D.netChartLoaded && dashSection.hasAttribute('open')) {
                D.netChartLoaded = true;
                fetchHistory();
            }
        }
        graphSection.addEventListener('toggle', function () {
            lsSet('sb_dash_graph_open', this.open ? '1' : '0');
            if (this.open && !D.netChartLoaded) {
                D.netChartLoaded = true;
                fetchHistory();
            }
        });

        // Boutons de temporalité — restore + persist dans localStorage
        const savedRange = lsGet('sb_net_range');
        if (savedRange) {
            D.netRange = savedRange;
            document.querySelectorAll('.net-range-btn').forEach(function (b) {
                b.classList.toggle('active', b.dataset.range === savedRange);
            });
        }
        document.querySelectorAll('.net-range-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                document.querySelectorAll('.net-range-btn').forEach(function (b) {
                    b.classList.remove('active');
                });
                btn.classList.add('active');
                D.netRange = btn.dataset.range;
                lsSet('sb_net_range', D.netRange);
                fetchHistory();
            });
        });
    }

    // Téléchargements et Envois s'ouvrent/ferment ensemble (si rtorrent actif)
    if (hasTorrents()) ['dash-downloads', 'dash-uploads'].forEach(function (elId) {
        const el = id(elId);
        if (!el) return;
        el.addEventListener('toggle', function () {
            const other = id(elId === 'dash-downloads' ? 'dash-uploads' : 'dash-downloads');
            if (other && other.open !== this.open) {
                other.open = this.open;
            }
            updateTorrentInterval();
        });
    });
})();
