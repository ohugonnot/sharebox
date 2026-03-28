<?php
header('Content-Type: application/json; charset=utf-8');
$seekSec = max(0.0, (float)($_GET['keyframe'] ?? 0));
if ($seekSec <= 0.0) { echo json_encode(['pts' => 0.0]); exit; }
$probeFp = acquireProbeSlot();
if (!$probeFp) { echo json_encode(['pts' => $seekSec]); exit; }
$cmd = 'timeout ' . KEYFRAME_LOOKUP_TIMEOUT . ' ffprobe -v error -ss ' . escapeshellarg(sprintf('%.3f', $seekSec))
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
