<?php
/**
 * Téléchargement public — Gère les URLs /dl/{token}
 * Vérifie le token, l'expiration, le mot de passe, puis sert le contenu
 *
 * - Fichiers : servis via X-Accel-Redirect (nginx sendfile, zero-copy, supporte resume)
 * - Dossiers : listing navigable avec téléchargement individuel de chaque fichier
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// Récupérer le token depuis l'URL (passé par nginx)
$token = $_GET['token'] ?? '';

if (empty($token) || !preg_match('/^[a-z0-9][a-z0-9-]{1,50}$/', $token)) {
    http_response_code(404);
    afficher_erreur('Lien introuvable', 'Ce lien de téléchargement n\'existe pas ou est invalide.');
    exit;
}

// Chercher le lien en base
$db = get_db();
$stmt = $db->prepare("SELECT * FROM links WHERE token = :token");
$stmt->execute([':token' => $token]);
$link = $stmt->fetch();

if (!$link) {
    stream_log('ACCESS 404 | token=' . $token . ' | not found');
    http_response_code(404);
    afficher_erreur('Lien introuvable', 'Ce lien n\'existe pas ou a été supprimé.');
    exit;
}

// Vérifier l'expiration
if ($link['expires_at'] !== null && strtotime($link['expires_at']) < time()) {
    stream_log('ACCESS 410 | token=' . $token . ' | expired ' . $link['expires_at']);
    http_response_code(410);
    afficher_erreur('Lien expiré', 'Ce lien de partage a expiré et n\'est plus disponible.');
    exit;
}

// Vérifier que le fichier/dossier existe toujours
if (!file_exists($link['path'])) {
    stream_log('ACCESS 404 | token=' . $token . ' | path gone: ' . $link['path']);
    http_response_code(404);
    afficher_erreur('Fichier introuvable', 'Le fichier ou dossier partagé n\'existe plus sur le serveur.');
    exit;
}

// Gestion du mot de passe (via session pour ne pas redemander à chaque clic)
if ($link['password_hash'] !== null) {
    session_start();
    $sessionKey = 'share_auth_' . $token;

    if (isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] === true) {
        // Déjà authentifié
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        $attemptsKey = 'share_attempts_' . $token;
        $attempts = (int)($_SESSION[$attemptsKey] ?? 0);
        if ($attempts >= AUTH_MAX_ATTEMPTS) {
            stream_log('AUTH brute-force | token=' . $token . ' | attempts=' . $attempts);
            sleep(AUTH_LOCKOUT_SLEEP);
            afficher_formulaire_mdp($link['name'], 'Trop de tentatives. Réessayez plus tard.');
            exit;
        }
        if (!password_verify($_POST['password'], $link['password_hash'])) {
            stream_log('AUTH fail | token=' . $token . ' | attempt=' . ($attempts + 1));
            $_SESSION[$attemptsKey] = $attempts + 1;
            sleep(AUTH_FAIL_SLEEP);
            afficher_formulaire_mdp($link['name'], 'Mot de passe incorrect.');
            exit;
        }
        unset($_SESSION[$attemptsKey]);
        $_SESSION[$sessionKey] = true;
        session_regenerate_id(true);
        stream_log('AUTH ok | token=' . $token);
    } else {
        afficher_formulaire_mdp($link['name']);
        exit;
    }
}

// Sous-chemin dans le dossier partagé (pour la navigation)
$subPath = $_GET['p'] ?? '';

// Calculer le chemin réel demandé
$basePath = rtrim($link['path'], '/');
$targetPath = $subPath ? $basePath . '/' . $subPath : $basePath;
$resolvedPath = realpath($targetPath);

// Sécurité : le chemin résolu doit rester dans le dossier partagé
if (!is_path_within($resolvedPath, $basePath)) {
    stream_log('ACCESS 403 | token=' . $token . ' | path traversal: ' . ($subPath ?: '(root)'));
    http_response_code(403);
    afficher_erreur('Accès interdit', 'Ce chemin n\'est pas autorisé.');
    exit;
}

// Si c'est un fichier
if (is_file($resolvedPath)) {
    // Mode lecture : page avec player video/audio
    if (isset($_GET['play']) && $_GET['play'] === '1') {
        $mediaType = get_media_type(basename($resolvedPath));
        if ($mediaType) {
            stream_log('PLAYER open | ' . $mediaType . ' | ' . basename($resolvedPath) . ($subPath ? ' | p=' . $subPath : ''));
            afficher_player($token, $link['name'], $subPath, $mediaType, $basePath);
            exit;
        }
    }

    // Probe : retourne les pistes audio/sous-titres en JSON (pour le player)
    if (isset($_GET['probe']) && $_GET['probe'] === '1') {
        require __DIR__ . '/handlers/probe.php';
    }

    // Extraction sous-titre en WebVTT (avec cache SQLite)
    if (isset($_GET['subtitle'])) {
        require __DIR__ . '/handlers/subtitle.php';
    }

    // Keyframe lookup : retourne le PTS réel du keyframe coarse-seeké par ffmpeg
    // Le JS corrige S.offset rétroactivement pour éliminer le drift des sous-titres
    if (isset($_GET['keyframe'])) {
        require __DIR__ . '/handlers/keyframe.php';
    }

    // Mode streaming natif : sert le fichier brut (audio uniquement, ou fallback)
    if (isset($_GET['stream']) && $_GET['stream'] === '1') {
        require __DIR__ . '/handlers/stream_native.php';
    }

    // Sélection de piste audio (paramètre &audio=N, index relatif dans les pistes audio)
    $audioTrack = isset($_GET['audio']) ? max(0, (int)$_GET['audio']) : 0;
    $audioMap = ' -map 0:v:0 -map 0:a:' . $audioTrack;

    // Seek coarse-only (keyframe seek avant -i). Imprécision max : ~2s sur x265 UHD.
    $startSec = isset($_GET['start']) ? max(0, (float)$_GET['start']) : 0;
    $seekArgBefore = $startSec > 0 ? ' -ss ' . escapeshellarg(sprintf('%.3f', $startSec)) : '';

    // Mode remux : repackage MKV→MP4 sans ré-encoder la vidéo (quasi zéro CPU)
    // Audio transcodé en AAC pour compatibilité (AC3/DTS → AAC, léger)
    if (isset($_GET['stream']) && $_GET['stream'] === 'remux') {
        require __DIR__ . '/handlers/stream_remux.php';
    }

    // Mode transcodage complet : ré-encode vidéo + audio (CPU intensif)
    if (isset($_GET['stream']) && $_GET['stream'] === 'transcode') {
        require __DIR__ . '/handlers/stream_transcode.php';
    }

    // Mode HLS : transcodage en segments TS pour iOS Safari
    // Safari refuse le streaming fMP4 progressif (broken pipe) — HLS est le seul format
    // que Safari iOS supporte nativement pour le streaming adaptatif.
    if (isset($_GET['stream']) && $_GET['stream'] === 'hls') {
        require __DIR__ . '/handlers/stream_hls.php';
    }

    // Téléchargement direct via nginx
    stream_log('DOWNLOAD | ' . basename($resolvedPath) . ' | size=' . format_taille(filesize($resolvedPath)));
    if (!$subPath) {
        $stmt = $db->prepare("UPDATE links SET download_count = download_count + 1 WHERE id = :id");
        $stmt->execute([':id' => $link['id']]);
    }

    $encodedPath = XACCEL_PREFIX . str_replace('%2F', '/', rawurlencode($resolvedPath));
    $fileName = basename($resolvedPath);

    header('Content-Type: application/octet-stream');
    $safeFileName = preg_replace('/[\r\n\0]/', '', $fileName);
    header('Content-Disposition: attachment; filename="' . addcslashes($safeFileName, '"\\') . '"');
    header('X-Accel-Redirect: ' . $encodedPath);
    exit;
}

// Si c'est un dossier
if (is_dir($resolvedPath)) {
    stream_log('BROWSE | token=' . $token . ' | ' . ($subPath ?: '(root)') . ' | ' . basename($resolvedPath));
    if (!$subPath) {
        $stmt = $db->prepare("UPDATE links SET download_count = download_count + 1 WHERE id = :id");
        $stmt->execute([':id' => $link['id']]);
    }

    // TMDB poster endpoints (search, batch, set)
    if (isset($_GET['posters']) || isset($_GET['tmdb_search']) || isset($_GET['tmdb_set']) || isset($_GET['folder_type_set']) || isset($_GET['ai_recheck']) || isset($_GET['tmdb_reload'])) {
        require __DIR__ . '/handlers/tmdb.php';
    }

    // Mode ZIP : télécharger tout le dossier en un seul fichier
    if (isset($_GET['zip']) && $_GET['zip'] === '1') {
        stream_log('ZIP start | ' . basename($resolvedPath));
        $maxZipSize = defined('MAX_ZIP_SIZE') ? MAX_ZIP_SIZE : 10 * 1024 * 1024 * 1024;
        if (dir_size($resolvedPath) > $maxZipSize) {
            http_response_code(413);
            afficher_erreur('Trop volumineux', 'Ce dossier dépasse la limite autorisée pour le téléchargement ZIP.');
            exit;
        }

        $zipName = basename($resolvedPath) . '.zip';
        $parentDir = dirname($resolvedPath);
        $baseName = basename($resolvedPath);

        header('Content-Type: application/zip');
        $safeZipName = preg_replace('/[\r\n\0]/', '', $zipName);
        header('Content-Disposition: attachment; filename="' . addcslashes($safeZipName, '"\\') . '"');
        header('X-Accel-Buffering: no');

        passthru('cd ' . escapeshellarg($parentDir) . ' && zip -r -0 - ' . escapeshellarg($baseName));
        exit;
    }

    afficher_listing($resolvedPath, $basePath, $token, $link['name'], $subPath);
    exit;
}

http_response_code(404);
afficher_erreur('Introuvable', 'Ce fichier n\'existe pas.');
exit;

// ============================================================================
// FONCTIONS
// ============================================================================

/**
 * Retourne le CSS commun pour toutes les pages publiques
 */
