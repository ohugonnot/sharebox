<?php
/**
 * Serve locally saved web poster images from data/posters/
 * URL: /share/poster.php?f=abc123def456.jpg
 */
$file = basename($_GET['f'] ?? '');
if (!$file || !preg_match('/^[a-f0-9]+\.jpg$/', $file)) {
    http_response_code(404);
    exit;
}
$path = __DIR__ . '/data/posters/' . $file;
if (!file_exists($path)) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Length: ' . filesize($path));
readfile($path);
