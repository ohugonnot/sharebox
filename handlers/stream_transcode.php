<?php
$mime = get_stream_mime(strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION)));
if ($mime && str_starts_with($mime, 'video/')) {
    set_time_limit(0);
    $quality = isset($_GET['quality']) ? (int)$_GET['quality'] : 720;
    $quality = validateQuality($quality);
    $burnSub = isset($_GET['burnSub']) ? max(0, (int)$_GET['burnSub']) : -1;
    header('Content-Type: video/mp4');
    header('Content-Disposition: inline');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-cache');
    $logFile = defined('STREAM_LOG') && STREAM_LOG ? STREAM_LOG : '/dev/null';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '-';
    $isSafari = str_contains($ua, 'Safari') && !str_contains($ua, 'Chrome');
    // Content-Length estimé : Safari coupe la connexion sans ça (pas de progressive download)
    // Chrome/Firefox n'en ont pas besoin et un CL trop grand cause un stall en fin de stream
    // (le navigateur attend des octets qui ne viendront jamais après la fin du transcode)
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
    } catch (PDOException $e) { /* probe cache miss — non-blocking, transcode proceeds */ }
    $remainingDuration = max(0, $probeDuration - $startSec);
    $estimatedCL = $remainingDuration > 0 ? (int)($estimatedBps * $remainingDuration / 8 * 1.2) : 0;
    if ($estimatedCL > 0 && $isSafari) {
        header('Content-Length: ' . $estimatedCL);
    }
    stream_log('CL=' . ($isSafari && $estimatedCL ? $estimatedCL : 'skip') . ' dur=' . round($probeDuration) . 's rem=' . round($remainingDuration) . 's' . ($isSafari ? ' [Safari]' : '') . ' | UA=' . substr($ua, 0, 80));
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