function css_public(): string {
    return <<<'CSS'
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=JetBrains+Mono:wght@400&display=swap');
:root {
    --bg-deep: #0c0e14;
    --bg-surface: #1a1d28;
    --bg-elevated: #222530;
    --bg-hover: #282b38;
    --accent: #f0a030;
    --accent-soft: rgba(240,160,48,.12);
    --text-primary: #e8eaf0;
    --text-secondary: #8b90a0;
    --text-muted: #555968;
    --border: rgba(255,255,255,.06);
    --red: #ef5350;
    --green: #66bb6a;
    --blue: #42a5f5;
    --radius-sm: 6px;
    --radius-md: 10px;
    --radius-lg: 16px;
    --font-sans: 'DM Sans', -apple-system, BlinkMacSystemFont, sans-serif;
    --font-mono: 'JetBrains Mono', monospace;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: var(--font-sans);
    background: var(--bg-deep);
    color: var(--text-primary);
    min-height: 100vh;
    -webkit-font-smoothing: antialiased;
}
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background:
        radial-gradient(ellipse 80% 50% at 50% -20%, rgba(240,160,48,.03), transparent),
        linear-gradient(rgba(255,255,255,.015) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.015) 1px, transparent 1px);
    background-size: 100% 100%, 48px 48px, 48px 48px;
    pointer-events: none;
    z-index: 0;
}
CSS;
}

/**
 * Affiche le listing d'un dossier partagé
 */
