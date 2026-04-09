<?php
$trackIdx = max(0, (int)$_GET['subtitle']);
// Borne haute : évite de lancer ffmpeg sur des tracks inexistantes (anti-DoS)
if ($trackIdx > SUBTITLE_TRACK_MAX) { http_response_code(400); exit; }
header('Content-Type: text/vtt; charset=utf-8');
header('Cache-Control: no-store');
$mtime = filemtime($resolvedPath);

// Cache hit → retour immédiat
$cached = $db->prepare("SELECT vtt FROM subtitle_cache WHERE path = :p AND track = :t AND mtime = :m");
$cached->execute([':p' => $resolvedPath, ':t' => $trackIdx, ':m' => $mtime]);
if ($row = $cached->fetch()) {
    stream_log('SUBTITLE cache-hit | track=' . $trackIdx . ' | ' . basename($resolvedPath));
    echo $row['vtt'];
    exit;
}

// Pas de cache — extraction complète (le pré-cache background du probe n'a pas encore fini)
// Le JS affichera "Chargement sous-titres..." en attendant
stream_log('SUBTITLE extract | track=' . $trackIdx . ' | ' . basename($resolvedPath));
$logFile = ffmpeg_log_path();
ob_start();
$cmd = 'timeout ' . SUBTITLE_EXTRACT_TIMEOUT . ' ffmpeg -i ' . escapeshellarg($resolvedPath)
    . ' -map 0:s:' . $trackIdx . ' -f webvtt pipe:1 -loglevel error 2>>' . escapeshellarg($logFile);
passthru($cmd);
$vtt = ob_get_clean();
echo $vtt;
// Ne cacher que du WebVTT valide (évite de servir du contenu corrompu indéfiniment)
if ($vtt && str_starts_with(trim($vtt), 'WEBVTT')) {
    try {
        $db->prepare("INSERT OR REPLACE INTO subtitle_cache (path, track, mtime, vtt) VALUES (:p, :t, :m, :v)")
           ->execute([':p' => $resolvedPath, ':t' => $trackIdx, ':m' => $mtime, ':v' => $vtt]);
    } catch (PDOException $e) { /* lock — recalculé au prochain appel */ }
}
exit;
