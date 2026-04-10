<?php
$mime = get_stream_mime(strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION)));
if ($mime) {
    stream_log('NATIVE stream | ' . basename($resolvedPath) . ' | mime=' . $mime . ' size=' . format_taille(filesize($resolvedPath)));
    serve_file($resolvedPath, $mime, 'inline');
}