function afficher_listing(string $dirPath, string $basePath, string $token, string $shareName, string $subPath): void {
    $items = scandir($dirPath);
    $folders = [];
    $files = [];

    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item[0] === '.') continue;
        $fullItem = $dirPath . '/' . $item;
        if (is_dir($fullItem)) {
            $folders[] = ['name' => $item, 'has_video' => dir_has_video($fullItem)];
        } else {
            $files[] = ['name' => $item, 'size' => filesize($fullItem)];
        }
    }

    usort($folders, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    usort($files, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

    $baseUrl = '/dl/' . htmlspecialchars($token);
    $shareNameHtml = htmlspecialchars($shareName);
    $css = css_public();
    $hasFolders = count($folders) > 0;

    // Check if this folder is tagged as "movies"
    $db = get_db();
    $currentFolderType = 'series';
    $stmtType = $db->prepare("SELECT folder_type FROM folder_posters WHERE path = :p");
    $stmtType->execute([':p' => $dirPath]);
    $typeRow = $stmtType->fetch();
    if ($typeRow && $typeRow['folder_type']) {
        $currentFolderType = $typeRow['folder_type'];
    }
    $isMoviesFolder = ($currentFolderType === 'movies');
    $videoFiles = [];
    if ($isMoviesFolder) {
        foreach ($files as $f) {
            if (get_media_type($f['name']) === 'video') {
                $videoFiles[] = $f;
            }
        }
    }
    $hasGridItems = $hasFolders || !empty($videoFiles);

    // Breadcrumb
    $breadcrumb = '<a href="' . $baseUrl . '">' . $shareNameHtml . '</a>';
    if ($subPath) {
        $parts = explode('/', $subPath);
        $cumul = '';
        foreach ($parts as $i => $part) {
            $cumul .= ($cumul ? '/' : '') . $part;
            $partHtml = htmlspecialchars($part);
            if ($i < count($parts) - 1) {
                $breadcrumb .= '<span class="sep">/</span><a href="' . $baseUrl . '?p=' . rawurlencode($cumul) . '">' . $partHtml . '</a>';
            } else {
                $breadcrumb .= '<span class="sep">/</span><span class="current">' . $partHtml . '</span>';
            }
        }
    }

    // Palette de couleurs pour les placeholders grille (dérivées du thème)
    $cardColors = ['#c06020','#2a7a5a','#4a5a8a','#8a4a6a','#5a7a3a','#7a5a2a','#3a6a7a','#6a3a5a'];

    echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="/share/favicon.svg">
    <title>{$shareNameHtml}</title>
    <style>{$css}
@keyframes fadeUp { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:none} }
@keyframes fadeScale { from{opacity:0;transform:scale(.92)} to{opacity:1;transform:none} }
.page { position:relative; z-index:1; max-width:1100px; margin:0 auto; padding:2rem 1.25rem 4rem; }
.header { display:flex; align-items:center; gap:.7rem; margin-bottom:1rem; padding-bottom:1rem; border-bottom:1px solid var(--border); }
.header-icon { width:36px; height:36px; background:var(--accent-soft); border-radius:var(--radius-md); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.header-title { font-size:1.1rem; font-weight:700; color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.breadcrumb { display:flex; align-items:center; gap:.1rem; margin-bottom:1.1rem; font-size:.82rem; overflow-x:auto; white-space:nowrap; -webkit-overflow-scrolling:touch; scrollbar-width:none; padding:.3rem 0; }
.breadcrumb::-webkit-scrollbar { display:none; }
.breadcrumb a { color:var(--accent); text-decoration:none; font-weight:500; flex-shrink:0; }
.breadcrumb a:hover { color:#ffc060; }
.breadcrumb .sep { color:var(--text-muted); margin:0 .2rem; flex-shrink:0; }
.breadcrumb .current { color:var(--text-secondary); font-weight:500; flex-shrink:0; }
.toolbar { display:flex; align-items:center; gap:.4rem; margin-bottom:.7rem; flex-wrap:wrap; }
.toolbar-info { color:var(--text-muted); font-size:.79rem; margin-right:auto; white-space:nowrap; }
.sort-bar { display:flex; align-items:center; gap:.25rem; }
.sort-btn,.tb-icon { display:inline-flex; align-items:center; gap:.2rem; padding:.28rem .55rem; border:1px solid var(--border); border-radius:var(--radius-sm); background:transparent; color:var(--text-muted); font-family:var(--font-sans); font-size:.73rem; font-weight:600; cursor:pointer; transition:all .15s; white-space:nowrap; }
.sort-btn:hover,.tb-icon:hover { color:var(--text-secondary); border-color:rgba(255,255,255,.12); }
.sort-btn.active,.tb-icon.active { color:var(--accent); border-color:rgba(240,160,48,.25); background:var(--accent-soft); }
.sort-btn svg { transition:transform .15s; }
.sort-btn.desc svg { transform:rotate(180deg); }
.search-box { padding:.3rem .65rem; border:1px solid var(--border); border-radius:var(--radius-sm); background:rgba(255,255,255,.03); color:var(--text-primary); font-family:var(--font-sans); font-size:.8rem; outline:none; transition:border-color .15s; width:175px; }
.search-box:focus { border-color:var(--accent); box-shadow:0 0 0 2px var(--accent-soft); }
.search-box::placeholder { color:var(--text-muted); }
.btn-zip { display:inline-flex; align-items:center; gap:.35rem; padding:.38rem .85rem; border:none; border-radius:var(--radius-sm); background:var(--accent); color:var(--bg-deep); font-family:var(--font-sans); font-size:.82rem; font-weight:700; cursor:pointer; text-decoration:none; transition:all .15s; white-space:nowrap; }
.btn-zip:hover { background:#ffc060; box-shadow:0 2px 12px rgba(240,160,48,.25); }
.btn-zip:active { transform:scale(.97); }
/* ── List view ── */
.panel { background:rgba(26,29,40,.65); border:1px solid rgba(255,255,255,.07); border-radius:var(--radius-lg); backdrop-filter:blur(12px); overflow:hidden; }
.row { display:flex; align-items:center; min-height:48px; padding:.5rem 1rem; border-bottom:1px solid var(--border); transition:background .12s; text-decoration:none; color:var(--text-primary); animation:fadeUp .22s ease both; }
.row:last-child { border-bottom:none; }
.row:hover { background:rgba(255,255,255,.022); }
.row:nth-child(1){animation-delay:.03s}.row:nth-child(2){animation-delay:.06s}.row:nth-child(3){animation-delay:.09s}.row:nth-child(4){animation-delay:.12s}.row:nth-child(5){animation-delay:.15s}.row:nth-child(6){animation-delay:.18s}.row:nth-child(7){animation-delay:.21s}.row:nth-child(8){animation-delay:.24s}.row:nth-child(n+9){animation-delay:.27s}
.row-icon { width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; margin-right:.7rem; flex-shrink:0; }
.row-icon.folder { background:var(--accent-soft); }
.row-icon.up { background:rgba(255,255,255,.04); }
.row-icon.vid { background:rgba(102,187,106,.1); }
.row-icon.aud { background:rgba(171,71,188,.12); }
.row-icon.img { background:rgba(38,198,218,.1); }
.row-icon.arc { background:rgba(239,83,80,.1); }
.row-icon.file { background:rgba(66,165,245,.08); }
.row-name { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:.875rem; }
.row-name.is-folder { color:var(--accent); font-weight:500; }
.row-ext { color:var(--text-muted); font-size:.68rem; font-family:var(--font-mono); text-transform:uppercase; background:rgba(255,255,255,.04); padding:.1rem .35rem; border-radius:4px; flex-shrink:0; margin-left:.5rem; letter-spacing:.04em; }
.row-meta { color:var(--text-muted); font-size:.78rem; font-family:var(--font-mono); margin-left:auto; padding-left:.6rem; white-space:nowrap; flex-shrink:0; }
.btn-play { display:flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:50%; background:rgba(102,187,106,.12); color:var(--green); cursor:pointer; flex-shrink:0; margin-left:.5rem; transition:background .15s,transform .12s; border:1px solid rgba(102,187,106,.2); }
.btn-play:hover { background:rgba(102,187,106,.22); border-color:rgba(102,187,106,.4); transform:scale(1.08); }
.btn-play:active { transform:scale(.92); }
.empty { text-align:center; padding:3rem 1rem; color:var(--text-muted); }
.empty-icon { font-size:2rem; display:block; margin-bottom:.6rem; opacity:.35; }
.row.hidden { display:none; }
/* ── Grid view ── */
.grid-wrap { display:grid; grid-template-columns:repeat(auto-fill,minmax(var(--card-size,180px),1fr)); gap:.75rem; margin-bottom:1rem; }
.grid-wrap.hidden { display:none; }
.grid-card { position:relative; border-radius:var(--radius-md); overflow:hidden; cursor:pointer; text-decoration:none; color:var(--text-primary); transition:transform .18s,box-shadow .18s; animation:fadeScale .25s ease both; border:1px solid rgba(255,255,255,.06); display:flex; flex-direction:column; }
.grid-card:hover { transform:translateY(-4px) scale(1.02); box-shadow:0 12px 32px rgba(0,0,0,.5); border-color:rgba(240,160,48,.2); }
.grid-card-bg { aspect-ratio:2/3; display:flex; align-items:center; justify-content:center; background-size:cover; background-position:center; transition:background-image .3s; }
.grid-card.has-poster .grid-card-letter { display:none; }
.grid-card.has-poster .grid-card-icon { display:none; }
.grid-card-overview { position:absolute; inset:0; background:rgba(6,8,14,.92); backdrop-filter:blur(8px); padding:.6rem; display:flex; flex-direction:column; justify-content:flex-end; opacity:0; transition:opacity .2s; pointer-events:none; }
.grid-card:hover .grid-card-overview { opacity:1; }
.grid-card-overview-title { font-size:.85rem; font-weight:700; color:var(--accent); margin-bottom:.35rem; line-height:1.25; }
.grid-card-overview-text { font-size:.74rem; color:#ccc; line-height:1.5; display:-webkit-box; -webkit-line-clamp:8; -webkit-box-orient:vertical; overflow:hidden; }
.grid-card-toggle { position:absolute; top:.5rem; left:.5rem; width:22px; height:22px; border-radius:50%; background:rgba(0,0,0,.5); border:1px solid rgba(255,255,255,.1); display:flex; align-items:center; justify-content:center; cursor:pointer; opacity:0; transition:opacity .15s; z-index:5; color:rgba(255,255,255,.5); }
.grid-card:hover .grid-card-toggle { opacity:.6; }
.grid-card-toggle:hover { opacity:1 !important; background:rgba(0,0,0,.7); border-color:rgba(255,255,255,.3); color:#fff; }
.grid-card-ctx { position:absolute; top:.5rem; right:.5rem; width:26px; height:26px; border-radius:50%; background:rgba(0,0,0,.55); border:1px solid rgba(255,255,255,.15); display:flex; align-items:center; justify-content:center; cursor:pointer; opacity:0; transition:opacity .15s; z-index:5; color:rgba(255,255,255,.8); }
.grid-card:hover .grid-card-ctx { opacity:1; }
.grid-card-ctx:hover { background:rgba(0,0,0,.8); border-color:var(--accent); color:var(--accent); }
.grid-card-menu { position:absolute; top:calc(.5rem + 30px); right:.5rem; background:#1a1a2e; border:1px solid rgba(255,255,255,.15); border-radius:8px; padding:.3rem 0; min-width:160px; z-index:20; box-shadow:0 4px 12px rgba(0,0,0,.5); display:none; }
.grid-card-menu.open { display:block; }
.grid-card-menu-item { display:flex; align-items:center; gap:.5rem; padding:.45rem .75rem; color:rgba(255,255,255,.85); font-size:.78rem; cursor:pointer; white-space:nowrap; transition:background .1s; }
.grid-card-menu-item:hover { background:rgba(255,255,255,.08); }
.grid-card-menu-item svg { width:14px; height:14px; flex-shrink:0; }
.grid-card-ai-pending { position:absolute; inset:0; background:rgba(0,0,0,.7); display:flex; flex-direction:column; align-items:center; justify-content:center; z-index:4; pointer-events:none; gap:.4rem; }
.grid-card-ai-pending svg { width:20px; height:20px; color:var(--accent); opacity:.8; animation:spin 2s linear infinite; }
.grid-card-ai-pending span { font-size:.65rem; color:rgba(255,255,255,.7); text-align:center; line-height:1.3; padding:0 .5rem; }
@keyframes spin { to { transform:rotate(360deg); } }
/* Poster picker modal */
.poster-modal { position:fixed; inset:0; z-index:100; background:rgba(0,0,0,.7); display:flex; align-items:center; justify-content:center; backdrop-filter:blur(4px); animation:fadeUp .15s ease; }
.poster-modal-card { background:var(--bg-surface); border:1px solid rgba(255,255,255,.1); border-radius:var(--radius-lg); padding:1.5rem; max-width:720px; width:94%; max-height:85vh; overflow-y:auto; }
.poster-modal h3 { font-size:1rem; font-weight:700; margin-bottom:.6rem; color:var(--text-primary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.poster-modal-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:.8rem; }
.poster-modal-item { position:relative; aspect-ratio:2/3; border-radius:var(--radius-sm); overflow:hidden; cursor:pointer; border:2px solid transparent; transition:border-color .15s,transform .12s; }
.poster-modal-item:hover { border-color:var(--accent); transform:scale(1.03); }
.poster-modal-item img { width:100%; height:100%; object-fit:cover; }
.poster-modal-info { position:absolute; bottom:0; left:0; right:0; padding:.4rem .5rem; background:rgba(0,0,0,.72); backdrop-filter:blur(4px); text-align:center; font-size:.78rem; color:#fff; font-weight:700; line-height:1.3; text-shadow:0 1px 2px rgba(0,0,0,.5); overflow-wrap:break-word; word-break:break-word; text-wrap:balance; }
.poster-modal-close { margin-top:1rem; padding:.5rem .8rem; border:1px solid var(--border); border-radius:var(--radius-sm); background:transparent; color:var(--text-secondary); font-family:var(--font-sans); font-size:.82rem; cursor:pointer; width:100%; }
.poster-modal-close:hover { background:rgba(255,255,255,.05); color:var(--text-primary); }
.grid-card-letter { font-family:var(--font-sans); font-weight:700; font-size:3rem; color:rgba(255,255,255,.18); text-transform:uppercase; user-select:none; }
.grid-card-icon { position:absolute; top:.7rem; right:.7rem; opacity:.25; }
.grid-card-label { padding:.4rem .5rem; background:rgba(0,0,0,.72); text-align:center; height:3rem; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.grid-card-title { font-size:.85rem; font-weight:700; line-height:1.3; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; text-shadow:0 1px 2px rgba(0,0,0,.5); overflow-wrap:break-word; word-break:break-word; hyphens:auto; text-wrap:balance; }
.grid-card:nth-child(1){animation-delay:.03s}.grid-card:nth-child(2){animation-delay:.06s}.grid-card:nth-child(3){animation-delay:.09s}.grid-card:nth-child(4){animation-delay:.12s}.grid-card:nth-child(5){animation-delay:.15s}.grid-card:nth-child(6){animation-delay:.18s}.grid-card:nth-child(n+7){animation-delay:.21s}
.grid-card.hidden { display:none; }
/* ── Settings dropdown ── */
.gear-wrap { position:relative; }
.gear-panel { display:none; position:absolute; top:calc(100% + 6px); right:0; z-index:50; min-width:260px; background:rgba(22,25,35,.96); border:1px solid rgba(255,255,255,.1); border-radius:var(--radius-md); backdrop-filter:blur(16px); box-shadow:0 16px 48px rgba(0,0,0,.6); padding:.5rem 0; animation:fadeUp .15s ease; }
.gear-panel.open { display:block; }
.gear-section { padding:.55rem .9rem; }
.gear-section + .gear-section { border-top:1px solid rgba(255,255,255,.05); }
.gear-label { font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted); margin-bottom:.4rem; }
.gear-row { display:flex; align-items:center; gap:.4rem; margin-bottom:.3rem; }
.gear-row:last-child { margin-bottom:0; }
.gear-toggle { display:flex; gap:0; border:1px solid var(--border); border-radius:var(--radius-sm); overflow:hidden; }
.gear-opt { padding:.3rem .65rem; font-family:var(--font-sans); font-size:.75rem; font-weight:600; background:transparent; color:var(--text-muted); border:none; cursor:pointer; transition:all .12s; white-space:nowrap; }
.gear-opt:not(:last-child) { border-right:1px solid var(--border); }
.gear-opt:hover { color:var(--text-secondary); }
.gear-opt.active { background:var(--accent-soft); color:var(--accent); }
.gear-select { flex:1; padding:.28rem .5rem; border:1px solid var(--border); border-radius:var(--radius-sm); background:rgba(255,255,255,.04); color:var(--text-primary); font-family:var(--font-sans); font-size:.78rem; outline:none; cursor:pointer; -webkit-appearance:none; appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' fill='%238b90a0' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right .4rem center; padding-right:1.4rem; }
.gear-select:focus { border-color:var(--accent); }
.gear-select option { background:#1a1d28; color:#e8eaf0; }
@media(max-width:640px){.row-name{white-space:normal;word-break:break-word;font-size:.83rem}.row-ext{display:none}.search-box{width:115px}.row{min-height:44px}.grid-wrap{grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:.5rem}}
@media(max-width:480px){.page{padding:1.1rem .85rem 3rem}.row{padding:.45rem .75rem}.row-icon{width:28px;height:28px;margin-right:.55rem}.btn-zip{flex:1;justify-content:center}.search-box{flex:1;width:auto;min-width:80px}.toolbar-info{display:none}.gear-panel{right:-1rem;min-width:240px}.grid-wrap{grid-template-columns:repeat(2,1fr)}}
    </style>
</head>
<body>
<div class="page">
    <div class="header">
        <div class="header-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style="color:var(--accent)"><path d="M2 6a2 2 0 012-2h5l2 2h9a2 2 0 012 2v10a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg></div>
        <div class="header-title">{$shareNameHtml}</div>
    </div>
    <nav class="breadcrumb">{$breadcrumb}</nav>
HTML;

    // Compter le total des éléments et construire l'URL ZIP
    $totalItems = count($folders) + count($files);
    $totalFiles = count($files);
    if ($totalItems > 0) {
        $zipUrl = $baseUrl . '?zip=1';
        if ($subPath) {
            $zipUrl = $baseUrl . '?p=' . rawurlencode($subPath) . '&zip=1';
        }
        $itemsLabel = $totalItems . ' élément' . ($totalItems > 1 ? 's' : '');
        echo '<div class="toolbar">';
        echo '<span class="toolbar-info">' . $itemsLabel . '</span>';

        // Barre de tri (seulement si des fichiers à trier)
        if ($totalFiles > 1) {
            $arrow = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M12 5v14M5 12l7 7 7-7"/></svg>';
            echo '<div class="sort-bar">';
            echo '<button class="sort-btn active" data-sort="name" onclick="tri(this,\'name\')">' . $arrow . ' Nom</button>';
            echo '<button class="sort-btn" data-sort="size" onclick="tri(this,\'size\')">' . $arrow . ' Taille</button>';
            echo '</div>';
        }

        // Recherche (seulement si 10+ éléments)
        if ($totalItems >= 10) {
            echo '<input class="search-box" type="text" placeholder="Rechercher..." oninput="filtrer(this.value)">';
        }

        // Toggle grille/liste (si dossiers présents)
        if ($hasGridItems) {
            echo '<button class="tb-icon" id="view-toggle" onclick="toggleView()" title="Basculer grille / liste">';
            echo '<svg id="view-icon-grid" width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>';
            echo '</button>';
        }

        // Settings gear
        echo '<div class="gear-wrap">';
        echo '<button class="tb-icon" id="gear-btn" onclick="toggleGear(event)" title="Préférences">';
        echo '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 01-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>';
        echo '</button>';
        echo '<div class="gear-panel" id="gear-panel">';
        // Comportement clic
        echo '<div class="gear-section"><div class="gear-label">Au clic sur une vidéo</div><div class="gear-row"><div class="gear-toggle" id="gt-click">';
        echo '<button class="gear-opt active" data-val="play" onclick="setPref(\'click\',\'play\',this)">Lire</button>';
        echo '<button class="gear-opt" data-val="download" onclick="setPref(\'click\',\'download\',this)">Télécharger</button>';
        echo '</div></div></div>';
        // Qualité par défaut
        echo '<div class="gear-section"><div class="gear-label">Qualité par défaut</div><div class="gear-row">';
        echo '<select class="gear-select" id="gs-quality" onchange="lsSet(\'pref_quality\',this.value)">';
        echo '<option value="480">480p</option><option value="720" selected>720p</option><option value="1080">1080p</option>';
        echo '</select></div></div>';
        // Langue audio
        echo '<div class="gear-section"><div class="gear-label">Audio préféré</div><div class="gear-row">';
        echo '<select class="gear-select" id="gs-audio" onchange="lsSet(\'pref_audio\',this.value)">';
        echo '<option value="">Auto</option><option value="fra">Français</option><option value="eng">English</option>';
        echo '</select></div></div>';
        // Sous-titres
        echo '<div class="gear-section"><div class="gear-label">Sous-titres préférés</div><div class="gear-row">';
        echo '<select class="gear-select" id="gs-subs" onchange="lsSet(\'pref_subs\',this.value)">';
        echo '<option value="off">Désactivés</option><option value="fra">Français</option><option value="eng">English</option>';
        echo '</select></div></div>';
        // Taille des cartes
        echo '<div class="gear-section"><div class="gear-label">Taille des cartes</div><div class="gear-row"><div class="gear-toggle" id="gt-cardsize">';
        echo '<button class="gear-opt" data-val="130" onclick="setCardSize(\'130\',this)">S</button>';
        echo '<button class="gear-opt active" data-val="180" onclick="setCardSize(\'180\',this)">M</button>';
        echo '<button class="gear-opt" data-val="240" onclick="setCardSize(\'240\',this)">L</button>';
        echo '</div></div></div>';
        echo '</div></div>'; // gear-panel, gear-wrap

        echo '<button class="btn-zip" style="background:transparent;border:1px solid var(--border);color:var(--text-secondary);padding:.38rem .55rem" onclick="reloadAllPosters()" title="Recharger tous les posters de ce dossier">';
        echo '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0115.34-6.36L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 01-15.34 6.36L3 16"/></svg>';
        echo '</button>';

        echo '<a class="btn-zip" href="' . $zipUrl . '">';
        echo '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';
        echo ' ZIP';
        echo '</a>';
        echo '</div>';
    }

    // ── Grid view (dossiers) ──
    if ($hasGridItems) {
        $gridHidden = isset($_GET['view']) && $_GET['view'] === 'grid' ? '' : ' hidden';
        echo '<div class="grid-wrap' . $gridHidden . '" id="grid-folders">';
        // Parent (..) en grille
        if ($subPath) {
            $parentPath = dirname($subPath);
            $parentUrl = $parentPath === '.' ? $baseUrl : $baseUrl . '?p=' . rawurlencode($parentPath);
            echo '<a class="grid-card" href="' . $parentUrl . '" style="background:rgba(255,255,255,.03)" data-type="folder" data-name="..">';
            echo '<div class="grid-card-bg"><div class="grid-card-letter" style="font-size:2rem">..</div></div>';
            echo '<div class="grid-card-label"><div class="grid-card-title">Retour</div></div>';
            echo '</a>';
        }
        // Look up folder types for all subfolders
        $folderTypes = [];
        if (!empty($folders)) {
            $paths = array_map(fn($f) => $dirPath . '/' . $f['name'], $folders);
            $placeholders = implode(',', array_fill(0, count($paths), '?'));
            $stmt = $db->prepare("SELECT path, folder_type FROM folder_posters WHERE path IN ($placeholders)");
            $stmt->execute($paths);
            foreach ($stmt->fetchAll() as $row) {
                $folderTypes[$row['path']] = $row['folder_type'] ?? 'series';
            }
        }
        foreach ($folders as $idx => $folder) {
            $folderHtml = htmlspecialchars($folder['name']);
            $folderPath = $subPath ? $subPath . '/' . $folder['name'] : $folder['name'];
            $folderUrl = $baseUrl . '?p=' . rawurlencode($folderPath);
            $color = $cardColors[$idx % count($cardColors)];
            $letter = mb_strtoupper(mb_substr($folder['name'], 0, 1));
            $hasVideo = $folder['has_video'] ?? false;
            $dataFolder = $hasVideo ? ' data-folder="' . $folderHtml . '"' : '';
            $folderFullPath = $dirPath . '/' . $folder['name'];
            $folderType = $folderTypes[$folderFullPath] ?? 'series';
            echo '<a class="grid-card" href="' . $folderUrl . '" style="background:' . $color . '" data-type="folder" data-name="' . $folderHtml . '"' . $dataFolder . ' data-folder-type="' . $folderType . '">';
            echo '<div class="grid-card-bg"><div class="grid-card-letter">' . htmlspecialchars($letter) . '</div></div>';
            echo '<div class="grid-card-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" opacity=".4"><path d="M2 6a2 2 0 012-2h5l2 2h9a2 2 0 012 2v10a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg></div>';
            if ($hasVideo) {
                $escapedName = htmlspecialchars(addcslashes($folder['name'], "'\\"), ENT_QUOTES);
                echo '<div class="grid-card-toggle" onclick="event.preventDefault();event.stopPropagation();togglePoster(this,\'' . $escapedName . '\')" title="Afficher/masquer l\'image"><svg class="eye-on" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg><svg class="eye-off" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg></div>';
                echo '<div class="grid-card-ctx" onclick="event.preventDefault();event.stopPropagation();toggleCardMenu(this,\'' . $escapedName . '\')" title="Options"><svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg></div>';
            }
            echo '<div class="grid-card-label"><div class="grid-card-title">' . $folderHtml . '</div></div>';
            echo '</a>';
        }
        // Video file cards (movies mode only)
        if ($isMoviesFolder) {
            foreach ($videoFiles as $idx => $vf) {
                $vfHtml = htmlspecialchars($vf['name']);
                $vfPath = $subPath ? $subPath . '/' . $vf['name'] : $vf['name'];
                $vfPlayUrl = $baseUrl . '?p=' . rawurlencode($vfPath) . '&play=1';
                $vfDownloadUrl = $baseUrl . '?p=' . rawurlencode($vfPath);
                $color = $cardColors[($idx + count($folders)) % count($cardColors)];
                $letter = mb_strtoupper(mb_substr($vf['name'], 0, 1));
                $escapedVfName = htmlspecialchars(addcslashes($vf['name'], "'\\"), ENT_QUOTES);
                echo '<a class="grid-card grid-card-file" href="' . $vfDownloadUrl . '" data-play="' . htmlspecialchars($vfPlayUrl, ENT_QUOTES) . '" style="background:' . $color . '" data-type="file" data-name="' . $vfHtml . '" data-folder="' . $vfHtml . '" data-size="' . $vf['size'] . '">';
                echo '<div class="grid-card-bg"><div class="grid-card-letter">' . htmlspecialchars($letter) . '</div></div>';
                echo '<div class="grid-card-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--green)"><rect x="2" y="4" width="20" height="16" rx="2"/><polygon points="10 9 15 12 10 15 10 9" fill="currentColor" stroke="none"/></svg></div>';
                echo '<div class="grid-card-toggle" onclick="event.preventDefault();event.stopPropagation();togglePoster(this,\'' . $escapedVfName . '\')" title="Afficher/masquer l\'image"><svg class="eye-on" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg><svg class="eye-off" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg></div>';
                echo '<div class="grid-card-ctx" onclick="event.preventDefault();event.stopPropagation();toggleCardMenu(this,\'' . $escapedVfName . '\')" title="Options"><svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg></div>';
                echo '<div class="grid-card-label"><div class="grid-card-title">' . $vfHtml . '</div></div>';
                echo '</a>';
            }
        }
        echo '</div>';
    }

    // ── List view (panel) ──
    echo '<div class="panel" id="list-panel">';

    // Lien parent (..)
    if ($subPath) {
        $parentPath = dirname($subPath);
        $parentUrl = $parentPath === '.' ? $baseUrl : $baseUrl . '?p=' . rawurlencode($parentPath);
        echo '<a class="row" href="' . $parentUrl . '">';
        echo '<div class="row-icon up"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19V5M5 12l7-7 7 7"/></svg></div>';
        echo '<span class="row-name is-folder">..</span>';
        echo '</a>';
    }

    // Dossiers
    foreach ($folders as $folder) {
        $folderHtml = htmlspecialchars($folder['name']);
        $folderPath = $subPath ? $subPath . '/' . $folder['name'] : $folder['name'];
        $folderUrl = $baseUrl . '?p=' . rawurlencode($folderPath);
        echo '<a class="row row-folder" href="' . $folderUrl . '" data-type="folder" data-name="' . $folderHtml . '">';
        echo '<div class="row-icon folder"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" style="color:var(--accent)"><path d="M2 6a2 2 0 012-2h5l2 2h9a2 2 0 012 2v10a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg></div>';
        echo '<span class="row-name is-folder">' . $folderHtml . '</span>';
        echo '</a>';
    }

    // Fichiers
    foreach ($files as $file) {
        $fileHtml = htmlspecialchars($file['name']);
        $filePath = $subPath ? $subPath . '/' . $file['name'] : $file['name'];
        $fileUrl = $baseUrl . '?p=' . rawurlencode($filePath);
        $playUrl = $baseUrl . '?p=' . rawurlencode($filePath) . '&play=1';
        $size = format_taille($file['size']);
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mediaType = get_media_type($file['name']);
        if ($mediaType === 'video') {
            $iconClass = 'vid';
            $iconSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--green)"><rect x="2" y="4" width="20" height="16" rx="2"/><polygon points="10 9 15 12 10 15 10 9" fill="currentColor" stroke="none"/></svg>';
        } elseif ($mediaType === 'audio') {
            $iconClass = 'aud';
            $iconSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:#ab47bc"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>';
        } elseif (in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','svg','tiff','heic','avif','raw','cr2','nef'])) {
            $iconClass = 'img';
            $iconSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:#26c6da"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';
        } elseif (in_array($ext, ['zip','rar','7z','tar','gz','bz2','xz','tgz','iso','cbz','cbr'])) {
            $iconClass = 'arc';
            $iconSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:#ef5350"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>';
        } else {
            $iconClass = 'file';
            $iconSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--blue)"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
        }
        // data-play stocke l'URL play pour le JS de clic configurable
        $dataPlay = $mediaType ? ' data-play="' . htmlspecialchars($playUrl, ENT_QUOTES) . '"' : '';
        echo '<a class="row" href="' . $fileUrl . '" title="' . $fileHtml . '" data-type="file" data-name="' . $fileHtml . '" data-size="' . $file['size'] . '"' . $dataPlay . '>';
        echo '<div class="row-icon ' . $iconClass . '">' . $iconSvg . '</div>';
        echo '<span class="row-name">' . $fileHtml . '</span>';
        if ($ext) echo '<span class="row-ext">' . htmlspecialchars($ext) . '</span>';
        echo '<span class="row-meta">' . $size . '</span>';
        if ($mediaType) {
            echo '<span class="btn-play" onclick="event.preventDefault();event.stopPropagation();location.href=\'' . htmlspecialchars($playUrl, ENT_QUOTES) . '\'" title="Lire"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg></span>';
        }
        echo '</a>';
    }

    if (empty($folders) && empty($files)) {
        echo '<div class="empty"><span class="empty-icon">&#x1F4ED;</span>Ce dossier est vide</div>';
    }

    // Inject baseUrl for JS
    $subPathJs = $subPath ? 'p=' . rawurlencode($subPath) . '&' : '';
    echo '</div></div>';
    echo '<script>var BASE_URL=' . json_encode($baseUrl) . ',SUB_PATH=' . json_encode($subPathJs) . ';</script>';
    echo <<<'HTML'
<script>
// ── localStorage helpers ──
function lsGet(k,d){try{var v=localStorage.getItem(k);return v!==null?v:d}catch(e){return d}}
function lsSet(k,v){try{localStorage.setItem(k,v)}catch(e){}}

// ── Preferences init ──
(function(){
    var q=lsGet('pref_quality','720'), a=lsGet('pref_audio',''), s=lsGet('pref_subs','off'), c=lsGet('pref_click','play');
    var el;
    el=document.getElementById('gs-quality'); if(el)el.value=q;
    el=document.getElementById('gs-audio');   if(el)el.value=a;
    el=document.getElementById('gs-subs');    if(el)el.value=s;
    // Click toggle
    document.querySelectorAll('#gt-click .gear-opt').forEach(function(b){
        b.classList.toggle('active',b.dataset.val===c);
    });
    // Card size
    var cs=lsGet('pref_cardsize','180');
    document.querySelectorAll('#gt-cardsize .gear-opt').forEach(function(b){ b.classList.toggle('active',b.dataset.val===cs); });
    var gw=document.getElementById('grid-folders');
    if(gw) gw.style.setProperty('--card-size',cs+'px');
    // View mode
    var vm=lsGet('pref_view','list');
    if(vm==='grid') applyView('grid');
    // Click behavior: redirect video file rows
    if(c==='play'){
        document.querySelectorAll('.row[data-play]').forEach(function(r){
            r.href=r.dataset.play;
        });
        document.querySelectorAll('.grid-card-file[data-play]').forEach(function(r){
            r.href=r.dataset.play;
        });
    }
})();

// ── Settings gear ──
function toggleGear(e){
    e.stopPropagation();
    var p=document.getElementById('gear-panel');
    p.classList.toggle('open');
    document.getElementById('gear-btn').classList.toggle('active',p.classList.contains('open'));
}
document.addEventListener('click',function(e){
    var p=document.getElementById('gear-panel');
    if(p && !e.target.closest('.gear-wrap')) { p.classList.remove('open'); document.getElementById('gear-btn').classList.remove('active'); }
});
function setPref(key,val,btn){
    lsSet('pref_'+key,val);
    btn.parentNode.querySelectorAll('.gear-opt').forEach(function(b){b.classList.remove('active')});
    btn.classList.add('active');
    if(key==='click'){
        document.querySelectorAll('.row[data-play], .grid-card-file[data-play]').forEach(function(r){
            r.href = val==='play' ? r.dataset.play : r.href.split('&play=')[0].split('?play=')[0];
        });
        if(val==='download'){
            document.querySelectorAll('.row[data-play], .grid-card-file[data-play]').forEach(function(r){
                r.href=r.href.replace(/[&?]play=1/,'');
            });
        }
    }
}

// ── Grid/List toggle ──
function toggleView(){
    var grid=document.getElementById('grid-folders');
    if(!grid) return;
    var isGrid=!grid.classList.contains('hidden');
    applyView(isGrid?'list':'grid');
    lsSet('pref_view',isGrid?'list':'grid');
}
function applyView(mode){
    var grid=document.getElementById('grid-folders');
    var panel=document.getElementById('list-panel');
    var toggle=document.getElementById('view-toggle');
    if(!grid) return;
    if(mode==='grid'){
        grid.classList.remove('hidden');
        panel.querySelectorAll('.row-folder').forEach(function(r){r.style.display='none'});
        document.querySelectorAll('.grid-card-file').forEach(function(gc){
            var name = gc.dataset.name;
            panel.querySelectorAll('.row[data-type="file"]').forEach(function(r){
                if(r.dataset.name === name) r.style.display='none';
            });
        });
        var upRow=panel.querySelector('.row:not([data-type])');
        if(upRow) upRow.style.display='none';
        if(toggle) toggle.classList.add('active');
    } else {
        grid.classList.add('hidden');
        panel.querySelectorAll('.row-folder').forEach(function(r){r.style.display=''});
        panel.querySelectorAll('.row[data-type="file"]').forEach(function(r){r.style.display=''});
        var upRow=panel.querySelector('.row:not([data-type])');
        if(upRow) upRow.style.display='';
        if(toggle) toggle.classList.remove('active');
    }
}

// ── Sort & filter ──
function tri(btn, key) {
    document.querySelectorAll('.sort-btn').forEach(b => b.classList.remove('active','desc'));
    const panel = document.getElementById('list-panel');
    const rows = [...panel.querySelectorAll('.row[data-type]')];
    const folders = rows.filter(r => r.dataset.type === 'folder');
    const files = rows.filter(r => r.dataset.type === 'file');
    const prev = btn.dataset.dir || 'asc';
    const dir = btn.dataset.lastKey === key ? (prev === 'asc' ? 'desc' : 'asc') : 'asc';
    btn.dataset.dir = dir;
    btn.dataset.lastKey = key;
    btn.classList.add('active');
    if (dir === 'desc') btn.classList.add('desc');
    const mul = dir === 'asc' ? 1 : -1;
    files.sort((a, b) => {
        if (key === 'size') return mul * (parseInt(a.dataset.size || 0) - parseInt(b.dataset.size || 0));
        return mul * a.dataset.name.localeCompare(b.dataset.name, 'fr', {numeric: true, sensitivity: 'base'});
    });
    const upLink = panel.querySelector('.row:not([data-type])');
    if (upLink) panel.appendChild(upLink);
    folders.forEach(f => panel.appendChild(f));
    files.forEach(f => panel.appendChild(f));
    // Also sort grid cards
    const grid = document.getElementById('grid-folders');
    if (grid) {
        const cards = [...grid.querySelectorAll('.grid-card[data-name]')];
        const folderCards = cards.filter(c => c.dataset.type === 'folder' && c.dataset.name !== '..');
        const fileCards = cards.filter(c => c.dataset.type === 'file');
        folderCards.sort((a, b) => mul * a.dataset.name.localeCompare(b.dataset.name, 'fr', {numeric: true, sensitivity: 'base'}));
        fileCards.sort((a, b) => {
            if (key === 'size') return mul * (parseInt(a.dataset.size || 0) - parseInt(b.dataset.size || 0));
            return mul * a.dataset.name.localeCompare(b.dataset.name, 'fr', {numeric: true, sensitivity: 'base'});
        });
        folderCards.forEach(c => grid.appendChild(c));
        fileCards.forEach(c => grid.appendChild(c));
    }
}
function filtrer(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.row[data-type="file"]').forEach(r => {
        r.classList.toggle('hidden', q && !r.dataset.name.toLowerCase().includes(q));
    });
    document.querySelectorAll('.row[data-type="folder"]').forEach(r => {
        r.classList.toggle('hidden', q && !r.dataset.name.toLowerCase().includes(q));
    });
    // Also filter grid cards
    document.querySelectorAll('.grid-card[data-type="folder"]').forEach(r => {
        r.classList.toggle('hidden', q && !r.dataset.name.toLowerCase().includes(q));
    });
    document.querySelectorAll('.grid-card[data-type="file"]').forEach(r => {
        r.classList.toggle('hidden', q && !r.dataset.name.toLowerCase().includes(q));
    });
}

// ── Card size ──
function setCardSize(val,btn){
    lsSet('pref_cardsize',val);
    btn.parentNode.querySelectorAll('.gear-opt').forEach(function(b){b.classList.remove('active')});
    btn.classList.add('active');
    var gw=document.getElementById('grid-folders');
    if(gw) gw.style.setProperty('--card-size',val+'px');
}

// ── Toggle poster on/off ──
function togglePoster(btn, folderName) {
    var card = btn.closest('.grid-card');
    if (!card) return;
    var bg = card.querySelector('.grid-card-bg');
    if (!bg) return;
    function updateEyeIcon(b, hasPoster) {
        var on = b.querySelector('.eye-on'), off = b.querySelector('.eye-off');
        if (on) on.style.display = hasPoster ? '' : 'none';
        if (off) off.style.display = hasPoster ? 'none' : '';
    }
    if (card.classList.contains('has-poster')) {
        bg.style.backgroundImage = '';
        card.classList.remove('has-poster');
        var ov = card.querySelector('.grid-card-overview');
        if (ov) ov.remove();
        updateEyeIcon(btn, false);
        selectPoster(folderName, '__none__', 0, '', '');
    } else {
        // Reset __none__ to NULL — daemon will re-fetch poster
        var url = BASE_URL + '?' + SUB_PATH + 'tmdb_set=1';
        fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({folder: folderName, poster_url: null, tmdb_id: 0, title: '', overview: ''})
        }).then(function(){
            updateEyeIcon(btn, true);
            // Start polling — daemon will fill the poster in ~10s
            fetchPosters.polls = 0;
            fetchPosters.pending = true;
            setTimeout(fetchPosters, 5000);
        }).catch(function(){});
    }
}

// ── Reload all posters ──
function reloadAllPosters() {
    if (!confirm('Supprimer le cache posters de ce dossier et tout re-chercher sur TMDB ?')) return;
    var url = BASE_URL + '?' + SUB_PATH + 'tmdb_reload=1';
    fetch(url, {method:'POST', credentials:'same-origin'})
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.success) location.reload();
        })
        .catch(function(){ alert('Erreur'); });
}

// ── TMDB poster fetching ──
(function(){
    var cards = document.querySelectorAll('.grid-card[data-folder]');
    if (!cards.length) return;
    function fetchPosters() {
        var url = BASE_URL + '?' + SUB_PATH + 'posters=1';
        fetch(url, {credentials:'same-origin'})
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (!d.posters) return;
                Object.keys(d.posters).forEach(function(name){
                    var info = d.posters[name];
                    var card = document.querySelector('.grid-card[data-folder="'+CSS.escape(name)+'"]');
                    if (!card) return;
                    // Dossier masqué → œil barré
                    if (info.hidden) {
                        var toggleBtn = card.querySelector('.grid-card-toggle');
                        if (toggleBtn) {
                            var on = toggleBtn.querySelector('.eye-on'), off = toggleBtn.querySelector('.eye-off');
                            if (on) on.style.display = 'none';
                            if (off) off.style.display = '';
                        }
                        return;
                    }
                    // En attente de vérification IA → badge overlay
                    if (info.pending_ai) {
                        if (!card.querySelector('.grid-card-ai-pending')) {
                            var ai = document.createElement('div');
                            ai.className = 'grid-card-ai-pending';
                            var aiSvg = document.createElementNS('http://www.w3.org/2000/svg','svg');
                            aiSvg.setAttribute('viewBox','0 0 24 24'); aiSvg.setAttribute('fill','none');
                            aiSvg.setAttribute('stroke','currentColor'); aiSvg.setAttribute('stroke-width','2');
                            var aiPath = document.createElementNS('http://www.w3.org/2000/svg','path');
                            aiPath.setAttribute('d','M21 12a9 9 0 11-6.22-8.57');
                            aiSvg.appendChild(aiPath);
                            var aiText = document.createElement('span');
                            aiText.textContent = 'V\u00e9rification IA\nen attente';
                            ai.appendChild(aiSvg); ai.appendChild(aiText);
                            card.appendChild(ai);
                        }
                        return;
                    }
                    // Si le badge AI pending existait, le retirer (IA a traité)
                    var oldAi = card.querySelector('.grid-card-ai-pending');
                    if (oldAi) oldAi.remove();
                    var poster = typeof info === 'string' ? info : info.poster;
                    var overview = typeof info === 'object' ? info.overview : null;
                    var bg = card.querySelector('.grid-card-bg');
                    if (bg) {
                        bg.style.backgroundImage = 'url(' + poster + ')';
                        card.classList.add('has-poster');
                    }
                    // Ajouter l'overlay résumé au hover
                    if (overview && !card.querySelector('.grid-card-overview')) {
                        var ov = document.createElement('div');
                        ov.className = 'grid-card-overview';
                        var ovTitle = document.createElement('div');
                        ovTitle.className = 'grid-card-overview-title';
                        ovTitle.textContent = name;
                        var ovText = document.createElement('div');
                        ovText.className = 'grid-card-overview-text';
                        ovText.textContent = overview;
                        ov.appendChild(ovTitle);
                        ov.appendChild(ovText);
                        card.appendChild(ov);
                    }
                });
                fetchPosters.pending = d.pending > 0;
                if (d.pending > 0) {
                    fetchPosters.polls = (fetchPosters.polls || 0) + 1;
                    setTimeout(fetchPosters, fetchPosters.polls <= 3 ? 5000 : 30000);
                }
            })
            .catch(function(){});
    }
    fetchPosters();
    // Re-poll when tab becomes visible (mobile browsers throttle background timers)
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden && fetchPosters.pending) fetchPosters();
    });
})();

