<?php
$mime = get_stream_mime(strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION)));
if ($mime) {
    stream_log('NATIVE stream | ' . basename($resolvedPath) . ' | mime=' . $mime . ' size=' . format_taille(filesize($resolvedPath)));
    $encodedPath = XACCEL_PREFIX . str_replace('%2F', '/', rawurlencode($resolvedPath));
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline');
    header('X-Accel-Redirect: ' . $encodedPath);
    exit;
}
