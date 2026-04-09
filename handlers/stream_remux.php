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
header('Content-Type: video/mp4');
header('Content-Disposition: inline');
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache');
header('Accept-Ranges: none');
$logFile = ffmpeg_log_path();
$hasAudio = hasAudioTrack($db, $resolvedPath);
stream_log('REMUX start | audio=' . $audioTrack . ' hasAudio=' . ($hasAudio ? 'yes' : 'no') . ' start=' . $startSec . ' | ' . basename($resolvedPath));
$audioArgs = $hasAudio
    ? ($audioMap . ' -c:a aac -ac 2 -b:a 192k -af "aresample=async=2000:first_pts=0"')
    : ' -an';
$cmd = buildFfmpegInputArgs($resolvedPath, $seekArgBefore)
    . $audioArgs . ' -dn -c:v copy'
    . buildFmp4MuxerArgs()
    . ' -f mp4 -y pipe:1 -loglevel error 2>>' . escapeshellarg($logFile);
[$slotFp, $queued] = acquireStreamSlot();
if ($queued) { stream_log('REMUX queued | ' . basename($resolvedPath)); header('X-Stream-Queued: 1'); }
$shellPid = null;
register_shutdown_function(function() use (&$slotFp, &$shellPid) {
    // Kill process group to terminate ffmpeg even behind shell wrapper
    if ($shellPid) { @posix_kill(-$shellPid, SIGTERM); @posix_kill($shellPid, SIGTERM); }
    releaseStreamSlot($slotFp);
});
warmFileCache($resolvedPath);
$proc = proc_open($cmd, [1 => ['pipe', 'w']], $pipes);
if (!is_resource($proc)) {
    stream_log('REMUX proc_open failed | ' . basename($resolvedPath));
    exit;
}
$shellPid = proc_get_status($proc)['pid'];
stream_set_timeout($pipes[1], 60);
while (!feof($pipes[1])) {
    $chunk = fread($pipes[1], 65536);
    if ($chunk === false || $chunk === '' || connection_aborted()) break;
    $meta = stream_get_meta_data($pipes[1]);
    if ($meta['timed_out']) { stream_log('REMUX pipe timeout — aborting'); break; }
    echo $chunk;
}
fclose($pipes[1]);
$status = proc_get_status($proc);
if ($status['running']) proc_terminate($proc, 15);
proc_close($proc);
$shellPid = null;
// Slot released by shutdown function
exit;