// ── Poster picker modal ──
function openPosterPicker(folderName) {
    var old = document.querySelector('.poster-modal');
    if (old) old.remove();
    var modal = document.createElement('div');
    modal.className = 'poster-modal';
    var card = document.createElement('div');
    card.className = 'poster-modal-card';
    var h3 = document.createElement('h3');
    h3.textContent = folderName;
    card.appendChild(h3);
    // Search bar
    var searchRow = document.createElement('div');
    searchRow.style.cssText = 'display:flex;gap:.4rem;margin-bottom:.7rem';
    var searchInput = document.createElement('input');
    searchInput.className = 'search-box';
    searchInput.style.cssText = 'flex:1;width:auto';
    searchInput.placeholder = 'Rechercher sur TMDB...';
    // Nettoyer le nom pour la recherche : retirer extension, tags techniques, crochets, tirets de release
    var cleanName = folderName.replace(/\.(mkv|avi|mp4|m4v|mov|wmv|flv|webm|ts|m2ts|mpg|mpeg)$/i, '');
    cleanName = cleanName.replace(/[\[\(].*?[\]\)]/g, ''); // [Torrent911], (2024), etc.
    cleanName = cleanName.replace(/\b(MULTI|VOSTFR|VFF|VF2?|FRENCH|TRUEFRENCH|SUBFRENCH|ENGLISH|MULTi)\b.*/i, '');
    cleanName = cleanName.replace(/\b(BluRay|BDRip|WEB[-.]?DL|WEB[-.]?Rip|HDRip|DVDRip|DVDRIP|HDTV|WEB|Remux|REPACK)\b.*/i, '');
    cleanName = cleanName.replace(/\b(x264|x265|h264|h265|HEVC|AVC|AAC|AC3|DTS|FLAC|10bit|HDR|HDR10|SDR|2160p|1080p|720p|480p|4K|UHD)\b.*/i, '');
    cleanName = cleanName.replace(/[-._]+/g, ' ').replace(/\s{2,}/g, ' ').trim();
    searchInput.value = cleanName || folderName;
    // Type toggle : Tous / Séries / Films
    var typeRow = document.createElement('div');
    typeRow.style.cssText = 'display:flex;gap:.3rem;margin-bottom:.5rem';
    var currentType = 'multi';
    ['multi', 'tv', 'movie'].forEach(function(t) {
        var btn = document.createElement('button');
        btn.className = 'poster-modal-close';
        btn.style.cssText = 'flex:1;margin:0;padding:.25rem .4rem;font-size:.72rem;' + (t === 'multi' ? 'background:rgba(240,160,48,.15);border-color:var(--accent);color:var(--accent);' : '');
        btn.textContent = t === 'multi' ? 'Tous' : (t === 'tv' ? 'S\u00e9ries' : 'Films');
        btn.dataset.tmdbType = t;
        btn.onclick = function() {
            currentType = t;
            typeRow.querySelectorAll('button').forEach(function(b) {
                b.style.background = b.dataset.tmdbType === t ? 'rgba(240,160,48,.15)' : '';
                b.style.borderColor = b.dataset.tmdbType === t ? 'var(--accent)' : '';
                b.style.color = b.dataset.tmdbType === t ? 'var(--accent)' : '';
            });
            doSearch(searchInput.value);
        };
        typeRow.appendChild(btn);
    });
    card.appendChild(typeRow);
    var searchBtn = document.createElement('button');
    searchBtn.className = 'btn-zip';
    searchBtn.style.cssText = 'padding:.3rem .7rem;font-size:.78rem';
    searchBtn.textContent = 'Chercher';
    searchRow.appendChild(searchInput);
    searchRow.appendChild(searchBtn);
    card.appendChild(searchRow);
    var grid = document.createElement('div');
    grid.className = 'poster-modal-grid';
    grid.textContent = 'Recherche...';
    card.appendChild(grid);
    var btnRow = document.createElement('div');
    btnRow.style.cssText = 'display:flex;gap:.5rem;margin-top:1rem';
    var noneBtn = document.createElement('button');
    noneBtn.className = 'poster-modal-close';
    noneBtn.style.cssText = 'color:var(--red);border-color:rgba(239,83,80,.2)';
    noneBtn.textContent = 'Pas d\'image';
    noneBtn.onclick = function(){ selectPoster(folderName, '__none__', 0, '', ''); modal.remove(); };
    var closeBtn = document.createElement('button');
    closeBtn.className = 'poster-modal-close';
    closeBtn.textContent = 'Fermer';
    closeBtn.onclick = function(){ modal.remove(); };
    btnRow.appendChild(noneBtn);
    btnRow.appendChild(closeBtn);
    card.appendChild(btnRow);
    modal.appendChild(card);
    modal.addEventListener('click', function(e){ if (e.target === modal) modal.remove(); });
    document.body.appendChild(modal);
    searchInput.focus();
    searchInput.select();
    function doSearch(query) {
        grid.textContent = 'Recherche...';
        var url = BASE_URL + '?' + SUB_PATH + 'tmdb_search=' + encodeURIComponent(query) + '&tmdb_type=' + currentType;
        fetch(url, {credentials:'same-origin'})
            .then(function(r){ return r.json(); })
            .then(function(results){ renderResults(results, folderName, modal, grid); })
            .catch(function(){ grid.textContent = 'Erreur de recherche'; });
    }
    searchBtn.onclick = function(){ doSearch(searchInput.value); };
    searchInput.addEventListener('keydown', function(e){ if (e.key === 'Enter') doSearch(searchInput.value); });
    doSearch(folderName);
}
function renderResults(results, folderName, modal, grid) {
    grid.textContent = '';
    if (!results.length) { grid.textContent = 'Aucun résultat. Essayez un autre nom.'; return; }
    results.forEach(function(r){
        var item = document.createElement('div');
        item.className = 'poster-modal-item';
        item.onclick = function(){ selectPoster(folderName, r.poster_w300, r.id, r.title, r.overview); modal.remove(); };
        var img = document.createElement('img');
        img.src = r.poster;
        img.alt = r.title;
        img.loading = 'lazy';
        item.appendChild(img);
        var info = document.createElement('div');
        info.className = 'poster-modal-info';
        info.textContent = r.title + (r.year ? ' (' + r.year + ')' : '');
        item.appendChild(info);
        grid.appendChild(item);
    });
}
function selectPoster(folderName, posterUrl, tmdbId, title, overview) {
    var url = BASE_URL + '?' + SUB_PATH + 'tmdb_set=1';
    fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({folder: folderName, poster_url: posterUrl, tmdb_id: tmdbId, title: title, overview: overview || ''})
    }).catch(function(){});
    var card = document.querySelector('.grid-card[data-folder="'+CSS.escape(folderName)+'"]');
    if (!card) return;
    var bg = card.querySelector('.grid-card-bg');
    // Supprimer l'ancien overlay
    var oldOv = card.querySelector('.grid-card-overview');
    if (oldOv) oldOv.remove();
    if (posterUrl === '__none__') {
        if (bg) { bg.style.backgroundImage = ''; }
        card.classList.remove('has-poster');
    } else {
        if (bg) { bg.style.backgroundImage = 'url(' + posterUrl + ')'; }
        card.classList.add('has-poster');
        if (overview) {
            var ov = document.createElement('div');
            ov.className = 'grid-card-overview';
            var ovTitle = document.createElement('div');
            ovTitle.className = 'grid-card-overview-title';
            ovTitle.textContent = title || folderName;
            var ovText = document.createElement('div');
            ovText.className = 'grid-card-overview-text';
            ovText.textContent = overview;
            ov.appendChild(ovTitle);
            ov.appendChild(ovText);
            card.appendChild(ov);
        }
    }
}

