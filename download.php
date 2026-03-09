<?php
/**
 * Téléchargement public — Gère les URLs /dl/{token}
 * Vérifie le token, l'expiration, le mot de passe, puis sert le contenu
 *
 * - Fichiers : servis via X-Accel-Redirect (nginx sendfile, zero-copy, supporte resume)
 * - Dossiers : listing navigable avec téléchargement individuel de chaque fichier
 */

require_once __DIR__ . '/db.php';

// Récupérer le token depuis l'URL (passé par nginx)
$token = $_GET['token'] ?? '';

if (empty($token) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
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
    http_response_code(404);
    afficher_erreur('Lien introuvable', 'Ce lien n\'existe pas ou a été supprimé.');
    exit;
}

// Vérifier l'expiration
if ($link['expires_at'] !== null && strtotime($link['expires_at']) < time()) {
    http_response_code(410);
    afficher_erreur('Lien expiré', 'Ce lien de partage a expiré et n\'est plus disponible.');
    exit;
}

// Vérifier que le fichier/dossier existe toujours
if (!file_exists($link['path'])) {
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
        if (!password_verify($_POST['password'], $link['password_hash'])) {
            afficher_formulaire_mdp($link['name'], 'Mot de passe incorrect.');
            exit;
        }
        $_SESSION[$sessionKey] = true;
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
if ($resolvedPath === false || ($resolvedPath !== $basePath && strpos($resolvedPath, $basePath . '/') !== 0)) {
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
            afficher_player($token, $link['name'], $subPath, $mediaType);
            exit;
        }
    }

    // Mode streaming natif : sert le fichier brut (audio uniquement, ou fallback)
    if (isset($_GET['stream']) && $_GET['stream'] === '1') {
        $mime = get_stream_mime(strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION)));
        if ($mime) {
            $encodedPath = XACCEL_PREFIX . str_replace('%2F', '/', rawurlencode($resolvedPath));
            header('Content-Type: ' . $mime);
            header('Content-Disposition: inline');
            header('X-Accel-Redirect: ' . $encodedPath);
            exit;
        }
    }

    // Mode remux : repackage MKV→MP4 sans ré-encoder la vidéo (quasi zéro CPU)
    // Audio transcodé en AAC pour compatibilité (AC3/DTS → AAC, léger)
    if (isset($_GET['stream']) && $_GET['stream'] === 'remux') {
        $mime = get_stream_mime(strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION)));
        if ($mime && str_starts_with($mime, 'video/')) {
            header('Content-Type: video/mp4');
            header('Content-Disposition: inline');
            header('X-Accel-Buffering: no');
            header('Cache-Control: no-cache');
            $cmd = 'ffmpeg -i ' . escapeshellarg($resolvedPath)
                . ' -c:v copy -c:a aac -ac 2 -b:a 128k'
                . ' -movflags frag_keyframe+empty_moov+default_base_moof'
                . ' -f mp4 -y pipe:1 2>/dev/null';
            passthru($cmd);
            exit;
        }
    }

    // Mode transcodage complet : ré-encode vidéo + audio (CPU intensif)
    if (isset($_GET['stream']) && $_GET['stream'] === 'transcode') {
        $mime = get_stream_mime(strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION)));
        if ($mime && str_starts_with($mime, 'video/')) {
            header('Content-Type: video/mp4');
            header('Content-Disposition: inline');
            header('X-Accel-Buffering: no');
            header('Cache-Control: no-cache');
            $cmd = 'ffmpeg -i ' . escapeshellarg($resolvedPath)
                . ' -c:v libx264 -preset ultrafast -crf 23 -vf "scale=-2:\'min(720,ih)\'" -pix_fmt yuv420p'
                . ' -c:a aac -ac 2 -b:a 128k'
                . ' -movflags frag_keyframe+empty_moov+default_base_moof'
                . ' -f mp4 -y pipe:1 2>/dev/null';
            passthru($cmd);
            exit;
        }
    }

    // Téléchargement direct via nginx
    if (!$subPath) {
        $stmt = $db->prepare("UPDATE links SET download_count = download_count + 1 WHERE id = :id");
        $stmt->execute([':id' => $link['id']]);
    }

    $encodedPath = XACCEL_PREFIX . str_replace('%2F', '/', rawurlencode($resolvedPath));
    $fileName = basename($resolvedPath);

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . addcslashes($fileName, '"\\') . '"');
    header('X-Accel-Redirect: ' . $encodedPath);
    exit;
}

