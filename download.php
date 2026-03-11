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
        session_regenerate_id(true);
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
    http_response_code(403);
    afficher_erreur('Accès interdit', 'Ce chemin n\'est pas autorisé.');
    exit;
}

function stream_log(string $msg): void {
    if (!defined('STREAM_LOG') || !STREAM_LOG) return;
    $line = '[' . date('Y-m-d H:i:s') . '] [' . ($_SERVER['REMOTE_ADDR'] ?? '-') . '] ' . $msg . "\n";
    @file_put_contents(STREAM_LOG, $line, FILE_APPEND | LOCK_EX);
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

    // Probe : retourne les pistes audio/sous-titres en JSON (pour le player)
    if (isset($_GET['probe']) && $_GET['probe'] === '1') {
        header('Content-Type: application/json; charset=utf-8');

        // Cache SQLite : évite de relancer ffprobe si le fichier n'a pas changé
        $mtime = filemtime($resolvedPath);
        $cached = $db->prepare("SELECT result FROM probe_cache WHERE path = :p AND mtime = :m");
        $cached->execute([':p' => $resolvedPath, ':m' => $mtime]);
        if ($row = $cached->fetch()) {
            echo $row['result'];
            exit;
        }

        $cmd = 'timeout 10 ffprobe -v error -show_entries format=duration -show_entries stream=index,codec_type,codec_name,width,height:stream_tags=language,title -of json '
            . escapeshellarg($resolvedPath) . ' 2>/dev/null';
        $output = shell_exec($cmd);
        $data = json_decode($output, true);
        $duration = (float)($data['format']['duration'] ?? 0);
        $audio = [];
        $subs = [];
        $audioIdx = 0;
        $subIdx = 0;
        $videoHeight = 0;
        $videoCodec = '';
        foreach (($data['streams'] ?? []) as $s) {
            $lang = $s['tags']['language'] ?? '';
            $title = $s['tags']['title'] ?? '';
            if ($s['codec_type'] === 'video' && !$videoHeight && isset($s['height'])) {
                $videoHeight = (int)$s['height'];
                $videoCodec = $s['codec_name'] ?? '';
            } elseif ($s['codec_type'] === 'audio') {
                $label = $lang ? strtoupper($lang) : 'Piste ' . ($audioIdx + 1);
                if ($title) $label .= ' — ' . $title;
                $audio[] = ['index' => $audioIdx, 'stream' => $s['index'], 'codec' => $s['codec_name'], 'lang' => $lang, 'label' => $label];
                $audioIdx++;
            } elseif ($s['codec_type'] === 'subtitle') {
                $imageCodecs = ['hdmv_pgs_subtitle', 'dvd_subtitle', 'dvb_subtitle', 'dvb_teletext', 'xsub'];
                $subType = in_array($s['codec_name'] ?? '', $imageCodecs) ? 'image' : 'text';
                $label = $lang ? strtoupper($lang) : 'Sous-titre ' . ($subIdx + 1);
                if ($title) $label .= ' — ' . $title;
                if ($subType === 'image') $label .= ' ★'; // indique burn-in requis
                $subs[] = ['index' => $subIdx, 'stream' => $s['index'], 'codec' => $s['codec_name'], 'lang' => $lang, 'label' => $label, 'type' => $subType];
                $subIdx++;
            }
        }
        $result = json_encode(['audio' => $audio, 'subtitles' => $subs, 'duration' => $duration, 'videoHeight' => $videoHeight, 'videoCodec' => $videoCodec]);

        // Stocker en cache (best-effort : on ignore si la DB est encore verrouillée)
        try {
            $db->prepare("INSERT OR REPLACE INTO probe_cache (path, mtime, result) VALUES (:p, :m, :r)")
               ->execute([':p' => $resolvedPath, ':m' => $mtime, ':r' => $result]);
        } catch (PDOException $e) { /* lock résiduel — le probe sera recalculé au prochain appel */ }

        echo $result;
        exit;
    }

    // Extraction sous-titre en WebVTT
    if (isset($_GET['subtitle'])) {
        $trackIdx = (int)$_GET['subtitle'];
        header('Content-Type: text/vtt; charset=utf-8');
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
        }
        $logFile = defined('STREAM_LOG') && STREAM_LOG ? STREAM_LOG : '/dev/null';
        stream_log('SUBTITLE start | track=' . $trackIdx . ' | ' . basename($resolvedPath));
        $cmd = 'ffmpeg -i ' . escapeshellarg($resolvedPath)
            . ' -map 0:s:' . $trackIdx . ' -f webvtt pipe:1 -loglevel error 2>>' . escapeshellarg($logFile);
        [$slotFp] = acquireStreamSlot();
        passthru($cmd);
        releaseStreamSlot($slotFp);
        exit;
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

    // Sélection de piste audio (paramètre &audio=N, index relatif dans les pistes audio)
    $audioTrack = isset($_GET['audio']) ? max(0, (int)$_GET['audio']) : 0;
    $audioMap = ' -map 0:v:0 -map 0:a:' . $audioTrack;

    // Seek : on utilise uniquement le seek rapide avant -i (keyframe seek)
    // Le fine seek après -i a été supprimé : sur 4K HEVC il force le décodage de N secondes
    // avant le premier frame → trop lent → navigateur time out.
    // Imprécision max : distance au keyframe précédent (typiquement <2s sur x265 UHD).
    $startSec = isset($_GET['start']) ? max(0, (float)$_GET['start']) : 0;
    if ($startSec > 0) {
        $roughSeek = $startSec;
        $fineSeek = 0;
        $seekArgBefore = ' -ss ' . escapeshellarg(sprintf('%.3f', $roughSeek));
        $seekArgAfter = '';
    } else {
        $seekArgBefore = '';
        $seekArgAfter = '';
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
            header('Accept-Ranges: none');
            $logFile = defined('STREAM_LOG') && STREAM_LOG ? STREAM_LOG : '/dev/null';
            stream_log('REMUX start | audio=' . $audioTrack . ' start=' . $startSec . ' | ' . basename($resolvedPath));
            $cmd = 'ffmpeg' . $seekArgBefore . ' -thread_queue_size 512 -fflags +genpts+discardcorrupt -i ' . escapeshellarg($resolvedPath)
                . $audioMap . ' -c:v copy -c:a aac -ac 2 -b:a 128k'
                . ' -af "aresample=async=2000:first_pts=0"'
                . ' -avoid_negative_ts make_zero -start_at_zero'
                . ' -max_muxing_queue_size 1024'
                . ' -min_frag_duration 300000'
                . ' -movflags frag_keyframe+empty_moov+default_base_moof'
                . ' -f mp4 -y pipe:1 -loglevel error 2>>' . escapeshellarg($logFile);
            [$slotFp, $queued] = acquireStreamSlot();
            if ($queued) { stream_log('REMUX queued | ' . basename($resolvedPath)); header('X-Stream-Queued: 1'); }
            if (filesize($resolvedPath) < 2 * 1024 * 1024 * 1024) shell_exec('vmtouch -qt ' . escapeshellarg($resolvedPath) . ' >/dev/null 2>&1 &');
            passthru($cmd);
            releaseStreamSlot($slotFp);
            exit;
        }
    }

    // Mode transcodage complet : ré-encode vidéo + audio (CPU intensif)
    if (isset($_GET['stream']) && $_GET['stream'] === 'transcode') {
        $mime = get_stream_mime(strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION)));
        if ($mime && str_starts_with($mime, 'video/')) {
            $quality = isset($_GET['quality']) ? (int)$_GET['quality'] : 720;
            $allowedQualities = [480, 720, 1080];
            if (!in_array($quality, $allowedQualities)) $quality = 720;
            $burnSub = isset($_GET['burnSub']) ? max(0, (int)$_GET['burnSub']) : -1;
            header('Content-Type: video/mp4');
            header('Content-Disposition: inline');
            header('X-Accel-Buffering: no');
            header('Cache-Control: no-cache');
            header('Accept-Ranges: none');
            $logFile = defined('STREAM_LOG') && STREAM_LOG ? STREAM_LOG : '/dev/null';
            if ($burnSub >= 0) {
                // Burn-in sous-titre image (PGS/VOBSUB) via filter_complex
                // Overlay sur la vidéo native puis scale : compatible 1080p et 4K
                // (le pad=1920:1080 cassait les fichiers 4K → offsets négatifs → crash ffmpeg)
                stream_log('TRANSCODE+SUB start | quality=' . $quality . 'p audio=' . $audioTrack . ' burnSub=' . $burnSub . ' start=' . $startSec . ' | ' . basename($resolvedPath));
                $fc = '"[0:v][0:s:' . $burnSub . ']overlay=eof_action=pass[ov];[ov]scale=-2:\'min(' . $quality . ',ih)\',format=yuv420p[v]"';
                // Pas de fine seek pour burnSub : le fine seek décode N sec de 4K avant le 1er frame
                // → trop lent → navigateur time out. On accepte ±5s d'imprécision.
                $cmd = 'ffmpeg' . $seekArgBefore . ' -thread_queue_size 512 -fflags +genpts+discardcorrupt -i ' . escapeshellarg($resolvedPath)
                    . ' -filter_complex ' . $fc
                    . ' -map "[v]" -map 0:a:' . $audioTrack
                    . ' -c:v libx264 -preset ultrafast -crf 23 -g 50'
                    . ' -c:a aac -ac 2 -b:a 128k'
                    . ' -af "aresample=async=2000:first_pts=0"'
                    . ' -avoid_negative_ts make_zero -start_at_zero'
                    . ' -max_muxing_queue_size 1024'
                    . ' -min_frag_duration 300000'
                    . ' -movflags frag_keyframe+empty_moov+default_base_moof'
                    . ' -f mp4 -y pipe:1 -loglevel error 2>>' . escapeshellarg($logFile);
            } else {
                stream_log('TRANSCODE start | quality=' . $quality . 'p audio=' . $audioTrack . ' start=' . $startSec . ' | ' . basename($resolvedPath));
                $cmd = 'ffmpeg' . $seekArgBefore . ' -thread_queue_size 512 -fflags +genpts+discardcorrupt -i ' . escapeshellarg($resolvedPath)
                    . $audioMap . ' -c:v libx264 -preset ultrafast -crf 23 -g 50'
                    . ' -vf "scale=-2:\'min(' . $quality . ',ih)\'" -pix_fmt yuv420p'
                    . ' -c:a aac -ac 2 -b:a 128k'
                    . ' -af "aresample=async=2000:first_pts=0"'
                    . ' -avoid_negative_ts make_zero -start_at_zero'
                    . ' -max_muxing_queue_size 1024'
                    . ' -min_frag_duration 300000'
                    . ' -movflags frag_keyframe+empty_moov+default_base_moof'
                    . ' -f mp4 -y pipe:1 -loglevel error 2>>' . escapeshellarg($logFile);
            }
            [$slotFp, $queued] = acquireStreamSlot();
            if ($queued) { stream_log('TRANSCODE queued | ' . basename($resolvedPath)); header('X-Stream-Queued: 1'); }
            if (filesize($resolvedPath) < 2 * 1024 * 1024 * 1024) shell_exec('vmtouch -qt ' . escapeshellarg($resolvedPath) . ' >/dev/null 2>&1 &');
            passthru($cmd);
            releaseStreamSlot($slotFp);
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
function afficher_player(string $token, string $shareName, string $subPath, string $mediaType): void {
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

    $tag = $mediaType === 'video' ? 'video' : 'audio';
    $controlsAttr = $mediaType === 'audio' ? 'controls' : '';
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
    <link rel="icon" type="image/svg+xml" href="/share/favicon.svg">
    <title>{$fileNameHtml}</title>
    <style>{$css}
@keyframes hintFade { from{opacity:0;transform:translateY(4px)} to{opacity:1;transform:none} }
.page { position:relative; z-index:1; max-width:1200px; margin:0 auto; padding:1.5rem 1.25rem 2rem; min-height:calc(100vh - 2rem); display:flex; flex-direction:column; }
.player-toolbar { display:flex; align-items:center; gap:.5rem; margin-bottom:1rem; }
.player-btn { display:inline-flex; align-items:center; gap:.35rem; padding:.4rem .8rem; border:1px solid var(--border); border-radius:var(--radius-sm); background:rgba(255,255,255,.04); color:var(--text-secondary); font-family:var(--font-sans); font-size:.82rem; font-weight:600; cursor:pointer; text-decoration:none; transition:all .15s; white-space:nowrap; }
.player-btn:hover { background:rgba(255,255,255,.08); color:var(--text-primary); }
.player-btn.accent { background:var(--accent); color:var(--bg-deep); border-color:transparent; font-weight:700; }
.player-btn.accent:hover { background:#ffc060; box-shadow:0 2px 14px rgba(240,160,48,.3); }
.player-name { flex:1; min-width:0; font-size:.85rem; color:var(--text-secondary); font-weight:500; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.player-card { border-radius:var(--radius-lg); overflow:hidden; border:1px solid rgba(255,255,255,.07); box-shadow:0 32px 80px rgba(0,0,0,.7), 0 0 0 1px rgba(255,255,255,.03); animation:hintFade .3s ease both; }
.player-video-wrap { position:relative; background:#000; line-height:0; }
.player-video-wrap:fullscreen,
.player-video-wrap:-webkit-full-screen { background:#000; display:flex; align-items:center; justify-content:center; width:100vw; height:100vh; }
.player-video-wrap:fullscreen video,
.player-video-wrap:-webkit-full-screen video { max-height:100vh; width:100%; }
video { display:block; width:100%; max-height:78vh; background:#000; object-fit:contain; }
.sub-overlay { position:absolute; left:0; right:0; text-align:center; pointer-events:none; padding:0 6%; z-index:10; font-size:1.5rem; }
audio { display:block; width:100%; padding:2rem 1.5rem; background:rgba(26,29,40,.8); }
.player-hint { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; pointer-events:none; z-index:10; }
.player-hint-text { font-family:var(--font-sans); font-size:.78rem; font-weight:600; padding:.4rem 1rem; border-radius:var(--radius-sm); background:rgba(12,14,20,.82); border:1px solid rgba(255,255,255,.08); color:var(--text-muted); backdrop-filter:blur(6px); letter-spacing:.01em; transition:color .2s; white-space:nowrap; }
.player-hint-text:empty { display:none; }
.player-hint.transcoding .player-hint-text { color:var(--accent); border-color:rgba(240,160,48,.2); }
.player-hint.error .player-hint-text { color:var(--red); border-color:rgba(239,83,80,.2); }
.tap-play-btn { pointer-events:all; display:inline-flex; align-items:center; gap:.55rem; padding:.75rem 2rem; background:rgba(240,160,48,.92); color:#0c0e14; border:none; border-radius:var(--radius-md); font-family:var(--font-sans); font-size:.9rem; font-weight:700; cursor:pointer; box-shadow:0 4px 32px rgba(240,160,48,.4); backdrop-filter:blur(8px); transition:all .15s; }
.tap-play-btn:hover { background:var(--accent); transform:scale(1.04); }
.player-controls { background:#0d0f1a; border-top:1px solid rgba(255,255,255,.055); padding:.8rem 1rem .7rem; }
.seek-bar { position:relative; height:32px; display:flex; align-items:center; cursor:pointer; user-select:none; -webkit-user-select:none; }
.seek-track { position:absolute; left:0; right:0; height:5px; background:rgba(255,255,255,.07); border-radius:3px; }
.seek-buffered { position:absolute; left:0; height:5px; background:rgba(255,255,255,.11); border-radius:3px; transition:width .3s; }
.seek-fill { position:absolute; left:0; height:5px; background:linear-gradient(90deg, var(--accent) 0%, #ffb020 100%); border-radius:3px; }
.seek-thumb { position:absolute; width:15px; height:15px; background:var(--accent); border-radius:50%; top:50%; transform:translate(-50%,-50%); box-shadow:0 0 0 3px rgba(240,160,48,.18), 0 2px 8px rgba(0,0,0,.5); transition:transform .1s, box-shadow .1s; z-index:2; }
.seek-thumb:hover, .seek-bar.dragging .seek-thumb { transform:translate(-50%,-50%) scale(1.3); box-shadow:0 0 0 5px rgba(240,160,48,.22), 0 2px 8px rgba(0,0,0,.5); }
.seek-bar.dragging .seek-fill { transition:none; }
.seek-time { display:flex; justify-content:space-between; font-size:.72rem; font-family:var(--font-mono); color:var(--text-muted); margin:.15rem 1px .45rem; }
.seek-time .current { color:var(--text-primary); font-weight:600; }
.ctrl-row { display:flex; align-items:center; gap:.6rem; margin-top:.05rem; }
.ctrl-play { display:flex; align-items:center; justify-content:center; width:38px; height:38px; border-radius:50%; background:var(--accent); color:#0c0e14; border:none; cursor:pointer; flex-shrink:0; transition:all .15s; box-shadow:0 2px 12px rgba(240,160,48,.25); }
.ctrl-play:hover { background:#ffc060; transform:scale(1.07); box-shadow:0 4px 18px rgba(240,160,48,.4); }
.ctrl-play:active { transform:scale(.94); }
.ctrl-spacer { flex:1; }
.ctrl-mute { display:flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:50%; background:transparent; color:var(--text-muted); border:1px solid rgba(255,255,255,.08); cursor:pointer; flex-shrink:0; transition:all .15s; }
.ctrl-mute:hover { background:rgba(255,255,255,.06); color:var(--text-primary); border-color:rgba(255,255,255,.15); }
.track-bar { display:flex; align-items:center; gap:.4rem; flex-wrap:wrap; padding-top:.55rem; border-top:1px solid rgba(255,255,255,.04); margin-top:.5rem; }
.track-bar label { color:var(--text-muted); font-size:.74rem; font-weight:600; letter-spacing:.02em; }
.track-select { padding:.26rem .5rem; border:1px solid var(--border); border-radius:20px; background:rgba(255,255,255,.04); color:var(--text-primary); font-family:var(--font-sans); font-size:.78rem; outline:none; cursor:pointer; -webkit-appearance:none; appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' fill='%238b90a0' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right .45rem center; padding-right:1.5rem; transition:border-color .15s; }
.track-select:hover { border-color:rgba(255,255,255,.14); }
.track-select:focus { border-color:var(--accent); }
.track-select option { background:#1a1d28; color:#e8eaf0; }
@media(max-width:480px){.page{padding:.9rem .75rem 3rem}.player-name{display:none}.ctrl-play{width:34px;height:34px}}
    </style>
</head>
<body>
<div class="page">
    <div class="player-toolbar">
        {$backHtml}
        <span class="player-name" title="{$fileNameHtml}">{$fileNameHtml}</span>
        <a class="player-btn accent" href="{$dlUrl}"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Télécharger</a>
    </div>
    <div class="player-card">
        <div class="player-video-wrap">
            <{$tag} id="player" {$controlsAttr} autoplay playsinline preload="metadata" crossorigin="anonymous"></{$tag}>
            <div class="player-hint" id="hint"><span class="player-hint-text">Chargement...</span></div>
        </div>
        <div class="player-controls">
            <div class="seek-bar" id="seek-bar" style="display:none">
                <div class="seek-track"></div>
                <div class="seek-buffered" id="seek-buffered"></div>
                <div class="seek-fill" id="seek-fill"></div>
                <div class="seek-thumb" id="seek-thumb"></div>
            </div>
            <div class="seek-time" id="seek-time" style="display:none">
                <span class="current" id="time-current">0:00</span>
                <span id="time-total">0:00</span>
            </div>
            <div class="ctrl-row" id="ctrl-row" style="display:none">
                <button class="ctrl-play" id="play-btn" title="Lecture / Pause">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                </button>
                <div class="ctrl-spacer"></div>
                <button class="ctrl-mute" id="mute-btn" title="Muet">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
                </button>
                <button class="ctrl-mute" id="speed-btn" title="Vitesse de lecture" style="font-size:.7rem;font-weight:700;font-family:var(--font-mono);width:auto;padding:0 .45rem;border-radius:20px;">1×</button>
                <button class="ctrl-mute" id="fs-btn" title="Plein écran">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>
                </button>
            </div>
            <div class="track-bar" id="track-bar" style="display:none"></div>
        </div>
    </div>
</div>
<script>
(function() {
    var player = document.getElementById('player');
    var hintWrap = document.getElementById('hint');
    var hintText = hintWrap.querySelector('.player-hint-text');
    // Compat: hint.textContent et hint.className redirigés vers le vrai élément
    var hint = {
        get textContent() { return hintText.textContent; },
        set textContent(v) { hintText.textContent = v; },
        get className() { return hintWrap.className; },
        set className(v) { hintWrap.className = v; },
        get innerHTML() { return hintWrap.innerHTML; },
        set innerHTML(v) { hintWrap.innerHTML = v; },
        appendChild: function(el) { hintWrap.appendChild(el); }
    };
    var trackBar = document.getElementById('track-bar');
    var ctrlRow = document.getElementById('ctrl-row');
    var playBtn = document.getElementById('play-btn');
    var muteBtn = document.getElementById('mute-btn');
    var seekBar = document.getElementById('seek-bar');
    var seekFill = document.getElementById('seek-fill');
    var seekThumb = document.getElementById('seek-thumb');
    var seekBuffered = document.getElementById('seek-buffered');
    var seekTimeEl = document.getElementById('seek-time');
    var timeCurrent = document.getElementById('time-current');
    var timeTotal = document.getElementById('time-total');
    var isVideo = {$isVideo};
    var base = '{$baseUrl}';

    // Play/pause + mute controls (vidéo uniquement)
    var svgPlay = '<svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>';
    var svgPause = '<svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>';
    var svgVol = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>';
    var svgMute = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>';

    var fsBtn = document.getElementById('fs-btn');
    var svgFs = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/></svg>';
    var svgFsExit = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3v3a2 2 0 0 1-2 2H3m18 0h-3a2 2 0 0 1-2-2V3m0 18v-3a2 2 0 0 1 2-2h3M3 16h3a2 2 0 0 1 2 2v3"/></svg>';

    function toggleFs() {
        var wrap = player.closest('.player-video-wrap') || player;
        if (!document.fullscreenElement) {
            (wrap.requestFullscreen || wrap.webkitRequestFullscreen || wrap.mozRequestFullScreen).call(wrap);
        } else {
            (document.exitFullscreen || document.webkitExitFullscreen || document.mozCancelFullScreen).call(document);
        }
    }
    document.addEventListener('fullscreenchange', function() {
        if (fsBtn) fsBtn.innerHTML = document.fullscreenElement ? svgFsExit : svgFs;
    });
    document.addEventListener('webkitfullscreenchange', function() {
        if (fsBtn) fsBtn.innerHTML = document.webkitFullscreenElement ? svgFsExit : svgFs;
    });

    if (isVideo) {
        ctrlRow.style.display = 'flex';
        playBtn.addEventListener('click', function() {
            if (player.paused) player.play().catch(function(){});
            else player.pause();
        });
        muteBtn.addEventListener('click', function() {
            player.muted = !player.muted;
            muteBtn.innerHTML = player.muted ? svgMute : svgVol;
        });
        if (fsBtn) fsBtn.addEventListener('click', toggleFs);
        player.addEventListener('dblclick', toggleFs);
        var speedBtn = document.getElementById('speed-btn');
        var speeds = [1, 1.5, 2];
        var speedIdx = 0;
        if (speedBtn) speedBtn.addEventListener('click', function() {
            speedIdx = (speedIdx + 1) % speeds.length;
            currentSpeed = speeds[speedIdx];
            player.playbackRate = currentSpeed;
            speedBtn.textContent = currentSpeed + '\u00D7';
        });
        player.addEventListener('play', function() { playBtn.innerHTML = svgPause; });
        player.addEventListener('playing', function() { playBtn.innerHTML = svgPause; });
        player.addEventListener('pause', function() { playBtn.innerHTML = svgPlay; });
        player.addEventListener('waiting', function() { playBtn.innerHTML = svgPause; });
        player.addEventListener('ended', function() { playBtn.innerHTML = svgPlay; });
    }
    var pp = '{$pParamJs}';
    var audioIdx = 0;
    var subtitleIdx = -1;
    var subtitleUrls = [];
    var subtitleTypes = [];
    var subtitleCues = [];
    var currentBurnSub = -1;
    var subDiv = null;
    var resyncBtn = null;
    var step = 'native'; // toujours essayer natif d'abord (native → remux → transcode)
    var confirmedStep = ''; // mode confirmé qui fonctionne (évite de re-tester)
    var currentSpeed = 1; // vitesse de lecture, persistée entre les restarts de stream
    var seekOffset = 0;
    var totalDuration = 0;
    var dragging = false;
    var seekDebounce = null;
    var seekPending = false;

    var currentQuality = 720;
    var videoHeight = 0;
    var tapBtn = null;
    var hasFailed = false;
    var hintTimer = null;
    var videoWidthTimer = null;

    function buildUrl(mode, audio, startSec) {
        if (mode === 'native') return base + '?' + pp + 'stream=1';
        var url = base + '?' + pp + 'stream=' + mode + '&audio=' + (audio || 0);
        if (mode === 'transcode') {
            url += '&quality=' + currentQuality;
            if (currentBurnSub >= 0) url += '&burnSub=' + currentBurnSub;
        }
        if (startSec > 0) url += '&start=' + startSec.toFixed(1);
        return url;
    }

    // Figer la taille du player pendant un reload pour éviter le "saut"
    function lockSize() {
        if (isVideo && player.videoWidth > 0) {
            player.style.minHeight = player.offsetHeight + 'px';
        }
    }
    function unlockSize() {
        player.style.minHeight = '';
    }

    function startStream(resumeAt) {
        seekOffset = resumeAt || 0;
        hasFailed = false;
        clearTimeout(videoWidthTimer);
        lockSize();
        if (tapBtn) { tapBtn.remove(); tapBtn = null; }
        var url;
        if (isVideo) {
            var mode = confirmedStep || step;
            url = buildUrl(mode, audioIdx, seekOffset);
        } else {
            url = base + '?' + pp + 'stream=1';
        }
        player.src = url;
        player.load();
        player.playbackRate = currentSpeed;
        player.play().catch(function(e) {
            if (e && e.name === 'NotAllowedError') showTapToPlay();
        });
    }

    function showTapToPlay() {
        if (tapBtn) return;
        tapBtn = document.createElement('button');
        tapBtn.className = 'tap-play-btn';
        tapBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="flex-shrink:0"><polygon points="5,3 19,12 5,21"/></svg> Appuyer pour lire';
        tapBtn.addEventListener('click', function() {
            tapBtn.remove(); tapBtn = null;
            hintText.textContent = '';
            player.play().catch(function(){});
        });
        hintText.textContent = '';
        hintWrap.className = 'player-hint';
        hintWrap.appendChild(tapBtn);
    }

    function realTime() {
        return seekOffset + (player.currentTime || 0);
    }

    function fmtTime(s) {
        s = Math.max(0, Math.floor(s));
        var h = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        var sec = s % 60;
        if (h > 0) return h + ':' + (m < 10 ? '0' : '') + m + ':' + (sec < 10 ? '0' : '') + sec;
        return m + ':' + (sec < 10 ? '0' : '') + sec;
    }

    // Mise à jour de la barre de progression
    function updateSeekUI() {
        if (totalDuration <= 0) return;
        if (seekPending) return;
        var pos = realTime();
        var pct = Math.min(100, Math.max(0, (pos / totalDuration) * 100));
        seekFill.style.width = pct + '%';
        seekThumb.style.left = pct + '%';
        timeCurrent.textContent = fmtTime(pos);
    }

    // Mise à jour du buffer
    function updateBuffered() {
        if (totalDuration <= 0 || !player.buffered || !player.buffered.length) return;
        var buffEnd = player.buffered.end(player.buffered.length - 1);
        var buffPct = Math.min(100, ((seekOffset + buffEnd) / totalDuration) * 100);
        seekBuffered.style.width = buffPct + '%';
    }

    player.addEventListener('timeupdate', function() {
        if (!dragging) updateSeekUI();
        updateBuffered();
        showCue();
    });

    // Seek par clic/drag sur la barre
    function seekToFraction(frac) {
        if (tapBtn) return; // pas de seek avant le premier tap utilisateur
        var targetSec = Math.max(0, Math.min(totalDuration, frac * totalDuration));
        // Mettre à jour visuellement tout de suite et bloquer timeupdate
        seekPending = true;
        var pct = (targetSec / totalDuration) * 100;
        seekFill.style.width = pct + '%';
        seekThumb.style.left = pct + '%';
        timeCurrent.textContent = fmtTime(targetSec);
        // Debounce le seek
        clearTimeout(seekDebounce);
        seekDebounce = setTimeout(function() {
            if (confirmedStep === 'native') {
                // Seek natif : laisser le navigateur gérer via Range requests
                seekPending = false;
                player.currentTime = targetSec;
                hint.textContent = '';
            } else {
                startStream(targetSec); // met seekOffset = targetSec et player.currentTime = 0
                seekPending = false;    // updateSeekUI verra maintenant seekOffset correct
                hint.textContent = 'Chargement à ' + fmtTime(targetSec) + '...';
                hint.className = 'player-hint';
            }
        }, 300);
    }

    function getFraction(e) {
        var rect = seekBar.getBoundingClientRect();
        var x = (e.touches ? e.touches[0].clientX : e.clientX) - rect.left;
        return Math.max(0, Math.min(1, x / rect.width));
    }

    seekBar.addEventListener('mousedown', function(e) {
        if (totalDuration <= 0) return;
        dragging = true;
        seekBar.classList.add('dragging');
        seekToFraction(getFraction(e));
    });
    seekBar.addEventListener('touchstart', function(e) {
        if (totalDuration <= 0) return;
        dragging = true;
        seekBar.classList.add('dragging');
        seekToFraction(getFraction(e));
    }, {passive: true});

    document.addEventListener('mousemove', function(e) {
        if (dragging) seekToFraction(getFraction(e));
    });
    document.addEventListener('touchmove', function(e) {
        if (dragging) seekToFraction(getFraction(e));
    }, {passive: true});

    document.addEventListener('mouseup', function() {
        if (dragging) { dragging = false; seekBar.classList.remove('dragging'); }
    });
    document.addEventListener('touchend', function() {
        if (dragging) { dragging = false; seekBar.classList.remove('dragging'); }
    });

    // Applique les métadonnées probe (durée, sélecteurs) sans (re)lancer le stream
    function applyProbe(probeData) {
        if (!probeData) return;
        var hasControls = false;

        // Durée totale
        if (probeData.duration > 0) {
            totalDuration = probeData.duration;
            timeTotal.textContent = fmtTime(totalDuration);
            seekBar.style.display = 'flex';
            seekTimeEl.style.display = 'flex';
        }

        // Sélecteur audio
        if (probeData.audio && probeData.audio.length > 1) {
            hasControls = true;
            var lbl = document.createElement('label');
            lbl.textContent = 'Audio :';
            var sel = document.createElement('select');
            sel.className = 'track-select';
            probeData.audio.forEach(function(a) {
                var opt = document.createElement('option');
                opt.value = a.index;
                opt.textContent = a.label;
                sel.appendChild(opt);
            });
            sel.addEventListener('change', function() {
                var pos = realTime();
                audioIdx = parseInt(sel.value);
                confirmedStep = 'transcode';
                step = 'transcode';
                hint.textContent = 'Changement de piste...';
                hint.className = 'player-hint transcoding';
                startStream(pos);
            });
            trackBar.append(lbl, sel);
        }

        // Sélecteur de qualité
        if (probeData.videoHeight > 0) {
            videoHeight = probeData.videoHeight;
            var qualities = [480, 720, 1080].filter(function(q) { return q <= videoHeight; });
            if (qualities.length > 0) {
                currentQuality = qualities.indexOf(720) !== -1 ? 720 : qualities[qualities.length - 1];
                hasControls = true;
                var lbl3 = document.createElement('label');
                lbl3.textContent = 'Qualité :';
                var sel3 = document.createElement('select');
                sel3.className = 'track-select';
                qualities.forEach(function(q) {
                    var opt = document.createElement('option');
                    opt.value = q;
                    opt.textContent = q + 'p';
                    if (q === currentQuality) opt.selected = true;
                    sel3.appendChild(opt);
                });
                sel3.addEventListener('change', function() {
                    var pos = realTime();
                    currentQuality = parseInt(sel3.value);
                    confirmedStep = 'transcode';
                    hint.textContent = 'Transcodage ' + currentQuality + 'p...';
                    hint.className = 'player-hint transcoding';
                    startStream(pos);
                });
                trackBar.append(lbl3, sel3);
            }
        }

        // Sous-titres
        if (probeData.subtitles && probeData.subtitles.length > 0) {
            hasControls = true;
            probeData.subtitles.forEach(function(s) {
                subtitleUrls.push(s.type === 'text' ? base + '?' + pp + 'subtitle=' + s.index : null);
                subtitleTypes.push(s.type || 'text');
            });
            var lbl2 = document.createElement('label');
            lbl2.textContent = 'Sous-titres :';
            var selSub = document.createElement('select');
            selSub.className = 'track-select';
            var offOpt = document.createElement('option');
            offOpt.value = '-1';
            offOpt.textContent = 'Désactivés';
            selSub.appendChild(offOpt);
            probeData.subtitles.forEach(function(s, i) {
                var opt = document.createElement('option');
                opt.value = i;
                opt.textContent = s.label;
                selSub.appendChild(opt);
            });
            selSub.addEventListener('change', function() {
                loadSubtitle(parseInt(selSub.value));
            });
            trackBar.append(lbl2, selSub);
        }

        if (hasControls) trackBar.style.display = 'flex';
    }

    // Pour les vidéos : Resync toujours visible immédiatement, indépendamment du probe
    if (isVideo) {
        resyncBtn = document.createElement('button');
        resyncBtn.className = 'player-btn';
        resyncBtn.title = 'Resynchroniser son et image';
        resyncBtn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg> Resync';
        resyncBtn.addEventListener('click', function() {
            if (confirmedStep === 'native') {
                var pos = player.currentTime;
                player.currentTime = Math.max(0, pos - 0.1);
                return;
            }
            hint.textContent = 'Resync...';
            hint.className = 'player-hint';
            startStream(realTime());
        });
        trackBar.appendChild(resyncBtn);
        trackBar.style.display = 'flex';
    }

    // Démarrer le stream immédiatement, puis appliquer le probe en arrière-plan
    // (évite que les contrôles restent cachés si ffprobe est lent sur cache froid)
    if (isVideo) {
        startStream(0);
        var probeCtrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var probeTimer = setTimeout(function() { if (probeCtrl) probeCtrl.abort(); }, 12000);
        var fetchOpts = probeCtrl ? { signal: probeCtrl.signal } : {};
        fetch(base + '?' + pp + 'probe=1', fetchOpts)
            .then(function(r) { clearTimeout(probeTimer); return r.json(); })
            .then(function(data) { applyProbe(data); })
            .catch(function() { clearTimeout(probeTimer); });
    } else {
        startStream(0);
    }

    // Overlay sous-titres custom (indépendant des textTracks navigateur)
    (function() {
        subDiv = document.createElement('div');
        subDiv.className = 'sub-overlay';
        player.parentNode.appendChild(subDiv);

        function updateSubPos() {
            var wrapRect = player.parentNode.getBoundingClientRect();
            var vidRect  = player.getBoundingClientRect();
            var vw = player.videoWidth, vh = player.videoHeight;
            // Espace entre bas de <video> et bas du wrapper (bandes noires du wrapper)
            var spaceBelow = wrapRect.bottom - vidRect.bottom;
            // Bandes noires internes à l'élément <video> (object-fit:contain)
            var barH = 0, contentH = vidRect.height;
            if (vw && vh && vidRect.width && vidRect.height) {
                var videoAR = vw / vh, elemAR = vidRect.width / vidRect.height;
                if (videoAR > elemAR) {
                    contentH = vidRect.width / videoAR;
                    barH = (vidRect.height - contentH) / 2;
                }
            }
            subDiv.style.bottom = (spaceBelow + barH + contentH * 0.08) + 'px';
            subDiv.style.fontSize = Math.max(13, Math.round(vidRect.width * 0.025)) + 'px';
        }

        updateSubPos();
        player.addEventListener('loadedmetadata', updateSubPos);
        player.addEventListener('resize', updateSubPos);
        document.addEventListener('fullscreenchange', function() { setTimeout(updateSubPos, 50); });
        document.addEventListener('webkitfullscreenchange', function() { setTimeout(updateSubPos, 50); });
        if (window.ResizeObserver) {
            new ResizeObserver(function() { updateSubPos(); }).observe(player);
        } else {
            window.addEventListener('resize', updateSubPos);
        }
    })();

    function vttTime(s) {
        var p = s.trim().split(':');
        return p.length === 3 ? +p[0]*3600 + +p[1]*60 + parseFloat(p[2]) : +p[0]*60 + parseFloat(p[1]);
    }

    function parseVTT(text) {
        var cues = [], blocks = text.replace(/\\r\\n/g,'\\n').split(/\\n\\n+/);
        for (var b = 0; b < blocks.length; b++) {
            var lines = blocks[b].trim().split('\\n'), ti = -1;
            for (var l = 0; l < lines.length; l++) {
                if (lines[l].indexOf(' --> ') !== -1) { ti = l; break; }
            }
            if (ti < 0) continue;
            var parts = lines[ti].split(' --> ');
            var txt = lines.slice(ti+1).join('\\n').trim();
            if (txt) cues.push({ start: vttTime(parts[0]), end: vttTime(parts[1].split(' ')[0]), text: txt });
        }
        return cues;
    }

    function showCue() {
        if (!subDiv) return;
        var t = realTime(), txt = '';
        for (var i = 0; i < subtitleCues.length; i++) {
            if (t >= subtitleCues[i].start && t < subtitleCues[i].end) { txt = subtitleCues[i].text; break; }
        }
        var safe = txt.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                      .replace(/&lt;(\/?(b|i|u|em|strong|s))&gt;/gi,'<$1>');
        var html = txt
            ? '<span style="background:rgba(0,0,0,.78);color:#fff;padding:.2em .6em;border-radius:4px;line-height:1.4;display:inline-block;max-width:100%;word-break:break-word;white-space:pre-line">'
              + safe + '</span>'
            : '';
        if (subDiv.innerHTML !== html) subDiv.innerHTML = html;
    }

    function loadSubtitle(idx) {
        subtitleIdx = idx;
        subtitleCues = [];
        if (subDiv) subDiv.innerHTML = '';
        var pos = realTime();
        var wasBurning = currentBurnSub >= 0;

        if (idx >= 0 && subtitleTypes[idx] === 'image') {
            // Sous-titre image (PGS) : burn-in via transcode
            currentBurnSub = idx;
            confirmedStep = 'transcode';
            step = 'transcode';
            hint.textContent = 'Transcodage avec sous-titres...';
            hint.className = 'player-hint transcoding';
            startStream(pos);
        } else if (idx >= 0) {
            // Sous-titre texte : overlay JS, pas de restart sauf si on sortait du burn-in
            currentBurnSub = -1;
            if (wasBurning) startStream(pos);
            fetch(subtitleUrls[idx], { credentials: 'same-origin' })
                .then(function(r) { return r.text(); })
                .then(function(t) { subtitleCues = parseVTT(t); })
                .catch(function() {});
        } else {
            // Désactivés
            currentBurnSub = -1;
            if (wasBurning) startStream(pos);
        }
    }

    player.addEventListener('playing', function() {
        unlockSize();
        var mode = confirmedStep || step;
        if ((mode === 'native' || mode === 'remux') && isVideo && !confirmedStep) {
            // Vérifier que le navigateur décode vraiment (videoWidth > 0)
            // native : 2s (les décodeurs HEVC/AV1 natifs peuvent être lents)
            // remux  : 1.5s
            var delay = mode === 'native' ? 2000 : 1500;
            videoWidthTimer = setTimeout(function() {
                if (player.videoWidth === 0) {
                    onFail();
                } else {
                    confirmedStep = mode;
                    hint.textContent = '';
                }
            }, delay);
            return;
        }
        hint.textContent = '';
    });

    function onFail() {
        if (tapBtn || hasFailed) return;
        hasFailed = true;
        var pos = realTime();
        if (!confirmedStep && step === 'native') {
            step = 'transcode';
            confirmedStep = 'transcode';
            hint.textContent = 'Transcodage en cours...';
            hint.className = 'player-hint transcoding';
            startStream(pos);
        } else {
            hint.textContent = 'Lecture impossible. Utilisez le bouton Télécharger.';
            hint.className = 'player-hint error';
        }
    }

    player.addEventListener('error', onFail);

    // Stall watchdog : si aucune donnée après 25s en mode transcode → retry automatique
    var stallTimer = null;
    var stallCount = 0;
    var stallInterval = null;
    function startStallWatchdog() {
        clearStallWatchdog();
        if (!isVideo || confirmedStep === 'native') return;
        var elapsed = 0;
        stallInterval = setInterval(function() {
            elapsed++;
            if (!player.paused && player.readyState < 3) {
                hint.textContent = 'Chargement... ' + elapsed + 's';
                hint.className = 'player-hint';
            }
        }, 1000);
        stallTimer = setTimeout(function() {
            clearStallWatchdog();
            if (player.readyState < 3 && !player.paused) {
                stallCount++;
                hint.textContent = 'Retry #' + stallCount + '...';
                hint.className = 'player-hint';
                startStream(realTime());
            }
        }, 25000);
    }
    function clearStallWatchdog() {
        clearTimeout(stallTimer);
        clearInterval(stallInterval);
        stallTimer = null;
        stallInterval = null;
    }
    player.addEventListener('waiting', startStallWatchdog);
    player.addEventListener('playing', clearStallWatchdog);
    player.addEventListener('pause',   clearStallWatchdog);
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
