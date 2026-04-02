<?php
$mime = get_stream_mime(strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION)));
if ($mime && str_starts_with($mime, 'video/')) {
    $quality = isset($_GET['quality']) ? (int)$_GET['quality'] : 720;
    $quality = validateQuality($quality);
    $filterMode = isset($_GET['filter']) ? $_GET['filter'] : 'none';
    $filterMode = validateFilterMode($filterMode);
    // Auto-détection HDR si aucun filtre spécifié
    if ($filterMode === 'none' && isHDRFile($db, $resolvedPath)) {
        $filterMode = 'hdr';
    }
    $burnSub = isset($_GET['burnSub']) ? max(0, (int)$_GET['burnSub']) : -1;
    $logFile = ffmpeg_log_path();

    // Dossier cache unique par fichier+qualité+audio+burnSub+startSec+filterMode
    // Hors de /tmp pour éviter la race condition avec systemd-tmpfiles qui supprime pendant ffmpeg
    $hlsKey = md5($resolvedPath . '|' . $quality . '|' . $audioTrack . '|' . $burnSub . '|' . $startSec . '|' . $filterMode);
    $hlsDir = (defined('STREAM_LOG') && STREAM_LOG ? dirname(STREAM_LOG) : sys_get_temp_dir()) . '/hls_cache/hls_' . $hlsKey;
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
            array_map('unlink', glob($hlsDir . '/*'));
            $needStart = true;
        }
    }

    if ($needStart) {
        if (!is_dir($hlsDir)) mkdir($hlsDir, 0755, true);
        // Supprimer les anciens segments si relance
        array_map('unlink', glob($hlsDir . '/seg*.ts'));
        if (file_exists($m3u8)) unlink($m3u8);

        $fc = buildFilterGraph($quality, $audioTrack, $burnSub, $filterMode);

        [$slotFp, $queued] = acquireStreamSlot();
        if ($queued) stream_log('HLS queued | ' . basename($resolvedPath));
        stream_log('HLS start | quality=' . $quality . 'p audio=' . $audioTrack . ' start=' . $startSec . ' | ' . basename($resolvedPath));

        warmFileCache($resolvedPath);

        $ffmpegCmd = buildFfmpegInputArgs($resolvedPath, $seekArgBefore)
            . ' -filter_complex ' . $fc . ' -map "[v]" -map "[a]" -dn'
            . buildFfmpegCodecArgs(50, $filterMode === 'hdr')
            . ' -f hls -hls_time 4 -hls_list_size 0 -hls_playlist_type event'
            . ' -hls_segment_filename ' . escapeshellarg($hlsDir . '/seg%d.ts')
            . ' -hls_flags append_list'
            . ' ' . escapeshellarg($m3u8)
            . ' -loglevel error 2>>' . escapeshellarg($logFile);

        // Lancer ffmpeg en arrière-plan et stocker son PID
        $pid = trim(shell_exec($ffmpegCmd . ' > /dev/null & echo $!'));
        file_put_contents($pidFile, $pid);
        touch($hlsDir . '/.active');

        // Cleanup background : tient le flock tant que ffmpeg tourne, puis attend 2min d'inactivité
        $slotPath = $slotFp ? stream_get_meta_data($slotFp)['uri'] : '';
        $activeFile = escapeshellarg($hlsDir . '/.active');
        // flock dans le shell hérite le lock — maintient le slot occupé pendant ffmpeg
        // Avant rm -rf, vérifier qu'un nouveau ffmpeg n'a pas pris le relais
        // (le stall watchdog peut relancer un transcode avec les mêmes params → même hlsDir)
        // Watchdog : tue ffmpeg après 5 min d'inactivité (client déconnecté), sinon attend mort naturelle
        $cleanupBody = 'while kill -0 ' . (int)$pid . ' 2>/dev/null; do '
            . 'inactive=$(($(date +%s) - $(stat -c %Y ' . $activeFile . ' 2>/dev/null || echo 0))); '
            . 'if [ $inactive -gt 300 ]; then kill -9 ' . (int)$pid . ' 2>/dev/null; break; fi; '
            . 'sleep 30; done; '
            . 'while [ $(($(date +%s) - $(stat -c %Y ' . $activeFile . ' 2>/dev/null || echo 0))) -lt 120 ]; do sleep 10; done; '
            . 'if [ -f ' . escapeshellarg($pidFile) . ' ] && [ "$(cat ' . escapeshellarg($pidFile) . ')" != "' . (int)$pid . '" ]; then exit 0; fi; '
            . 'rm -rf ' . escapeshellarg($hlsDir);
        $cleanupCmd = $slotPath
            ? '(flock -x 9; ' . $cleanupBody . ') 9>' . escapeshellarg($slotPath) . ' >/dev/null 2>&1 &'
            : '(' . $cleanupBody . ') >/dev/null 2>&1 &';
        // Fermer le FD PHP avant exec — le shell cleanup reprend le lock via flock 9>
        if ($slotFp) fclose($slotFp);
        exec($cleanupCmd);
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