// Si c'est un dossier
if (is_dir($resolvedPath)) {
    if (!$subPath) {
        $stmt = $db->prepare("UPDATE links SET download_count = download_count + 1 WHERE id = :id");
        $stmt->execute([':id' => $link['id']]);
    }

    // Mode ZIP : télécharger tout le dossier en un seul fichier
    if (isset($_GET['zip']) && $_GET['zip'] === '1') {
        $zipName = basename($resolvedPath) . '.zip';
        $parentDir = dirname($resolvedPath);
        $baseName = basename($resolvedPath);

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . addcslashes($zipName, '"\\') . '"');
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
    <title>{$shareNameHtml}</title>
    <style>{$css}
    .page { position: relative; z-index: 1; max-width: 800px; margin: 0 auto; padding: 2.5rem 1.5rem 4rem; }
    .header { display: flex; align-items: center; gap: .8rem; margin-bottom: .4rem; }
    .header-icon { width: 38px; height: 38px; background: linear-gradient(135deg, var(--accent), #e08820); border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; box-shadow: 0 4px 20px rgba(240,160,48,.2); }
    .header-title { font-size: 1.3rem; font-weight: 700; color: #fff; }
    .breadcrumb { display: flex; flex-wrap: wrap; align-items: center; gap: .15rem; margin-bottom: 1.5rem; font-size: .85rem; padding: .5rem .8rem; background: rgba(255,255,255,.02); border-radius: var(--radius-md); border: 1px solid var(--border); }
    .breadcrumb a { color: var(--accent); text-decoration: none; font-weight: 500; }
    .breadcrumb a:hover { color: #ffc060; }
    .breadcrumb .sep { color: var(--text-muted); margin: 0 .2rem; font-size: .75rem; }
    .breadcrumb .current { color: var(--text-primary); font-weight: 500; }
    .panel { background: rgba(26,29,40,.7); border: 1px solid rgba(255,255,255,.08); border-radius: var(--radius-lg); backdrop-filter: blur(12px); overflow: hidden; }
    .row { display: flex; align-items: center; padding: .6rem 1rem; border-bottom: 1px solid var(--border); transition: background .12s; text-decoration: none; color: var(--text-primary); }
    .row:last-child { border-bottom: none; }
    .row:hover { background: rgba(240,160,48,.04); }
    .row-icon { width: 32px; height: 32px; border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; margin-right: .7rem; flex-shrink: 0; }
    .row-icon.folder { background: var(--accent-soft); }
    .row-icon.file { background: rgba(66,165,245,.08); }
    .row-icon.up { background: rgba(255,255,255,.04); }
    .row-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .88rem; }
    .row-name.is-folder { color: var(--accent); font-weight: 500; }
    .row-ext { color: var(--text-muted); font-size: .7rem; font-family: var(--font-mono); text-transform: uppercase; background: rgba(255,255,255,.04); padding: .1rem .4rem; border-radius: 4px; flex-shrink: 0; margin-left: .5rem; letter-spacing: .03em; }
    .row-meta { color: var(--text-muted); font-size: .8rem; font-family: var(--font-mono); margin-left: auto; padding-left: .5rem; white-space: nowrap; flex-shrink: 0; }
    .empty { text-align: center; padding: 3rem 1rem; color: var(--text-muted); }
    .empty-icon { font-size: 2.5rem; display: block; margin-bottom: .6rem; opacity: .4; }
    .toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: .8rem; gap: .5rem; }
    .toolbar-info { color: var(--text-muted); font-size: .82rem; }
    .btn-zip { display: inline-flex; align-items: center; gap: .4rem; padding: .5rem 1rem; border: none; border-radius: var(--radius-sm); background: var(--accent); color: var(--bg-deep); font-family: var(--font-sans); font-size: .85rem; font-weight: 700; cursor: pointer; text-decoration: none; transition: all .15s; white-space: nowrap; }
    .btn-zip:hover { background: #ffc060; box-shadow: 0 2px 12px rgba(240,160,48,.25); }
    .btn-zip:active { transform: scale(.97); }
    .btn-zip svg { flex-shrink: 0; }
    .btn-play { display: flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: var(--radius-sm); background: rgba(102,187,106,.1); color: var(--green); cursor: pointer; flex-shrink: 0; margin-left: .5rem; transition: background .15s; z-index: 1; }
    .btn-play:hover { background: rgba(102,187,106,.2); }
    .btn-play:active { transform: scale(.9); }
    .sort-bar { display: flex; align-items: center; gap: .3rem; }
    .sort-btn { display: inline-flex; align-items: center; gap: .25rem; padding: .3rem .6rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: transparent; color: var(--text-muted); font-family: var(--font-sans); font-size: .75rem; font-weight: 600; cursor: pointer; transition: all .15s; white-space: nowrap; }
    .sort-btn:hover { color: var(--text-secondary); border-color: rgba(255,255,255,.12); }
    .sort-btn.active { color: var(--accent); border-color: rgba(240,160,48,.25); background: var(--accent-soft); }
    .sort-btn svg { transition: transform .15s; }
    .sort-btn.desc svg { transform: rotate(180deg); }
    .search-box { padding: .35rem .7rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: rgba(255,255,255,.03); color: var(--text-primary); font-family: var(--font-sans); font-size: .8rem; outline: none; transition: border-color .15s; width: 180px; }
    .search-box:focus { border-color: var(--accent); box-shadow: 0 0 0 2px var(--accent-soft); }
    .search-box::placeholder { color: var(--text-muted); }
    .row.hidden { display: none; }
    @media (max-width: 640px) { .row-name { white-space: normal; word-break: break-all; } .row-ext { display: none; } .search-box { width: 120px; } }
    @media (max-width: 480px) { .page { padding: 1.5rem 1rem; } .row { padding: .5rem .7rem; } .btn-zip { width: 100%; justify-content: center; } .toolbar { flex-wrap: wrap; } }
    </style>
</head>
<body>
<div class="page">
    <div class="header">
        <div class="header-icon">&#x1F4C2;</div>
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
        echo '<a class="row" href="' . $fileUrl . '" title="' . $fileHtml . '" data-type="file" data-name="' . $fileHtml . '" data-size="' . $file['size'] . '">';
        echo '<div class="row-icon file"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="color:var(--blue)"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>';
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
 * Formate une taille en octets
 */
function format_taille(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' o';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' Ko';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' Mo';
    return round($bytes / 1073741824, 2) . ' Go';
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
 * Retourne le type MIME de streaming pour une extension donnée
 */
function get_stream_mime(string $ext): ?string {
    return match($ext) {
        'mp4', 'm4v', 'mov' => 'video/mp4',
        'webm' => 'video/webm',
        'mkv' => 'video/x-matroska',
        'avi' => 'video/x-msvideo',
        'mp3' => 'audio/mpeg',
        'aac', 'm4a' => 'audio/mp4',
        'ogg', 'oga', 'opus' => 'audio/ogg',
        'flac' => 'audio/flac',
        'wav' => 'audio/wav',
        default => null,
    };
}

/**
 * Retourne 'video', 'audio' ou null selon l'extension du fichier
 */
function get_media_type(string $filename): ?string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mime = get_stream_mime($ext);
    if (!$mime) return null;
    return str_starts_with($mime, 'video/') ? 'video' : 'audio';
}

/**
 * Affiche la page du player video/audio
 */
function afficher_player(string $token, string $shareName, string $subPath, string $mediaType): void {
    $baseUrl = '/dl/' . htmlspecialchars($token);
    $fileName = $subPath ? basename($subPath) : $shareName;
    $fileNameHtml = htmlspecialchars($fileName);

    $remuxUrl = $subPath
        ? $baseUrl . '?p=' . rawurlencode($subPath) . '&amp;stream=remux'
        : $baseUrl . '?stream=remux';

    $transcodeUrl = $subPath
        ? $baseUrl . '?p=' . rawurlencode($subPath) . '&amp;stream=transcode'
        : $baseUrl . '?stream=transcode';

    $nativeUrl = $subPath
        ? $baseUrl . '?p=' . rawurlencode($subPath) . '&amp;stream=1'
        : $baseUrl . '?stream=1';

    $dlUrl = $subPath
        ? $baseUrl . '?p=' . rawurlencode($subPath)
        : $baseUrl;

    $isVideo = $mediaType === 'video';

    // URL retour vers le listing parent
    if ($subPath && str_contains($subPath, '/')) {
        $backUrl = $baseUrl . '?p=' . rawurlencode(dirname($subPath));
    } elseif ($subPath) {
        $backUrl = $baseUrl;
    } else {
        $backUrl = null;
    }

    $tag = $mediaType === 'video' ? 'video' : 'audio';
    // Vidéo : remux d'abord (MKV→MP4, zéro CPU). Audio : natif direct.
    $srcUrl = $isVideo ? $remuxUrl : $nativeUrl;
    $css = css_public();
    $backHtml = $backUrl
        ? '<a class="player-btn" href="' . $backUrl . '"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg> Retour</a>'
        : '';

    echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$fileNameHtml}</title>
    <style>{$css}
    .page { position: relative; z-index: 1; max-width: 960px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
    .player-toolbar { display: flex; align-items: center; gap: .5rem; margin-bottom: 1rem; flex-wrap: wrap; }
    .player-btn { display: inline-flex; align-items: center; gap: .35rem; padding: .45rem .8rem; border: none; border-radius: var(--radius-sm); background: rgba(255,255,255,.05); color: var(--text-secondary); font-family: var(--font-sans); font-size: .82rem; font-weight: 600; cursor: pointer; text-decoration: none; transition: all .15s; border: 1px solid var(--border); }
    .player-btn:hover { background: rgba(255,255,255,.08); color: var(--text-primary); }
    .player-btn.accent { background: var(--accent); color: var(--bg-deep); border-color: transparent; }
    .player-btn.accent:hover { background: #ffc060; }
    .player-name { flex: 1; min-width: 0; font-size: .9rem; color: var(--text-primary); font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .player-container { background: rgba(26,29,40,.7); border: 1px solid rgba(255,255,255,.08); border-radius: var(--radius-lg); overflow: hidden; backdrop-filter: blur(12px); }
    video { display: block; width: 100%; max-height: 80vh; background: #000; }
    audio { display: block; width: 100%; padding: 2.5rem 1.5rem; }
    .player-hint { text-align: center; padding: .8rem; color: var(--text-muted); font-size: .78rem; transition: all .2s; }
    .player-hint.transcoding { color: var(--accent); }
    .player-hint.error { color: var(--red); }
    @media (max-width: 480px) { .page { padding: 1rem .75rem; } .player-name { display: none; } }
    </style>
</head>
<body>
<div class="page">
    <div class="player-toolbar">
        {$backHtml}
        <span class="player-name" title="{$fileNameHtml}">{$fileNameHtml}</span>
        <a class="player-btn accent" href="{$dlUrl}"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Télécharger</a>
    </div>
    <div class="player-container">
        <{$tag} id="player" controls autoplay preload="metadata">
            <source src="{$srcUrl}">
        </{$tag}>
        <div class="player-hint" id="hint">Chargement...</div>
    </div>
</div>
<script>
(function() {
    var player = document.getElementById('player');
    var hint = document.getElementById('hint');
    var isVideo = {$isVideo};
    var transcodeUrl = '{$transcodeUrl}'.replace(/&amp;/g, '&');
    var step = isVideo ? 'remux' : 'native';

    player.addEventListener('playing', function() {
        if (step === 'remux' && isVideo) {
            // Attendre un court instant puis vérifier si la vidéo a une image
            // HEVC 10-bit : l'audio joue mais videoWidth reste 0
            setTimeout(function() {
                if (player.videoWidth === 0) {
                    onFail();
                } else {
                    hint.textContent = '';
                }
            }, 800);
            return;
        }
        if (step === 'transcode') { hint.textContent = 'Transcodage en cours (720p)'; hint.className = 'player-hint transcoding'; }
        else hint.textContent = '';
    });

    function onFail() {
        if (step === 'remux') {
            step = 'transcode';
            hint.textContent = 'Remux échoué, transcodage en cours...';
            hint.className = 'player-hint transcoding';
            player.src = transcodeUrl;
            player.load();
            player.play().catch(function(){});
        } else {
            hint.textContent = 'Lecture impossible. Utilisez le bouton Télécharger.';
            hint.className = 'player-hint error';
        }
    }

    player.addEventListener('error', onFail);
    var src = player.querySelector('source');
    if (src) src.addEventListener('error', onFail);
})();
</script>
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
