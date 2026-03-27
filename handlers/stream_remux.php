<?php
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