// ── Card dropdown menu ──
function toggleCardMenu(btn, folderName) {
    var old = document.querySelector('.grid-card-menu');
    if (old) { old.remove(); }
    if (btn.dataset.menuOpen === '1') { btn.dataset.menuOpen = ''; return; }
    document.querySelectorAll('.grid-card-ctx').forEach(function(b){ b.dataset.menuOpen = ''; });
    btn.dataset.menuOpen = '1';

    var menu = document.createElement('div');
    menu.className = 'grid-card-menu open';

    // Item 1: Changer le poster
    var item1 = document.createElement('div');
    item1.className = 'grid-card-menu-item';
    var svg1 = document.createElementNS('http://www.w3.org/2000/svg','svg');
    svg1.setAttribute('viewBox','0 0 24 24'); svg1.setAttribute('fill','none');
    svg1.setAttribute('stroke','currentColor'); svg1.setAttribute('stroke-width','2');
    var rect1 = document.createElementNS('http://www.w3.org/2000/svg','rect');
    rect1.setAttribute('x','3'); rect1.setAttribute('y','3'); rect1.setAttribute('width','18'); rect1.setAttribute('height','18'); rect1.setAttribute('rx','2');
    var circle1 = document.createElementNS('http://www.w3.org/2000/svg','circle');
    circle1.setAttribute('cx','8.5'); circle1.setAttribute('cy','8.5'); circle1.setAttribute('r','1.5');
    var poly1 = document.createElementNS('http://www.w3.org/2000/svg','polyline');
    poly1.setAttribute('points','21 15 16 10 5 21');
    svg1.appendChild(rect1); svg1.appendChild(circle1); svg1.appendChild(poly1);
    item1.appendChild(svg1);
    var span1 = document.createElement('span');
    span1.textContent = 'Changer le poster';
    item1.appendChild(span1);
    item1.onclick = function(e) { e.preventDefault(); e.stopPropagation(); menu.remove(); btn.dataset.menuOpen = ''; openPosterPicker(folderName); };
    menu.appendChild(item1);

    var card = btn.closest('.grid-card');

    // Item 2: Folder type toggle (only for folder cards)
    if (card && card.dataset.type === 'folder') {
        var currentType = card.dataset.folderType || 'series';
        var item2 = document.createElement('div');
        item2.className = 'grid-card-menu-item';
        var nextType = currentType === 'movies' ? 'series' : 'movies';
        // Icon: TV for series, film clap for movies (shows target type)
        var svg2 = document.createElementNS('http://www.w3.org/2000/svg','svg');
        svg2.setAttribute('viewBox','0 0 24 24'); svg2.setAttribute('fill','none');
        svg2.setAttribute('stroke','currentColor'); svg2.setAttribute('stroke-width','2');
        if (nextType === 'movies') {
            // Film clap icon → "passer en films"
            var p2a = document.createElementNS('http://www.w3.org/2000/svg','path');
            p2a.setAttribute('d','M4 20h16a2 2 0 002-2V8H2v10a2 2 0 002 2z');
            var p2b = document.createElementNS('http://www.w3.org/2000/svg','path');
            p2b.setAttribute('d','M2 8l4-4h4l-4 4M10 8l4-4h4l-4 4M18 8l4-4');
            svg2.appendChild(p2a); svg2.appendChild(p2b);
        } else {
            // TV icon → "passer en séries"
            var r2 = document.createElementNS('http://www.w3.org/2000/svg','rect');
            r2.setAttribute('x','2'); r2.setAttribute('y','7'); r2.setAttribute('width','20'); r2.setAttribute('height','15'); r2.setAttribute('rx','2');
            var p2a = document.createElementNS('http://www.w3.org/2000/svg','polyline');
            p2a.setAttribute('points','17 2 12 7 7 2');
            svg2.appendChild(r2); svg2.appendChild(p2a);
        }
        item2.appendChild(svg2);
        var span2 = document.createElement('span');
        span2.textContent = nextType === 'movies' ? 'Passer en mode Films' : 'Passer en mode S\u00e9ries';
        item2.appendChild(span2);
        item2.onclick = function(e) {
            e.preventDefault(); e.stopPropagation();
            menu.remove(); btn.dataset.menuOpen = '';
            setFolderType(folderName, nextType, card);
        };
        menu.appendChild(item2);
    }

    // Item 3: AI recheck — visible avec ou sans poster (sauf si masqué par l'utilisateur)
    var eyeOff = card ? card.querySelector('.eye-off') : null;
    var isHidden = eyeOff && eyeOff.style.display !== 'none';
    if (card && !isHidden) {
        var item3 = document.createElement('div');
        item3.className = 'grid-card-menu-item';
        var svg3 = document.createElementNS('http://www.w3.org/2000/svg','svg');
        svg3.setAttribute('viewBox','0 0 24 24'); svg3.setAttribute('fill','none');
        svg3.setAttribute('stroke','currentColor'); svg3.setAttribute('stroke-width','2');
        var path3 = document.createElementNS('http://www.w3.org/2000/svg','path');
        path3.setAttribute('d','M21 2v6h-6');
        var path3b = document.createElementNS('http://www.w3.org/2000/svg','path');
        path3b.setAttribute('d','M21 13a9 9 0 11-3-7.7L21 8');
        svg3.appendChild(path3); svg3.appendChild(path3b);
        item3.appendChild(svg3);
        var span3 = document.createElement('span');
        span3.textContent = card.classList.contains('has-poster') ? 'V\u00e9rifier avec l\u2019IA' : 'Chercher avec l\u2019IA';
        item3.appendChild(span3);
        item3.onclick = function(e) {
            e.preventDefault(); e.stopPropagation();
            menu.remove(); btn.dataset.menuOpen = '';
            requestAIRecheck(folderName);
        };
        menu.appendChild(item3);
    }

    card.appendChild(menu);
}

