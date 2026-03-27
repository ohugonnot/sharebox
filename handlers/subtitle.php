<?php
$trackIdx = max(0, (int)$_GET['subtitle']);
$fromSec = isset($_GET['from']) ? max(0, (float)$_GET['from']) : 0;
header('Content-Type: text/vtt; charset=utf-8');
header('Cache-Control: no-store');
$mtime = filemtime($resolvedPath);

// Cache hit → retour immédiat (sous-titres complets)
$cached = $db->prepare("SELECT vtt FROM subtitle_cache WHERE path = :p AND track = :t AND mtime = :m");
$cached->execute([':p' => $resolvedPath, ':t' => $trackIdx, ':m' => $mtime]);
if ($row = $cached->fetch()) {
    stream_log('SUBTITLE cache-hit | track=' . $trackIdx . ' | ' . basename($resolvedPath));
    echo $row['vtt'];
    exit;
}

// Extraction partielle rapide (à partir du temps demandé)
// Le JS demande d'abord les sous-titres à partir de la position courante
// pour un affichage immédiat, puis fetch les complets en background.
$seekArg = $fromSec > 10 ? ' -ss ' . escapeshellarg(sprintf('%.1f', $fromSec - 10)) : '';
$logFile = defined('STREAM_LOG') && STREAM_LOG ? STREAM_LOG : '/dev/null';

if ($fromSec > 10) {
    // Extraction partielle : rapide, pas cachée
    stream_log('SUBTITLE partial | track=' . $trackIdx . ' from=' . round($fromSec) . 's | ' . basename($resolvedPath));
    $cmd = 'timeout 30 ffmpeg' . $seekArg . ' -i ' . escapeshellarg($resolvedPath)
        . ' -map 0:s:' . $trackIdx . ' -f webvtt pipe:1 -loglevel error 2>>' . escapeshellarg($logFile);
    passthru($cmd);
    exit;
}

// Extraction complète : lente sur gros fichiers, cachée en SQLite
stream_log('SUBTITLE extract | track=' . $trackIdx . ' | ' . basename($resolvedPath));
ob_start();
$cmd = 'timeout 120 ffmpeg -i ' . escapeshellarg($resolvedPath)
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
