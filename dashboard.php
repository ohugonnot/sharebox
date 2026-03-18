<details class="dash-section" id="dash-section">
    <summary class="dash-summary">
        <span class="dash-chevron">&#9658;</span>
        Syst&egrave;me
        <span class="dash-status-pills">
            <span class="dash-pill" id="dash-pill-cpu">CPU &mdash;</span>
            <span class="dash-pill" id="dash-pill-ram">RAM &mdash;</span>
            <span class="dash-pill" id="dash-pill-disk">HDD &mdash;</span>
            <span class="dash-pill" id="dash-pill-net">&uarr;&mdash; &darr;&mdash;</span>
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
                <div class="dash-card-title">Disque</div>
                <div class="dash-metric-main" id="dash-disk-val">&mdash;</div>
                <div class="dash-metric-sub" id="dash-disk-sub">&mdash; libre</div>
                <div class="dash-bar">
                    <div class="dash-bar-fill" id="dash-disk-bar" style="width:0%"></div>
                </div>
                <div class="dash-subtitle">
                    <span id="dash-disk-io">busy &mdash;</span>
                    <span id="dash-disk-rw">R &mdash; W &mdash;</span>
                </div>
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

        <!-- Graphe réseau 7 jours -->
        <details class="dash-subsection" id="dash-net-graph-section" open>
            <summary>R&eacute;seau 7 jours</summary>
            <div style="position:relative;height:200px">
                <canvas id="netChart"></canvas>
            </div>
        </details>

        <!-- Torrents -->
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

    </div><!-- /.dash-body -->
</details>

<!-- Chart.js — chargé uniquement via cette page admin -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script src="/share/dashboard.js?v=<?= filemtime(__DIR__ . '/dashboard.js') ?>"></script>