function setFolderType(folderName, type, card) {
    var url = BASE_URL + '?' + SUB_PATH + 'folder_type_set=1';
    fetch(url, {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({folder: folderName, folder_type: type})
    }).then(function(r){ return r.json(); }).then(function(d){
        if (d.success && card) {
            card.dataset.folderType = type;
        }
    }).catch(function(){});
}

function requestAIRecheck(name) {
    var url = BASE_URL + '?' + SUB_PATH + 'ai_recheck=1';
    fetch(url, {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({name: name})
    }).then(function(r){ return r.json(); }).then(function(d){
        if (d.success) {
            var card = document.querySelector('.grid-card[data-folder="'+CSS.escape(name)+'"]');
            if (card) {
                // Retirer le poster et ajouter le badge AI pending
                card.classList.remove('has-poster');
                var bg = card.querySelector('.grid-card-bg');
                if (bg) bg.style.backgroundImage = '';
                var oldOv = card.querySelector('.grid-card-overview');
                if (oldOv) oldOv.remove();
                if (!card.querySelector('.grid-card-ai-pending')) {
                    var ai = document.createElement('div');
                    ai.className = 'grid-card-ai-pending';
                    var aiSvg = document.createElementNS('http://www.w3.org/2000/svg','svg');
                    aiSvg.setAttribute('viewBox','0 0 24 24'); aiSvg.setAttribute('fill','none');
                    aiSvg.setAttribute('stroke','currentColor'); aiSvg.setAttribute('stroke-width','2');
                    var aiPath = document.createElementNS('http://www.w3.org/2000/svg','path');
                    aiPath.setAttribute('d','M21 12a9 9 0 11-6.22-8.57');
                    aiSvg.appendChild(aiPath);
                    var aiText = document.createElement('span');
                    aiText.textContent = 'V\u00e9rification IA\nen attente';
                    ai.appendChild(aiSvg); ai.appendChild(aiText);
                    card.appendChild(ai);
                }
            }
        }
    }).catch(function(){});
}

