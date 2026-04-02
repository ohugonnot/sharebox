<?php
function sb_has_vnstat(): bool {
    static $has = null;
    if ($has === null) {
        $has = is_executable('/usr/bin/vnstat') || is_executable('/usr/sbin/vnstat');
    }
    return $has;
}
?>
<details class="dash-section" id="dash-section">
    <summary class="dash-summary">
        <span class="dash-chevron">&#9658;</span>
        Syst&egrave;me
        <span class="dash-status-pills">
            <span class="dash-pill" id="dash-pill-cpu">CPU &mdash;</span>
            <span class="dash-pill" id="dash-pill-ram">RAM &mdash;</span>
            <span class="dash-pill" id="dash-pill-disk">HDD &mdash;</span>
            <span class="dash-pill" id="dash-pill-net">&uarr;&mdash; &darr;&mdash;</span>
            <?php if (sb_has_vnstat()): ?>
            <span class="dash-pill" id="dash-pill-quota">Quota &mdash;</span>
            <?php endif; ?>
        </span>
    </summary>

    <div class="dash-body">

        <!-- 4 cartes métriques -->
        <div class="dash-grid">

            <!-- CPU -->
            <div class="dash-card" id="dash-cpu">
                <div class="dash-card-title">CPU</div>
                <div class="dash-metric-main" id="dash-cpu-val">&mdash;</div>
                <div class="dash-metric-sub" id="dash-cpu-sub">load &mdash;</div>
                <div class="dash-bar">
                    <div class="dash-bar-fill" id="dash-cpu-bar" style="width:0%"></div>
                </div>
                <div class="dash-subtitle">
                    <span id="dash-cpu-iowait">I/O &mdash;</span>
                    <span id="dash-cpu-idle">idle &mdash;</span>
                </div>
                <div class="dash-temp-line">
                    <span class="dash-temp-label">temp</span>
                    <span class="dash-temp-val" id="dash-cpu-temp">&mdash;</span>
                </div>
            </div>

            <!-- RAM -->
            <div class="dash-card" id="dash-ram">
                <div class="dash-card-title">RAM</div>
                <div class="dash-metric-main" id="dash-ram-val">&mdash;</div>
                <div class="dash-metric-sub" id="dash-ram-sub">&mdash;</div>
                <div class="dash-mini-bars" id="dash-ram-bars">
                    <div class="dash-mini-bar" id="dash-ram-prog" style="width:0%;background:var(--dash-down)"></div>
                    <div class="dash-mini-bar" id="dash-ram-cache" style="width:0%;background:var(--text-muted)"></div>
                </div>
                <div class="dash-subtitle">
                    <span id="dash-ram-prog-lbl">prog &mdash;</span>
                    <span id="dash-ram-free-lbl">free &mdash;</span>
                </div>
            </div>

            <!-- Disque -->
            <div class="dash-card" id="dash-disk">
                <div class="dash-card-title">Stockage</div>
                <div id="dash-disk-volumes"><!-- rempli dynamiquement --></div>
                <div class="dash-temp-line" id="dash-disk-temps-row" style="display:none">
                    <span class="dash-temp-label">hdd</span>
                    <span class="dash-temp-val" id="dash-disk-temps"></span>
                </div>
            </div>

            <!-- Réseau -->
            <div class="dash-card" id="dash-net">
                <div class="dash-card-title">R&eacute;seau</div>
                <div class="dash-metric-main" id="dash-net-up">&mdash;</div>
                <div class="dash-bar">
                    <div class="dash-bar-fill" id="dash-net-up-bar" style="width:0%;background:var(--dash-up)"></div>
                </div>
                <div class="dash-metric-main" id="dash-net-down" style="margin-top:.4rem">&mdash;</div>
                <div class="dash-bar">
                    <div class="dash-bar-fill" id="dash-net-down-bar" style="width:0%;background:var(--dash-down)"></div>
                </div>
            </div>

        </div><!-- /.dash-grid -->

        <!-- Quota bande passante mensuel (affiché uniquement si vnstat est installé) -->
        <?php if (sb_has_vnstat()): ?>
        <div class="dash-quota-card" id="dash-quota">
            <div class="dash-quota-ring-wrap">
                <svg class="dash-quota-ring" viewBox="0 0 120 120">
                    <circle class="dash-quota-track" cx="60" cy="60" r="52" />
                    <circle class="dash-quota-fill" id="dash-quota-arc" cx="60" cy="60" r="52" />
                    <circle class="dash-quota-proj" id="dash-quota-proj-arc" cx="60" cy="60" r="46" />
                </svg>
                <div class="dash-quota-center">
                    <div class="dash-quota-pct" id="dash-quota-pct">&mdash;</div>
                    <div class="dash-quota-label">utilis&eacute;</div>
                </div>
            </div>
            <div class="dash-quota-details">
                <div class="dash-card-title">Quota mensuel</div>
                <div class="dash-quota-used" id="dash-quota-used">&mdash;</div>
                <div class="dash-quota-breakdown">
                    <span class="dash-quota-tx">&uarr; <span id="dash-quota-tx">&mdash;</span></span>
                    <span class="dash-quota-rx">&darr; <span id="dash-quota-rx">&mdash;</span></span>
                </div>
                <div class="dash-quota-meta">
                    <div><span class="dash-quota-meta-label">Moy/jour</span> <span id="dash-quota-daily">&mdash;</span></div>
                    <div><span class="dash-quota-meta-label">Projection</span> <span id="dash-quota-proj">&mdash;</span></div>
                    <div><span class="dash-quota-meta-label">Reste</span> <span id="dash-quota-left">&mdash;</span></div>
                    <div><span class="dash-quota-meta-label">J restants</span> <span id="dash-quota-days">&mdash;</span></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Graphe réseau multi-temporalité -->
        <details class="dash-subsection" id="dash-net-graph-section" open>
            <summary>
                R&eacute;seau
                <span class="net-range-btns">
                    <button class="net-range-btn" data-range="24h">24h</button>
                    <button class="net-range-btn active" data-range="7d">7j</button>
                    <button class="net-range-btn" data-range="1m">1m</button>
                    <button class="net-range-btn" data-range="1y">1an</button>
                </span>
            </summary>
            <div style="position:relative;height:200px">
                <canvas id="netChart"></canvas>
            </div>
        </details>

        <!-- Torrents (affiché uniquement si rtorrent est configuré et le socket existe) -->
        <?php
        $rtSock = defined('RTORRENT_SOCK') ? RTORRENT_SOCK : '';
        if ($rtSock !== '' && file_exists($rtSock)):
        ?>
        <div class="dash-torrents-row">
            <details class="dash-subsection" id="dash-downloads">
                <summary>&darr; T&eacute;l&eacute;chargements</summary>
                <div id="dash-dl-list"><div class="dash-empty">&mdash;</div></div>
            </details>
            <details class="dash-subsection" id="dash-uploads">
                <summary>&uarr; Envois</summary>
                <div id="dash-ul-list"><div class="dash-empty">&mdash;</div></div>
            </details>
        </div>
        <?php endif; ?>

    </div><!-- /.dash-body -->
</details>

<!-- Chart.js — chargé uniquement via cette page admin -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script src="/share/dashboard.js?v=<?= filemtime(__DIR__ . '/dashboard.js') ?>"></script>
