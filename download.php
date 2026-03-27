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
        header('Content-Type: application/json; charset=utf-8');

        // Cache SQLite : évite de relancer ffprobe si le fichier n'a pas changé
        $mtime = filemtime($resolvedPath);
        $cached = $db->prepare("SELECT result FROM probe_cache WHERE path = :p AND mtime = :m");
        $cached->execute([':p' => $resolvedPath, ':m' => $mtime]);
        if ($row = $cached->fetch()) {
            // Invalider les entrées cache sans isMP4/isMKV (champs ajoutés après coup)
            $decoded = json_decode($row['result'], true);
            if (isset($decoded['isMP4']) && isset($decoded['isMKV'])) {
                stream_log('PROBE cache-hit | ' . basename($resolvedPath) . ' | codec=' . ($decoded['videoCodec'] ?? '?') . ' h=' . ($decoded['videoHeight'] ?? '?') . ' audio=' . count($decoded['audio'] ?? []) . ' subs=' . count($decoded['subtitles'] ?? []));
                echo $row['result'];
                exit;
            }
        }

        $probeFp = acquireProbeSlot();
        if (!$probeFp) {
            stream_log('PROBE 429 | ' . basename($resolvedPath) . ' | all probe slots busy');
            http_response_code(429);
            echo json_encode(['error' => 'too_many_probes']);
            exit;
        }

        $cmd = 'timeout 10 ffprobe -v error -show_entries format=duration,format_name -show_entries stream=index,codec_type,codec_name,width,height:stream_tags=language,title -of json '
            . escapeshellarg($resolvedPath) . ' 2>/dev/null';
        $output = shell_exec($cmd);
        $data = json_decode($output, true);
        $duration = (float)($data['format']['duration'] ?? 0);
        $formatName = $data['format']['format_name'] ?? '';
        $isMP4 = str_contains($formatName, 'mp4') || str_contains($formatName, 'mov');
        $isMKV = str_contains($formatName, 'matroska') || str_contains($formatName, 'webm');
        $audio = [];
        $subs = [];
        $audioIdx = 0;
        $subIdx = 0;
        $videoHeight = 0;
        $videoCodec = '';
        foreach (($data['streams'] ?? []) as $s) {
            $lang  = $s['tags']['language'] ?? '';
            $title = strip_tags($s['tags']['title'] ?? '');
            if ($s['codec_type'] === 'video' && !$videoHeight && isset($s['height'])
                && !in_array($s['codec_name'] ?? '', ['mjpeg', 'png', 'bmp'])) {
                $videoHeight = (int)$s['height'];
                $videoCodec = $s['codec_name'] ?? '';
            } elseif ($s['codec_type'] === 'audio') {
                $label = $lang ? strtoupper($lang) : 'Piste ' . ($audioIdx + 1);
                if ($title) $label .= ' — ' . $title;
                $audio[] = ['index' => $audioIdx, 'stream' => $s['index'], 'codec' => $s['codec_name'], 'lang' => $lang, 'label' => $label];
                $audioIdx++;
            } elseif ($s['codec_type'] === 'subtitle') {
                $imageCodecs = ['hdmv_pgs_subtitle', 'dvd_subtitle', 'dvb_subtitle', 'dvb_teletext', 'xsub', 'eia_608', 'eia_708'];
                $subType = in_array($s['codec_name'] ?? '', $imageCodecs) ? 'image' : 'text';
                $label = $lang ? strtoupper($lang) : 'Sous-titre ' . ($subIdx + 1);
                if ($title) $label .= ' — ' . $title;
                if ($subType === 'image') $label .= ' ★'; // indique burn-in requis
                $subs[] = ['index' => $subIdx, 'stream' => $s['index'], 'codec' => $s['codec_name'], 'lang' => $lang, 'label' => $label, 'type' => $subType];
                $subIdx++;
            }
        }
        $result = json_encode(['audio' => $audio, 'subtitles' => $subs, 'duration' => $duration, 'videoHeight' => $videoHeight, 'videoCodec' => $videoCodec, 'isMP4' => $isMP4, 'isMKV' => $isMKV]);
        stream_log('PROBE ffprobe | ' . basename($resolvedPath) . ' | codec=' . $videoCodec . ' h=' . $videoHeight . ' dur=' . round($duration) . 's fmt=' . $formatName . ' audio=' . count($audio) . ' subs=' . count($subs));

        // Stocker en cache (best-effort : on ignore si la DB est encore verrouillée)
        try {
            $db->prepare("INSERT OR REPLACE INTO probe_cache (path, mtime, result) VALUES (:p, :m, :r)")
               ->execute([':p' => $resolvedPath, ':m' => $mtime, ':r' => $result]);
        } catch (PDOException $e) { /* lock résiduel — le probe sera recalculé au prochain appel */ }

        releaseProbeSlot($probeFp);
        echo $result;
        exit;
    }

    // Extraction sous-titre en WebVTT (avec cache SQLite)
    if (isset($_GET['subtitle'])) {
        $trackIdx = max(0, (int)$_GET['subtitle']);
        header('Content-Type: text/vtt; charset=utf-8');
        $mtime = filemtime($resolvedPath);
        $cached = $db->prepare("SELECT vtt FROM subtitle_cache WHERE path = :p AND track = :t AND mtime = :m");
        $cached->execute([':p' => $resolvedPath, ':t' => $trackIdx, ':m' => $mtime]);
        if ($row = $cached->fetch()) {
            stream_log('SUBTITLE cache-hit | track=' . $trackIdx . ' | ' . basename($resolvedPath));
            echo $row['vtt'];
            exit;
        }
        stream_log('SUBTITLE extract | track=' . $trackIdx . ' | ' . basename($resolvedPath));
        $logFile = defined('STREAM_LOG') && STREAM_LOG ? STREAM_LOG : '/dev/null';
        ob_start();
        $cmd = 'timeout 60 ffmpeg -i ' . escapeshellarg($resolvedPath)
            . ' -map 0:s:' . $trackIdx . ' -f webvtt pipe:1 -loglevel error 2>>' . escapeshellarg($logFile);
        passthru($cmd);
        $vtt = ob_get_flush();
        if ($vtt) {
            try {
                $db->prepare("INSERT OR REPLACE INTO subtitle_cache (path, track, mtime, vtt) VALUES (:p, :t, :m, :v)")
                   ->execute([':p' => $resolvedPath, ':t' => $trackIdx, ':m' => $mtime, ':v' => $vtt]);
            } catch (PDOException $e) { /* lock — recalculé au prochain appel */ }
        }
        exit;
    }

    // Keyframe lookup : retourne le PTS réel du keyframe coarse-seeké par ffmpeg
    // Le JS corrige S.offset rétroactivement pour éliminer le drift des sous-titres
    if (isset($_GET['keyframe'])) {
        header('Content-Type: application/json; charset=utf-8');
        $seekSec = max(0.0, (float)($_GET['keyframe'] ?? 0));
        if ($seekSec <= 0.0) { echo json_encode(['pts' => 0.0]); exit; }
        $probeFp = acquireProbeSlot();
        if (!$probeFp) { echo json_encode(['pts' => $seekSec]); exit; }
        $cmd = 'timeout 5 ffprobe -v error -ss ' . escapeshellarg(sprintf('%.3f', $seekSec))
            . ' -select_streams v:0 -skip_frame nokey -show_entries frame=pts_time'
            . ' -frames:v 1 -of csv=p=0 '
            . escapeshellarg($resolvedPath) . ' 2>/dev/null';
        $kfLines = [];
        exec($cmd, $kfLines);
        releaseProbeSlot($probeFp);
        $pts = isset($kfLines[0]) && is_numeric($kfLines[0]) ? (float)$kfLines[0] : $seekSec;
        stream_log('KEYFRAME lookup | ' . basename($resolvedPath) . ' | seek=' . round($seekSec, 1) . ' → pts=' . round($pts, 1) . ' drift=' . round(abs($seekSec - $pts), 1) . 's');
        echo json_encode(['pts' => $pts]);
        exit;
    }

    // Mode streaming natif : sert le fichier brut (audio uniquement, ou fallback)
    if (isset($_GET['stream']) && $_GET['stream'] === '1') {
        $mime = get_stream_mime(strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION)));
        if ($mime) {
            stream_log('NATIVE stream | ' . basename($resolvedPath) . ' | mime=' . $mime . ' size=' . format_taille(filesize($resolvedPath)));
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

    // Seek coarse-only (keyframe seek avant -i). Imprécision max : ~2s sur x265 UHD.
    $startSec = isset($_GET['start']) ? max(0, (float)$_GET['start']) : 0;
    $seekArgBefore = $startSec > 0 ? ' -ss ' . escapeshellarg(sprintf('%.3f', $startSec)) : '';

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
            $cmd = buildFfmpegInputArgs($resolvedPath, $seekArgBefore)
                . $audioMap . ' -dn -c:v copy -c:a aac -ac 2 -b:a 192k'
                . ' -af "aresample=async=3000:first_pts=0"'
                . buildFmp4MuxerArgs()
                . ' -f mp4 -y pipe:1 -loglevel error 2>>' . escapeshellarg($logFile);
            [$slotFp, $queued] = acquireStreamSlot();
            if ($queued) { stream_log('REMUX queued | ' . basename($resolvedPath)); header('X-Stream-Queued: 1'); }
            warmFileCache($resolvedPath);
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
            $quality = validateQuality($quality);
            $burnSub = isset($_GET['burnSub']) ? max(0, (int)$_GET['burnSub']) : -1;
            header('Content-Type: video/mp4');
            header('Content-Disposition: inline');
            header('X-Accel-Buffering: no');
            header('Cache-Control: no-cache');
            // Content-Length estimé : Safari coupe la connexion sans ça (pas de progressive download)
            // Estimation conservatrice basée sur le bitrate moyen par qualité + audio 192kbps
            $estimatedBitrates = [480 => 1800000, 576 => 2500000, 720 => 4000000, 1080 => 8000000];
            $estimatedBps = ($estimatedBitrates[$quality] ?? 4000000) + 192000;
            $probeDuration = 0;
            try {
                $cachedProbe = $db->prepare("SELECT result FROM probe_cache WHERE path = :p");
                $cachedProbe->execute([':p' => $resolvedPath]);
                if ($probeRow = $cachedProbe->fetch()) {
                    $probeData = json_decode($probeRow['result'], true);
                    $probeDuration = (float)($probeData['duration'] ?? 0);
                }
            } catch (PDOException $e) { /* ignore */ }
            $remainingDuration = max(0, $probeDuration - $startSec);
            $estimatedCL = $remainingDuration > 0 ? (int)($estimatedBps * $remainingDuration / 8 * 1.2) : 0;
            if ($estimatedCL > 0) {
                header('Content-Length: ' . $estimatedCL);
            }
            $logFile = defined('STREAM_LOG') && STREAM_LOG ? STREAM_LOG : '/dev/null';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '-';
            $isSafari = str_contains($ua, 'Safari') && !str_contains($ua, 'Chrome');
            stream_log('CL=' . ($estimatedCL ?: 'none') . ' dur=' . round($probeDuration) . 's rem=' . round($remainingDuration) . 's' . ($isSafari ? ' [Safari]' : '') . ' | UA=' . substr($ua, 0, 80));
            $logLabel = $burnSub >= 0 ? 'TRANSCODE+SUB' : 'TRANSCODE';
            stream_log($logLabel . ' start | quality=' . $quality . 'p audio=' . $audioTrack . ($burnSub >= 0 ? ' burnSub=' . $burnSub : '') . ' start=' . $startSec . ' | ' . basename($resolvedPath));
            $fc = buildFilterGraph($quality, $audioTrack, $burnSub);
            $cmd = buildFfmpegInputArgs($resolvedPath, $seekArgBefore)
                . ' -filter_complex ' . $fc . ' -map "[v]" -map "[a]" -dn'
                . buildFfmpegCodecArgs(25) . buildFmp4MuxerArgs()
                . ' -f mp4 -y pipe:1 -loglevel error 2>>' . escapeshellarg($logFile);
            [$slotFp, $queued] = acquireStreamSlot();
            if ($queued) { stream_log('TRANSCODE queued | ' . basename($resolvedPath)); header('X-Stream-Queued: 1'); }
            warmFileCache($resolvedPath);
            passthru($cmd);
            releaseStreamSlot($slotFp);
            exit;
        }
    }

    // Mode HLS : transcodage en segments TS pour iOS Safari
    // Safari refuse le streaming fMP4 progressif (broken pipe) — HLS est le seul format
    // que Safari iOS supporte nativement pour le streaming adaptatif.
    if (isset($_GET['stream']) && $_GET['stream'] === 'hls') {
        $mime = get_stream_mime(strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION)));
        if ($mime && str_starts_with($mime, 'video/')) {
            $quality = isset($_GET['quality']) ? (int)$_GET['quality'] : 720;
            $quality = validateQuality($quality);
            $burnSub = isset($_GET['burnSub']) ? max(0, (int)$_GET['burnSub']) : -1;
            $logFile = defined('STREAM_LOG') && STREAM_LOG ? STREAM_LOG : '/dev/null';

            // Dossier temp unique par fichier+qualité+audio+burnSub (PAS startSec)
            // Seek ne crée pas de nouvelle session — le JS gère le seek dans la playlist existante
            $hlsKey = md5($resolvedPath . '|' . $quality . '|' . $audioTrack . '|' . $burnSub);
            $hlsDir = sys_get_temp_dir() . '/hls_' . $hlsKey;
            $m3u8   = $hlsDir . '/stream.m3u8';
            $pidFile = $hlsDir . '/ffmpeg.pid';

            // Servir un segment TS existant
            if (isset($_GET['seg'])) {
                $segName = basename($_GET['seg']);
                if (!preg_match('/^seg\d+\.ts$/', $segName)) { http_response_code(400); exit; }
                $segPath = $hlsDir . '/' . $segName;
                // Attendre que le segment soit prêt (max 10s)
                for ($w = 0; $w < 100 && !file_exists($segPath); $w++) { usleep(100000); }
                if (!file_exists($segPath)) { http_response_code(404); exit; }
                // Attendre que ffmpeg ait fini d'écrire le segment (taille stable pendant 100ms)
                $prevSize = 0;
                for ($w = 0; $w < 20; $w++) {
                    clearstatcache(true, $segPath);
                    $curSize = filesize($segPath);
                    if ($curSize > 0 && $curSize === $prevSize) break;
                    $prevSize = $curSize;
                    usleep(50000);
                }
                header('Content-Type: video/mp2t');
                header('Content-Length: ' . filesize($segPath));
                header('Cache-Control: no-cache');
                // Marquer activité pour le cleanup
                touch($hlsDir . '/.active');
                readfile($segPath);
                exit;
            }

            // Lancer ffmpeg HLS si pas déjà actif
            $needStart = !is_dir($hlsDir);
            // Si le dossier existe mais ffmpeg est mort, relancer
            if (!$needStart && is_file($pidFile)) {
                $pid = (int)file_get_contents($pidFile);
                if ($pid > 0 && !file_exists('/proc/' . $pid)) {
                    // ffmpeg est mort — si seek demandé, relancer avec le nouveau start
                    if ($startSec > 0) {
                        // Nettoyer l'ancien dossier et relancer
                        array_map('unlink', glob($hlsDir . '/*'));
                        $needStart = true;
                    }
                }
            }

            if ($needStart) {
                if (!is_dir($hlsDir)) mkdir($hlsDir, 0755, true);
                // Supprimer les anciens segments si relance
                array_map('unlink', glob($hlsDir . '/seg*.ts'));
                if (file_exists($m3u8)) unlink($m3u8);

                $fc = buildFilterGraph($quality, $audioTrack, $burnSub);

                [$slotFp, $queued] = acquireStreamSlot();
                if ($queued) stream_log('HLS queued | ' . basename($resolvedPath));
                stream_log('HLS start | quality=' . $quality . 'p audio=' . $audioTrack . ' start=' . $startSec . ' | ' . basename($resolvedPath));

                warmFileCache($resolvedPath);

                $ffmpegCmd = buildFfmpegInputArgs($resolvedPath, $seekArgBefore)
                    . ' -filter_complex ' . $fc . ' -map "[v]" -map "[a]" -dn'
                    . buildFfmpegCodecArgs(50)
                    . ' -f hls -hls_time 4 -hls_list_size 0 -hls_segment_filename ' . escapeshellarg($hlsDir . '/seg%d.ts')
                    . ' -hls_flags append_list'
                    . ' ' . escapeshellarg($m3u8)
                    . ' -loglevel error 2>>' . escapeshellarg($logFile);

                // Lancer ffmpeg en arrière-plan et stocker son PID
                $pid = trim(shell_exec($ffmpegCmd . ' > /dev/null & echo $!'));
                file_put_contents($pidFile, $pid);
                touch($hlsDir . '/.active');

                // Cleanup background : attend que ffmpeg termine + 2min d'inactivité
                $slotPath = $slotFp ? stream_get_meta_data($slotFp)['uri'] : '';
                $activeFile = escapeshellarg($hlsDir . '/.active');
                $cleanupCmd = '('
                    . 'while kill -0 ' . (int)$pid . ' 2>/dev/null; do sleep 5; done; '   // attendre fin ffmpeg
                    . ($slotPath ? 'rm -f ' . escapeshellarg($slotPath) . '; ' : '')       // libérer le slot
                    . 'while [ $(($(date +%s) - $(stat -c %Y ' . $activeFile . ' 2>/dev/null || echo 0))) -lt 120 ]; do sleep 10; done; '  // attendre 2min sans activité
                    . 'rm -rf ' . escapeshellarg($hlsDir)
                    . ') >/dev/null 2>&1 &';
                exec($cleanupCmd);
                if ($slotFp) fclose($slotFp);
            } else {
                // Session existante — marquer activité
                touch($hlsDir . '/.active');
            }

            // Attendre que le .m3u8 existe et ait au moins un segment (max 15s)
            for ($w = 0; $w < 150; $w++) {
                clearstatcache(true, $m3u8);
                if (file_exists($m3u8) && filesize($m3u8) > 20) break;
                usleep(100000);
            }
            if (!file_exists($m3u8) || filesize($m3u8) <= 20) {
                stream_log('HLS timeout waiting for m3u8 | ' . basename($resolvedPath));
                http_response_code(504);
                header('Content-Type: application/vnd.apple.mpegurl');
                echo "#EXTM3U\n#EXT-X-ERROR:Timeout\n";
                exit;
            }

            // Réécrire le m3u8 : remplacer les noms de fichier locaux par des URLs absolues
            $m3u8Content = file_get_contents($m3u8);
            $baseStreamUrl = '/dl/' . $token . '?' . ($subPath ? 'p=' . rawurlencode($subPath) . '&' : '')
                . 'stream=hls&audio=' . $audioTrack . '&quality=' . $quality
                . ($burnSub >= 0 ? '&burnSub=' . $burnSub : '');
            $m3u8Rewritten = preg_replace('/^(seg\d+\.ts)$/m', $baseStreamUrl . '&seg=$1', $m3u8Content);

            header('Content-Type: application/vnd.apple.mpegurl');
            header('Cache-Control: no-cache');
            echo $m3u8Rewritten;
            exit;
        }
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
    header('Content-Disposition: attachment; filename="' . addcslashes($fileName, '"\\') . '"');
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
    $css = css_public();
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
    header('Cache-Control: no-store');

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
.player-card:fullscreen,
.player-card:-webkit-full-screen { position:relative; width:100vw; height:100vh; background:#000; border-radius:0; border:none; }
.player-card:fullscreen .player-video-wrap,
.player-card:-webkit-full-screen .player-video-wrap { position:absolute; inset:0; }
.player-card:fullscreen video,
.player-card:-webkit-full-screen video { position:absolute; inset:0; width:100%; height:100%; max-height:none; }
.player-card:fullscreen .player-controls,
.player-card:-webkit-full-screen .player-controls { position:absolute; bottom:0; left:0; right:0; z-index:20; background:linear-gradient(transparent 0%, rgba(8,10,18,.4) 40%, rgba(8,10,18,.92) 100%) !important; padding-top:3rem; transition:opacity .25s; border-top:none !important; }
.player-card:fullscreen .player-controls .ctrl-row button,
.player-card:-webkit-full-screen .player-controls .ctrl-row button,
.player-card:fullscreen .player-controls .ctrl-row svg,
.player-card:-webkit-full-screen .player-controls .ctrl-row svg { filter:drop-shadow(0 1px 3px rgba(0,0,0,.8)); }
.fs-title { display:none; }
.player-card:fullscreen .fs-title,
.player-card:-webkit-full-screen .fs-title { display:flex; align-items:center; gap:.6rem; position:absolute; top:0; left:0; right:0; z-index:20; padding:1.2rem 1.5rem; background:linear-gradient(rgba(8,10,18,.8) 0%, rgba(8,10,18,.3) 60%, transparent 100%); font-family:var(--font-sans); font-size:clamp(.82rem,1.4vw,1.05rem); font-weight:600; color:rgba(255,255,255,.88); letter-spacing:.01em; text-shadow:0 1px 6px rgba(0,0,0,.7); transition:opacity .25s; }
.fs-title-text { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; min-width:0; }
.fs-title .player-nav-btn { background:rgba(255,255,255,.1); border-color:rgba(255,255,255,.2); color:#fff; padding:.35rem .6rem; flex-shrink:0; text-shadow:none; filter:drop-shadow(0 1px 3px rgba(0,0,0,.6)); }
.fs-title .player-nav-btn:hover { background:rgba(240,160,48,.2); border-color:rgba(240,160,48,.4); color:var(--accent); }
.player-card:fullscreen .fs-title.fs-hidden,
.player-card:-webkit-full-screen .fs-title.fs-hidden { opacity:0; }
.player-card:fullscreen .player-controls .track-bar,
.player-card:-webkit-full-screen .player-controls .track-bar { border-top:none; }
.player-card:fullscreen .player-controls.fs-hidden,
.player-card:-webkit-full-screen .player-controls.fs-hidden { opacity:0; pointer-events:none; }
.player-card.hide-cursor,.player-card.hide-cursor * { cursor:none !important; }
video { display:block; width:100%; max-height:78vh; background:#000; object-fit:contain; }
.sub-overlay { position:absolute; left:0; right:0; text-align:center; pointer-events:none; padding:0 6%; z-index:10; font-size:1.5rem; }
.play-icon-overlay { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); width:72px; height:72px; background:rgba(0,0,0,.5); border-radius:50%; display:flex; align-items:center; justify-content:center; pointer-events:none; opacity:0; transition:opacity .2s; z-index:15; }
.play-icon-overlay svg { width:30px; height:30px; color:#fff; }
.play-icon-overlay.visible { opacity:1; }
@keyframes popPause { 0%{transform:translate(-50%,-50%) scale(.6);opacity:.8} 100%{transform:translate(-50%,-50%) scale(1);opacity:1} }
@keyframes popPlay  { 0%{transform:translate(-50%,-50%) scale(.6);opacity:.9} 50%{transform:translate(-50%,-50%) scale(1);opacity:.9} 100%{transform:translate(-50%,-50%) scale(1.4);opacity:0} }
.play-icon-overlay.pop-pause { animation:popPause .2s ease forwards; }
.play-icon-overlay.pop-play  { animation:popPlay .4s ease forwards; }
#vol-osd { position:absolute; top:clamp(1rem,3vh,2rem); right:clamp(1rem,3vw,2rem); z-index:20; background:rgba(0,0,0,.72); color:#fff; padding:clamp(.5rem,1.2vh,.9rem) clamp(1rem,2vw,1.6rem); border-radius:clamp(.5rem,1vh,.75rem); font-size:clamp(1.35rem,2.8vh,2.4rem); font-weight:700; letter-spacing:.03em; pointer-events:none; opacity:0; transition:opacity .2s; backdrop-filter:blur(6px); border:1px solid rgba(255,255,255,.08); text-shadow:0 1px 4px rgba(0,0,0,.5); }
#vol-osd.visible { opacity:1; }
audio { display:block; width:100%; padding:2rem 1.5rem; background:rgba(26,29,40,.8); }
.player-hint { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; pointer-events:none; z-index:10; }
.player-hint-text { font-family:var(--font-sans); font-size:.78rem; font-weight:600; padding:.4rem 1rem; border-radius:var(--radius-sm); background:rgba(12,14,20,.82); border:1px solid rgba(255,255,255,.08); color:var(--text-muted); backdrop-filter:blur(6px); letter-spacing:.01em; transition:color .2s; white-space:nowrap; }
.player-hint-text:empty { display:none; }
.player-hint.transcoding .player-hint-text { color:var(--accent); border-color:rgba(240,160,48,.2); }
.player-hint.error .player-hint-text { color:var(--red); border-color:rgba(239,83,80,.2); }
.player-controls { background:#0d0f1a; border-top:1px solid rgba(255,255,255,.055); padding:.5rem .8rem .45rem; }
.seek-bar { position:relative; height:32px; display:flex; align-items:center; cursor:pointer; user-select:none; -webkit-user-select:none; }
.seek-track { position:absolute; left:0; right:0; height:5px; background:rgba(255,255,255,.07); border-radius:3px; }
.seek-buffered { position:absolute; left:0; height:5px; background:rgba(255,255,255,.11); border-radius:3px; transition:width .3s; }
.seek-fill { position:absolute; left:0; height:5px; background:linear-gradient(90deg, var(--accent) 0%, #ffb020 100%); border-radius:3px; }
.seek-thumb { position:absolute; width:15px; height:15px; background:var(--accent); border-radius:50%; top:50%; transform:translate(-50%,-50%); box-shadow:0 0 0 3px rgba(240,160,48,.18), 0 2px 8px rgba(0,0,0,.5); transition:transform .1s, box-shadow .1s; z-index:2; }
.seek-thumb:hover, .seek-bar.dragging .seek-thumb { transform:translate(-50%,-50%) scale(1.3); box-shadow:0 0 0 5px rgba(240,160,48,.22), 0 2px 8px rgba(0,0,0,.5); }
.seek-bar.dragging .seek-fill { transition:none; }
.seek-time { display:none; align-items:center; gap:.3rem; font-size:.72rem; font-family:var(--font-mono); color:var(--text-muted); white-space:nowrap; padding:.4rem .1rem 0; margin-bottom:-4px; }
.seek-time .current { color:var(--text-primary); font-weight:600; }
.ctrl-row { position:relative; display:flex; align-items:center; gap:.45rem; margin-top:.3rem; }
.ctrl-spacer { flex:1; }
.ctrl-row .ctrl-play { position:absolute; left:50%; transform:translateX(-50%); }
.ctrl-row .ctrl-play:active { transform:translateX(-50%) scale(.92); }
.ctrl-play,.ctrl-mute { display:flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:50%; background:transparent; color:var(--text-muted); border:1px solid rgba(255,255,255,.08); cursor:pointer; flex-shrink:0; transition:background .15s,color .15s,border-color .15s; outline:none; -webkit-tap-highlight-color:transparent; }
.ctrl-play:hover,.ctrl-mute:hover { background:rgba(255,255,255,.06); color:var(--text-primary); border-color:rgba(255,255,255,.15); }
.ctrl-play:active,.ctrl-mute:active { transform:scale(.92); }
.track-bar { display:flex; align-items:center; gap:.4rem; flex-wrap:wrap; padding:.55rem 0 .3rem; border-top:1px solid rgba(255,255,255,.04); margin-top:.4rem; }
.track-bar label { color:var(--text-muted); font-size:.74rem; font-weight:600; letter-spacing:.02em; }
.track-select { padding:.26rem .5rem; border:1px solid var(--border); border-radius:20px; background:rgba(255,255,255,.04); color:var(--text-primary); font-family:var(--font-sans); font-size:.78rem; outline:none; cursor:pointer; -webkit-appearance:none; appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' fill='%238b90a0' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right .45rem center; padding-right:1.5rem; transition:border-color .15s; }
.track-select:hover { border-color:rgba(255,255,255,.14); }
.track-select:focus { border-color:var(--accent); }
.track-select option { background:#1a1d28; color:#e8eaf0; }
.resume-banner { position:absolute; top:1rem; left:50%; transform:translateX(-50%); z-index:25; display:flex; align-items:center; gap:.75rem; background:rgba(12,14,20,.92); border:1px solid rgba(240,160,48,.25); border-radius:var(--radius-md); padding:.7rem 1.2rem; backdrop-filter:blur(8px); animation:hintFade .3s ease both; font-size:.85rem; font-weight:500; color:var(--text-primary); white-space:nowrap; letter-spacing:.01em; }
.resume-banner button { padding:.5rem 1.1rem; border-radius:var(--radius-sm); font-family:var(--font-sans); font-size:.8rem; font-weight:700; cursor:pointer; border:none; transition:all .15s; }
.resume-banner .resume-yes { background:var(--accent); color:var(--bg-deep); }
.resume-banner .resume-yes:hover { background:#ffc060; }
.resume-banner .resume-no { background:rgba(255,255,255,.08); color:var(--text-secondary); border:1px solid rgba(255,255,255,.1); }
.resume-banner .resume-no:hover { background:rgba(255,255,255,.12); color:var(--text-primary); }
@media(max-width:480px){.page{padding:.9rem .75rem 3rem}.player-name{display:none}.player-btn.accent span{display:none}.track-bar label{display:none}.track-bar{gap:.3rem}}
@media(orientation:landscape) and (max-height:500px){
.page{padding:0;overflow:hidden;height:100vh}
.player-toolbar{position:fixed;top:0;left:0;right:0;z-index:30;background:linear-gradient(rgba(8,10,18,.85),transparent);padding:.5rem .8rem;margin-bottom:0;transition:opacity .25s}
.player-card{border-radius:0;border:none;position:fixed;inset:0;z-index:10}
.player-video-wrap{height:100vh}
video{max-height:100vh !important;height:100vh !important}
.player-controls{position:fixed;bottom:0;left:0;right:0;z-index:20;background:linear-gradient(transparent 0%,rgba(8,10,18,.4) 40%,rgba(8,10,18,.92) 100%) !important;padding-top:2.5rem;border-top:none !important;transition:opacity .25s}
.fs-title{display:block;position:fixed;top:0;left:0;right:0;z-index:30;padding:.8rem 1rem;background:linear-gradient(rgba(8,10,18,.8) 0%,transparent 100%);transition:opacity .25s}
.player-controls.fs-hidden{opacity:0;pointer-events:none}
.player-card.hide-cursor .player-toolbar{opacity:0;pointer-events:none}
.track-bar{display:none !important}
.player-name{display:none}
}
.seek-tooltip { position:absolute; bottom:calc(100% + 6px); background:rgba(12,14,20,.9); border:1px solid rgba(255,255,255,.1); color:var(--text-primary); font-family:var(--font-mono); font-size:.68rem; padding:.18rem .45rem; border-radius:4px; pointer-events:none; white-space:nowrap; transform:translateX(-50%); display:none; z-index:5; }
.vol-wrap { display:flex; align-items:center; gap:.3rem; }
.vol-slider { -webkit-appearance:none; appearance:none; width:60px; height:16px; background:transparent; outline:none; cursor:pointer; vertical-align:middle; }
.vol-slider::-webkit-slider-runnable-track { height:3px; border-radius:2px; background:linear-gradient(to right,#f0a030 0%,#f0a030 var(--vol-pct,100%),rgba(255,255,255,.15) var(--vol-pct,100%),rgba(255,255,255,.15) 100%); }
.vol-slider::-webkit-slider-thumb { -webkit-appearance:none; width:11px; height:11px; border-radius:50%; background:var(--accent); cursor:pointer; margin-top:-4px; box-shadow:0 0 0 2px rgba(240,160,48,.2); }
.vol-slider::-moz-range-track { height:3px; border-radius:2px; background:rgba(255,255,255,.15); }
.vol-slider::-moz-range-progress { height:3px; border-radius:2px; background:#f0a030; }
.vol-slider::-moz-range-thumb { width:11px; height:11px; border-radius:50%; background:var(--accent); cursor:pointer; border:none; }
@media(max-width:480px){.vol-slider{width:44px}}
.mode-badge { display:inline-flex; align-items:center; font-family:var(--font-mono); font-size:.68rem; font-weight:700; padding:.4rem .55rem; border-radius:3px; border:1px solid; cursor:pointer; white-space:nowrap; transition:all .15s; letter-spacing:.04em; }
.mode-badge.m-native   { color:#6b7280; border-color:rgba(255,255,255,.1);  background:rgba(255,255,255,.03); }
.mode-badge.m-remux    { color:#4ade80; border-color:rgba(74,222,128,.25);  background:rgba(74,222,128,.07); }
.mode-badge.m-transcode{ color:#f0a030; border-color:rgba(240,160,48,.25); background:rgba(240,160,48,.07); }
.mode-badge:hover { filter:brightness(1.25); }
.player-nav-btn { opacity:.85; transition:all .15s; }
.player-nav-btn:hover { opacity:1; background:rgba(240,160,48,.12); border-color:rgba(240,160,48,.25); color:var(--accent); }
.player-nav-btn svg { width:14px; height:14px; }
.autonext-overlay { position:absolute; inset:0; z-index:50; display:flex; flex-direction:column; align-items:center; justify-content:center; background:rgba(8,10,18,.88); backdrop-filter:blur(6px); animation:hintFade .3s ease both; }
.autonext-title { font-size:.85rem; color:var(--text-secondary); margin-bottom:.5rem; }
.autonext-name { font-size:1.1rem; font-weight:700; color:var(--text-primary); margin-bottom:1.2rem; max-width:80%; text-align:center; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.autonext-countdown { font-size:.8rem; color:var(--text-muted); margin-bottom:1rem; }
.autonext-actions { display:flex; gap:.6rem; }
.autonext-actions button { padding:.5rem 1.1rem; border-radius:var(--radius-sm); font-family:var(--font-sans); font-size:.8rem; font-weight:700; cursor:pointer; border:none; transition:all .15s; }
.autonext-actions .an-play { background:var(--accent); color:var(--bg-deep); }
.autonext-actions .an-play:hover { background:#ffc060; }
.autonext-actions .an-cancel { background:rgba(255,255,255,.08); color:var(--text-secondary); border:1px solid rgba(255,255,255,.1); }
.autonext-actions .an-cancel:hover { background:rgba(255,255,255,.12); color:var(--text-primary); }
    </style>
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
var REMUX_ENABLED = {$remuxEnabled};
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
    var isVideo     = {$isVideo};
    var base        = '{$baseUrl}';
    var pp          = '{$pParamJs}';
    var episodeNav  = {$episodeNavJson};

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
        audioIdx: 0,    quality: 720,    burnSub: -1,  isMP4: false,  isMKV: false,
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
    function realTime() { return useHLS ? (player.currentTime || 0) : S.offset + (player.currentTime || 0); }
    // Safari iOS : utiliser HLS au lieu de fMP4 (Safari coupe les streams fMP4 sans Range support)
    var isSafari = /Safari/.test(navigator.userAgent) && !/Chrome/.test(navigator.userAgent);
    var useHLS = isIOS || (isSafari && 'ontouchend' in document);
    function buildUrl(mode, audio, startSec) {
        if (mode === 'native') return base + '?' + pp + 'stream=1';
        // Sur Safari iOS, remplacer transcode par HLS
        var streamMode = (mode === 'transcode' && useHLS) ? 'hls' : mode;
        var url = base + '?' + pp + 'stream=' + streamMode + '&audio=' + (audio || 0);
        if (mode === 'transcode' || streamMode === 'hls') {
            url += '&quality=' + S.quality;
            if (S.burnSub >= 0) url += '&burnSub=' + S.burnSub;
        }
        if (startSec > 0) url += '&start=' + startSec.toFixed(1);
        return url;
    }

    // ── Position et config mémorisées ───────────────────────────────────────
    var posKey   = 'player_seek_' + base + pp;
    var cfgKey   = 'player_cfg_'  + base + pp;
    var savedPos = isVideo ? Math.max(0, parseFloat(lsGet(posKey, '0')) || 0) : 0;
    var savedCfg = (function() { try { return JSON.parse(lsGet(cfgKey, 'null')); } catch(e) { return null; } })();
    function saveCfg() {
        lsSet(cfgKey, JSON.stringify({
            mode: S.confirmed || '',
            audio: S.audioIdx,
            quality: S.quality,
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
    var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
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
            if (hevcSupported && nativeAudio) return 'native';
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
        var base = S.confirmed === 'remux' ? 10000 : (S.burnSub >= 0 ? 30000 : 20000);
        return Math.min(base * Math.pow(2, S.stallCount), 120000); // exponentiel, cap 2min
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
        _div: null, _idx: 0, _gen: 0,
        resetIdx: function() { this._idx = this.cues.length ? this._find(realTime()) : 0; },
        _find: function(t) {
            var lo = 0, hi = this.cues.length;
            while (lo < hi) { var mid = (lo + hi) >> 1; if (this.cues[mid].end <= t) lo = mid + 1; else hi = mid; }
            return lo;
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
            if (idx >= 0 && this.types[idx] === 'image') {
                S.burnSub = idx; S.confirmed = S.step = 'transcode';
                hint.textContent = 'Transcodage avec sous-titres...'; hint.className = 'player-hint transcoding';
                startStream(pos);
            } else if (idx >= 0 && this.urls[idx]) {
                if (wasBurning) startStream(pos);
                var self = this;
                fetch(this.urls[idx], {credentials:'same-origin'})
                    .then(function(r) { return r.text(); })
                    .then(function(t) {
                        if (gen !== self._gen) { plog('SUBS', 'DISCARDED: gen=' + gen + ' current=' + self._gen); return; }
                        self.cues = parseVTT(t);
                        self._idx = self._find(realTime());
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
        }
    };

    function vttTime(s) {
        var p = s.trim().split(':');
        return p.length === 3 ? +p[0]*3600 + +p[1]*60 + parseFloat(p[2]) : +p[0]*60 + parseFloat(p[1]);
    }
    function parseVTT(text) {
        var cues = [], blocks = text.replace(/\\r\\n|\\r/g,'\\n').split(/\\n\\n+/);
        for (var b = 0; b < blocks.length; b++) {
            var lines = blocks[b].trim().split('\\n'), ti = -1;
            for (var l = 0; l < lines.length; l++) { if (lines[l].indexOf(' --> ') !== -1) { ti = l; break; } }
            if (ti < 0) continue;
            var parts = lines[ti].split(' --> ');
            var txt = lines.slice(ti+1).join('\\n').trim();
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
        if (S.confirmed === 'native' || useHLS) {
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
    });
    seekBar.addEventListener('mousedown',  function(e) { if (!S.duration) return; S.dragging = true; seekBar.classList.add('dragging'); seekToFraction(getFraction(e)); });
    seekBar.addEventListener('touchstart', function(e) { if (!S.duration) return; S.dragging = true; seekBar.classList.add('dragging'); seekToFraction(getFraction(e)); }, {passive:true});
    document.addEventListener('mousemove', function(e) { if (S.dragging) seekToFraction(getFraction(e)); });
    document.addEventListener('touchmove', function(e) { if (S.dragging) seekToFraction(getFraction(e)); }, {passive:true});
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
                S.audioIdx = parseInt(sel.value); S.confirmed = S.step = 'transcode';
                plog('TRACK', 'audio changed → ' + S.audioIdx);
                hint.textContent = 'Changement de piste...'; hint.className = 'player-hint transcoding';
                saveCfg(); startStream(realTime());
            });
            trackBar.append(lbl, sel);
        }
        if (d.videoHeight > 0) {
            S.videoHeight = d.videoHeight;
            var qs = [480, 576, 720, 1080].filter(function(q) { return q <= S.videoHeight; });
            if (qs.length) {
                if (!savedCfg || savedCfg.quality <= 0 || qs.indexOf(savedCfg.quality) === -1)
                    S.quality = qs.indexOf(720) !== -1 ? 720 : qs[qs.length - 1];
                hasControls = true;
                var lbl3 = document.createElement('label'); lbl3.textContent = 'Qualit\u00E9 :';
                var sel3 = document.createElement('select'); sel3.className = 'track-select';
                qs.forEach(function(q) { var o = document.createElement('option'); o.value = q; o.textContent = q + 'p'; if (q === S.quality) o.selected = true; sel3.appendChild(o); });
                sel3.addEventListener('change', function() {
                    S.quality = parseInt(sel3.value); S.confirmed = 'transcode';
                    plog('TRACK', 'quality changed → ' + S.quality + 'p');
                    hint.textContent = 'Transcodage ' + S.quality + 'p...'; hint.className = 'player-hint transcoding';
                    saveCfg(); startStream(realTime());
                });
                trackBar.append(lbl3, sel3);
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
            if (savedSub !== '-1' && parseInt(savedSub) < d.subtitles.length) {
                selSub.value = savedSub;
                Subs.load(parseInt(savedSub));
            }
            selSub.addEventListener('change', function() {
                var idx = parseInt(selSub.value);
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
        // Picture-in-Picture
        if (pipBtn && document.pictureInPictureEnabled) {
            pipBtn.style.display = '';
            pipBtn.addEventListener('click', function() {
                if (document.pictureInPictureElement) document.exitPictureInPicture().catch(function(){});
                else player.requestPictureInPicture().catch(function(){});
            });
        }
        // Volume
        var savedVol = parseFloat(lsGet('player_volume', '1'));
        player.volume = isNaN(savedVol) ? 1 : Math.max(0, Math.min(1, savedVol));
        player.muted  = lsGet('player_muted', 'false') === 'true';
        updateVolUI();
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
        // Molette : volume
        playerCard.addEventListener('wheel', function(e) {
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
        kbStyle.textContent = '#kb-overlay{position:fixed;inset:0;z-index:200;background:rgba(0,0,0,.75);display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px)}#kb-overlay.hidden{display:none}#kb-card{background:#1a1d28;border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:1.5rem 2rem;min-width:270px}#kb-card h3{font-size:.8rem;font-weight:700;color:#8b90a0;text-transform:uppercase;letter-spacing:.07em;margin-bottom:.85rem}.kb-row{display:flex;align-items:center;justify-content:space-between;padding:.27rem 0;border-bottom:1px solid rgba(255,255,255,.055);font-size:.81rem;gap:1.2rem}.kb-row:last-child{border-bottom:none}.kb-key{font-family:monospace;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);border-radius:4px;padding:.13rem .48rem;font-size:.72rem;color:#e8eaf0;white-space:nowrap;flex-shrink:0}.kb-desc{color:#8b90a0}';
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
                if (document.pictureInPictureEnabled) {
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