// Close menu on outside click
document.addEventListener('click', function(e) {
    if (!e.target.closest('.grid-card-ctx') && !e.target.closest('.grid-card-menu')) {
        var m = document.querySelector('.grid-card-menu');
        if (m) m.remove();
        document.querySelectorAll('.grid-card-ctx').forEach(function(b){ b.dataset.menuOpen = ''; });
    }
});
</script>
</body>
</html>
HTML;
}


/**
 * Affiche le formulaire de mot de passe
 */
function afficher_formulaire_mdp(string $name, string $erreur = ''): void {
    $nameHtml = htmlspecialchars($name);
    $css = css_public();
    $erreurHtml = $erreur
        ? '<div class="error-msg">' . htmlspecialchars($erreur) . '</div>'
        : '';

    echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="/share/favicon.svg">
    <title>Accès protégé</title>
    <style>{$css}
    .page { position: relative; z-index: 1; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 1.5rem; }
    .card { background: rgba(26,29,40,.85); border: 1px solid rgba(255,255,255,.08); border-radius: var(--radius-lg); backdrop-filter: blur(16px); padding: 2.5rem 2rem; max-width: 380px; width: 100%; text-align: center; }
    .card-icon { width: 56px; height: 56px; background: var(--accent-soft); border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.2rem; font-size: 1.6rem; }
    .card h2 { font-size: 1.2rem; font-weight: 700; margin-bottom: .3rem; color: #fff; }
    .card .filename { color: var(--text-secondary); font-size: .88rem; margin-bottom: 1.5rem; word-break: break-all; }
    .error-msg { background: rgba(239,83,80,.1); border: 1px solid rgba(239,83,80,.15); color: var(--red); border-radius: var(--radius-sm); padding: .5rem .8rem; font-size: .85rem; margin-bottom: 1rem; }
    input[type=password] { width: 100%; padding: .75rem 1rem; border: 1px solid rgba(255,255,255,.1); border-radius: var(--radius-md); background: var(--bg-deep); color: var(--text-primary); font-family: var(--font-sans); font-size: .95rem; margin-bottom: .8rem; outline: none; transition: border-color .15s; }
    input[type=password]:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }
    input[type=password]::placeholder { color: var(--text-muted); }
    button[type=submit] { width: 100%; padding: .75rem; border: none; border-radius: var(--radius-md); background: var(--accent); color: var(--bg-deep); font-family: var(--font-sans); font-size: .95rem; font-weight: 700; cursor: pointer; transition: all .15s; }
    button[type=submit]:hover { background: #ffc060; box-shadow: 0 4px 16px rgba(240,160,48,.25); }
    button[type=submit]:active { transform: scale(.98); }
    </style>
</head>
<body>
<div class="page">
    <div class="card">
        <div class="card-icon">&#x1F512;</div>
        <h2>Accès protégé</h2>
        <p class="filename">{$nameHtml}</p>
        {$erreurHtml}
        <form method="POST">
            <input type="password" name="password" placeholder="Mot de passe" autofocus required>
            <button type="submit">Accéder</button>
        </form>
    </div>
</div>
</body>
</html>
HTML;
}


/**
 * Affiche la page du player video/audio
 */
function afficher_player(string $token, string $shareName, string $subPath, string $mediaType, string $basePath = ''): void {
    $baseUrl = '/dl/' . htmlspecialchars($token);
    $fileName = $subPath ? basename($subPath) : $shareName;
    $fileNameHtml = htmlspecialchars($fileName);

    // Base des URLs avec le sous-chemin
    $pParam = $subPath ? 'p=' . rawurlencode($subPath) . '&amp;' : '';
    $pParamJs = $subPath ? 'p=' . rawurlencode($subPath) . '&' : '';

    $dlUrl = $subPath
        ? $baseUrl . '?p=' . rawurlencode($subPath)
        : $baseUrl;

    $isVideo = $mediaType === 'video' ? 'true' : 'false';

    // URL retour vers le listing parent
    if ($subPath && str_contains($subPath, '/')) {
        $backUrl = $baseUrl . '?p=' . rawurlencode(dirname($subPath));
    } elseif ($subPath) {
        $backUrl = $baseUrl;
    } else {
        $backUrl = null;
    }

    // ── Épisodes voisins (prev/next) ────────────────────────────────────────
    $prevFile = null;
    $nextFile = null;
    if ($subPath && $basePath && $mediaType === 'video') {
        $nav = computeEpisodeNav($subPath, $basePath, $baseUrl);
        $prevFile = $nav['prev'];
        $nextFile = $nav['next'];
    }
    $episodeNavJson = json_encode(['prev' => $prevFile, 'next' => $nextFile], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    if ($prevFile || $nextFile) {
        stream_log('EPISODE NAV | ' . basename($subPath) . ' | prev=' . ($prevFile ? $prevFile['name'] : 'none') . ' next=' . ($nextFile ? $nextFile['name'] : 'none'));
    }

    $tag = $mediaType === 'video' ? 'video' : 'audio';
    $controlsAttr = $mediaType === 'audio' ? 'controls' : '';
    $backHtml = $backUrl
        ? '<a class="player-btn" href="' . $backUrl . '"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg> Retour</a>'
        : '';

    // Boutons épisode prev/next
    $prevBtnHtml = $prevFile
        ? '<a class="player-btn player-nav-btn ep-prev" href="' . htmlspecialchars($prevFile['url']) . '" title="' . htmlspecialchars($prevFile['name']) . '"><svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M6 6h2v12H6zm3.5 6l8.5 6V6z"/></svg></a>'
        : '';
    $nextBtnHtml = $nextFile
        ? '<a class="player-btn player-nav-btn ep-next" href="' . htmlspecialchars($nextFile['url']) . '" title="' . htmlspecialchars($nextFile['name']) . '"><svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M16 18h2V6h-2zM6 18l8.5-6L6 6z"/></svg></a>'
        : '';

    $remuxEnabled = STREAM_REMUX_ENABLED ? 'true' : 'false';
    $jsMtime = filemtime(__DIR__ . '/player.js');
    $cssMtime = filemtime(__DIR__ . '/player.css');
    header('Cache-Control: no-store');

    echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <link rel="icon" type="image/svg+xml" href="/share/favicon.svg">
    <title>{$fileNameHtml}</title>
    <link rel="stylesheet" href="/share/player.css?v={$cssMtime}">
</head>
<body>
<div class="page">
    <div class="player-toolbar">
        {$backHtml}
        {$prevBtnHtml}
        <span class="player-name" title="{$fileNameHtml}">{$fileNameHtml}</span>
        {$nextBtnHtml}
        <a class="player-btn accent" href="{$dlUrl}"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg><span> Télécharger</span></a>
    </div>
    <div class="player-card">
        <div class="fs-title" id="fs-title">{$prevBtnHtml}<span class="fs-title-text">{$fileNameHtml}</span>{$nextBtnHtml}</div>
        <div class="player-video-wrap">
            <{$tag} id="player" {$controlsAttr} autoplay playsinline webkit-playsinline preload="metadata"></{$tag}>
            <div class="player-hint" id="hint"><span class="player-hint-text">Chargement...</span></div>
            <div id="video-click-area" style="position:absolute;top:0;right:0;bottom:0;left:0;z-index:6;cursor:pointer;outline:none;-webkit-tap-highlight-color:transparent;user-select:none"></div>
            <div id="play-icon-overlay" class="play-icon-overlay"></div>
            <div id="vol-osd"></div>
        </div>
        <div class="player-controls">
            <div class="seek-time" id="seek-time" style="display:none">
                <span class="current" id="time-current">0:00</span>
                <span class="sep">/</span>
                <span id="time-total">0:00</span>
            </div>
            <div class="seek-bar" id="seek-bar" style="display:none">
                <div class="seek-track"></div>
                <div class="seek-buffered" id="seek-buffered"></div>
                <div class="seek-fill" id="seek-fill"></div>
                <div class="seek-thumb" id="seek-thumb"></div>
                <div class="seek-tooltip" id="seek-tooltip"></div>
            </div>
            <div class="ctrl-row" id="ctrl-row" style="display:none">
                <div class="ctrl-spacer"></div>
                <button class="ctrl-play" id="play-btn" title="Lecture / Pause">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                </button>
                <div class="vol-wrap">
                    <button class="ctrl-mute" id="mute-btn" title="Muet / Son">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
                    </button>
                    <input type="range" id="vol-slider" class="vol-slider" min="0" max="1" step="0.05" value="1" title="Volume">
                </div>
                <button class="ctrl-mute" id="speed-btn" title="Vitesse de lecture" style="font-size:.7rem;font-weight:700;font-family:var(--font-mono);width:auto;padding:0 .45rem;border-radius:20px;">1×</button>
                <button class="ctrl-mute" id="zoom-btn" title="Zoom (Fit/Fill/Stretch)">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M6.5 12a5.5 5.5 0 1 0 0-11 5.5 5.5 0 0 0 0 11zM13 6.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0z"/><path d="M10.344 11.742c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1 6.538 6.538 0 0 1-1.398 1.4z"/><path fill-rule="evenodd" d="M6.5 3a.5.5 0 0 1 .5.5V6h2.5a.5.5 0 0 1 0 1H7v2.5a.5.5 0 0 1-1 0V7H3.5a.5.5 0 0 1 0-1H6V3.5a.5.5 0 0 1 .5-.5z"/></svg>
                </button>
                <button class="ctrl-mute" id="pip-btn" title="Picture-in-Picture" style="display:none">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><rect x="12" y="9" width="8" height="6" rx="1" fill="currentColor" opacity=".3"/></svg>
                </button>
                <button class="ctrl-mute" id="fs-btn" title="Plein écran">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>
                </button>
            </div>
            <div class="track-bar" id="track-bar" style="display:none"></div>
        </div>
    </div>
</div>
<script>
var PLAYER_CONFIG = {
    remuxEnabled: {$remuxEnabled},
    isVideo: {$isVideo},
    baseUrl: '{$baseUrl}',
    pp: '{$pParamJs}',
    episodeNav: {$episodeNavJson}
};
</script>
<script src="/share/player.js?v={$jsMtime}"></script>
</body>
</html>
HTML;
}

/**
 * Affiche une page d'erreur
 */
function afficher_erreur(string $titre, string $message): void {
    $titreHtml = htmlspecialchars($titre);
    $messageHtml = htmlspecialchars($message);
    $css = css_public();

    echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="/share/favicon.svg">
    <title>{$titreHtml}</title>
    <style>{$css}
    .page { position: relative; z-index: 1; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 1.5rem; }
    .card { background: rgba(26,29,40,.85); border: 1px solid rgba(255,255,255,.08); border-radius: var(--radius-lg); backdrop-filter: blur(16px); padding: 2.5rem 2rem; max-width: 380px; width: 100%; text-align: center; }
    .card-icon { width: 56px; height: 56px; background: rgba(239,83,80,.1); border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.2rem; font-size: 1.6rem; }
    .card h2 { font-size: 1.2rem; font-weight: 700; margin-bottom: .5rem; color: var(--red); }
    .card p { color: var(--text-secondary); font-size: .9rem; line-height: 1.5; }
    </style>
</head>
<body>
<div class="page">
    <div class="card">
        <div class="card-icon">&#x26A0;&#xFE0F;</div>
        <h2>{$titreHtml}</h2>
        <p>{$messageHtml}</p>
    </div>
</div>
</body>
</html>
HTML;
}
