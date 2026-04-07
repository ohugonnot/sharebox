<?php
$mime = get_stream_mime(strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION)));
if (!$mime || !str_starts_with($mime, 'video/')) {
    http_response_code(415);
    echo json_encode(['error' => 'not_video']);
    exit;
}
set_time_limit(0);
ob_implicit_flush(true);
if (ob_get_level()) ob_end_flush();
$quality = isset($_GET['quality']) ? (int)$_GET['quality'] : 720;
$quality = validateQuality($quality);
$filterMode = isset($_GET['filter']) ? $_GET['filter'] : 'none';
$filterMode = validateFilterMode($filterMode);
// Auto-détection HDR si aucun filtre spécifié
if ($filterMode === 'none' && isHDRFile($db, $resolvedPath)) {
    $filterMode = 'hdr';
}
$burnSub = validateBurnSub(isset($_GET['burnSub']) ? (int)$_GET['burnSub'] : -1, getSubtitleCount($db, $resolvedPath));
header('Content-Type: video/mp4');
header('Content-Disposition: inline');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache');
header('Accept-Ranges: none');
$logFile = ffmpeg_log_path();
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '-';
$isSafari = str_contains($ua, 'Safari') && !str_contains($ua, 'Chrome');
stream_log('TRANSCODE | ' . ($isSafari ? '[Safari] ' : '') . 'UA=' . substr($ua, 0, 80));
$logLabel = $burnSub >= 0 ? 'TRANSCODE+SUB' : 'TRANSCODE';
stream_log($logLabel . ' start | quality=' . $quality . 'p filter=' . $filterMode . ' audio=' . $audioTrack . ($burnSub >= 0 ? ' burnSub=' . $burnSub : '') . ' start=' . $startSec . ' | ' . basename($resolvedPath));
$hasAudio = hasAudioTrack($db, $resolvedPath);
if ($hasAudio) {
    $fc = buildFilterGraph($quality, $audioTrack, $burnSub, $filterMode);
    $cmd = buildFfmpegInputArgs($resolvedPath, $seekArgBefore)
        . ' -filter_complex ' . $fc . ' -map "[v]" -map "[a]" -dn'
        . buildFfmpegCodecArgs(250, $filterMode === 'hdr', false) . buildFmp4MuxerArgs()
        . ' -f mp4 -y pipe:1 -loglevel error 2>>' . escapeshellarg($logFile);
} else {
    // Fichier sans piste audio — video-only transcode
    $scaleFilter = 'scale=-2:\'min(' . $quality . ',ih)\':flags=lanczos,format=yuv420p';
    $cmd = buildFfmpegInputArgs($resolvedPath, $seekArgBefore)
        . ' -vf ' . escapeshellarg($scaleFilter) . ' -an -dn'
        . ' -c:v libx264 -preset ' . FFMPEG_PRESET . ' -crf ' . FFMPEG_CRF . ' -threads ' . FFMPEG_THREADS
        . buildFmp4MuxerArgs()
        . ' -f mp4 -y pipe:1 -loglevel error 2>>' . escapeshellarg($logFile);
}
[$slotFp, $queued] = acquireStreamSlot();
if ($queued) { stream_log('TRANSCODE queued | ' . basename($resolvedPath)); header('X-Stream-Queued: 1'); }
$shellPid = null;
register_shutdown_function(function() use (&$slotFp, &$shellPid) {
    // Kill process group to terminate ffmpeg even behind shell wrapper
    if ($shellPid) { @posix_kill(-$shellPid, SIGTERM); @posix_kill($shellPid, SIGTERM); }
    releaseStreamSlot($slotFp);
});
warmFileCache($resolvedPath);
$proc = proc_open($cmd, [1 => ['pipe', 'w']], $pipes);
if (!is_resource($proc)) {
    stream_log('TRANSCODE proc_open failed | ' . basename($resolvedPath));
    exit;
}
if (true) {
    $shellPid = proc_get_status($proc)['pid'];
    stream_set_timeout($pipes[1], 60);
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 65536);
        if ($chunk === false || $chunk === '' || connection_aborted()) break;
        $meta = stream_get_meta_data($pipes[1]);
        if ($meta['timed_out']) { stream_log('TRANSCODE pipe timeout — aborting'); break; }
        echo $chunk;
    }
    fclose($pipes[1]);
    $status = proc_get_status($proc);
    if ($status['running']) proc_terminate($proc, 15);
    proc_close($proc);
}
$shellPid = null;
// Slot released by shutdown function
exit;
