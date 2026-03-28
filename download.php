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
        if ($attempts >= 10) {
            stream_log('AUTH brute-force | token=' . $token . ' | attempts=' . $attempts);
            sleep(3);
            afficher_formulaire_mdp($link['name'], 'Trop de tentatives. Réessayez plus tard.');
            exit;
        }
        if (!password_verify($_POST['password'], $link['password_hash'])) {
            stream_log('AUTH fail | token=' . $token . ' | attempt=' . ($attempts + 1));
            $_SESSION[$attemptsKey] = $attempts + 1;
            sleep(1);
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
            $folders[] = ['name' => $item];
        } else {
            $files[] = ['name' => $item, 'size' => filesize($fullItem)];
        }
    }

    usort($folders, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    usort($files, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

    $baseUrl = '/dl/' . htmlspecialchars($token);
    $shareNameHtml = htmlspecialchars($shareName);
    $css = css_public();

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

    echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="/share/favicon.svg">
    <title>{$shareNameHtml}</title>
    <style>{$css}
@keyframes fadeUp { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:none; } }
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
.sort-btn { display:inline-flex; align-items:center; gap:.2rem; padding:.28rem .55rem; border:1px solid var(--border); border-radius:var(--radius-sm); background:transparent; color:var(--text-muted); font-family:var(--font-sans); font-size:.73rem; font-weight:600; cursor:pointer; transition:all .15s; white-space:nowrap; }
.sort-btn:hover { color:var(--text-secondary); border-color:rgba(255,255,255,.12); }
.sort-btn.active { color:var(--accent); border-color:rgba(240,160,48,.25); background:var(--accent-soft); }
.sort-btn svg { transition:transform .15s; }
.sort-btn.desc svg { transform:rotate(180deg); }
.search-box { padding:.3rem .65rem; border:1px solid var(--border); border-radius:var(--radius-sm); background:rgba(255,255,255,.03); color:var(--text-primary); font-family:var(--font-sans); font-size:.8rem; outline:none; transition:border-color .15s; width:175px; }
.search-box:focus { border-color:var(--accent); box-shadow:0 0 0 2px var(--accent-soft); }
.search-box::placeholder { color:var(--text-muted); }
.btn-zip { display:inline-flex; align-items:center; gap:.35rem; padding:.38rem .85rem; border:none; border-radius:var(--radius-sm); background:var(--accent); color:var(--bg-deep); font-family:var(--font-sans); font-size:.82rem; font-weight:700; cursor:pointer; text-decoration:none; transition:all .15s; white-space:nowrap; }
.btn-zip:hover { background:#ffc060; box-shadow:0 2px 12px rgba(240,160,48,.25); }
.btn-zip:active { transform:scale(.97); }
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
@media(max-width:640px){.row-name{white-space:normal;word-break:break-word;font-size:.83rem}.row-ext{display:none}.search-box{width:115px}.row{min-height:44px}}
@media(max-width:480px){.page{padding:1.1rem .85rem 3rem}.row{padding:.45rem .75rem}.row-icon{width:28px;height:28px;margin-right:.55rem}.btn-zip{flex:1;justify-content:center}.search-box{flex:1;width:auto;min-width:80px}.toolbar-info{display:none}}
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

        // Recherche (seulement si 10+ fichiers)
        if ($totalFiles >= 10) {
            echo '<input class="search-box" type="text" placeholder="Rechercher..." oninput="filtrer(this.value)">';
        }

        echo '<a class="btn-zip" href="' . $zipUrl . '">';
        echo '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';
        echo ' Tout télécharger (.zip)';
        echo '</a>';
        echo '</div>';
    }

    echo '<div class="panel">';


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
        echo '<a class="row" href="' . $folderUrl . '" data-type="folder" data-name="' . $folderHtml . '">';
        echo '<div class="row-icon folder"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" style="color:var(--accent)"><path d="M2 6a2 2 0 012-2h5l2 2h9a2 2 0 012 2v10a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg></div>';
        echo '<span class="row-name is-folder">' . $folderHtml . '</span>';
        echo '</a>';
    }

    // Fichiers
    foreach ($files as $file) {
        $fileHtml = htmlspecialchars($file['name']);
        $filePath = $subPath ? $subPath . '/' . $file['name'] : $file['name'];
        $fileUrl = $baseUrl . '?p=' . rawurlencode($filePath);
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
        echo '<a class="row" href="' . $fileUrl . '" title="' . $fileHtml . '" data-type="file" data-name="' . $fileHtml . '" data-size="' . $file['size'] . '">';
        echo '<div class="row-icon ' . $iconClass . '">' . $iconSvg . '</div>';
        echo '<span class="row-name">' . $fileHtml . '</span>';
        if ($ext) echo '<span class="row-ext">' . htmlspecialchars($ext) . '</span>';
        echo '<span class="row-meta">' . $size . '</span>';
        if ($mediaType) {
            $playUrl = $baseUrl . '?p=' . rawurlencode($filePath) . '&play=1';
            echo '<span class="btn-play" onclick="event.preventDefault();event.stopPropagation();location.href=\'' . htmlspecialchars($playUrl, ENT_QUOTES) . '\'" title="Lire"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg></span>';
        }
        echo '</a>';
    }

    if (empty($folders) && empty($files)) {
        echo '<div class="empty"><span class="empty-icon">&#x1F4ED;</span>Ce dossier est vide</div>';
    }

    echo <<<'HTML'
    </div>
</div>
<script>
function tri(btn, key) {
    document.querySelectorAll('.sort-btn').forEach(b => b.classList.remove('active','desc'));
    const panel = document.querySelector('.panel');
    const rows = [...panel.querySelectorAll('.row[data-type]')];
    const folders = rows.filter(r => r.dataset.type === 'folder');
    const files = rows.filter(r => r.dataset.type === 'file');
    // Determine direction: click same = toggle, click other = asc
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
    // Re-insert: up link first (if exists), then folders, then sorted files
    const upLink = panel.querySelector('.row:not([data-type])');
    if (upLink) panel.appendChild(upLink);
    folders.forEach(f => panel.appendChild(f));
    files.forEach(f => panel.appendChild(f));
}
function filtrer(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.row[data-type="file"]').forEach(r => {
        r.classList.toggle('hidden', q && !r.dataset.name.toLowerCase().includes(q));
    });
    document.querySelectorAll('.row[data-type="folder"]').forEach(r => {
        r.classList.toggle('hidden', q && !r.dataset.name.toLowerCase().includes(q));
    });
}
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            <div id="video-click-area" style="position:absolute;inset:0;z-index:6;cursor:pointer;outline:none;-webkit-tap-highlight-color:transparent;user-select:none"></div>
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
