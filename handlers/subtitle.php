<?php
$trackIdx = max(0, (int)$_GET['subtitle']);
header('Content-Type: text/vtt; charset=utf-8');
header('Cache-Control: no-store');
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
$vtt = ob_get_clean();
echo $vtt;
if ($vtt) {
    try {
        $db->prepare("INSERT OR REPLACE INTO subtitle_cache (path, track, mtime, vtt) VALUES (:p, :t, :m, :v)")
           ->execute([':p' => $resolvedPath, ':t' => $trackIdx, ':m' => $mtime, ':v' => $vtt]);
    } catch (PDOException $e) { /* lock — recalculé au prochain appel */ }
}
exit;
