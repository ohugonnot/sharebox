<?php
// php -S router: maps /share/* to the worktree and /dl/<token> to download.php, like Apache does.
require __DIR__ . '/reader-audio-config.php';

$root = realpath(__DIR__ . '/../..');
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$_SERVER['REMOTE_USER'] = 'e2e';

if (preg_match('#^/dl/([a-z0-9][a-z0-9-]{1,50})$#', $path, $m)) {
    $_GET['token'] = $m[1];
    chdir($root);
    require $root . '/download.php';
    return true;
}

if (str_starts_with($path, '/share/')) {
    $rel = substr($path, strlen('/share/'));
    $file = realpath($root . '/' . $rel);
    $denied = $file === false || !str_starts_with($file, $root . '/')
        || preg_match('#^(data|tests|vendor|node_modules|\.git)/#', substr($file, strlen($root) + 1))
        || basename($file) === 'config.php';
    if ($denied || !is_file($file)) {
        http_response_code(404);
        echo 'not found';
        return true;
    }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext === 'php') {
        chdir(dirname($file));
        $_SERVER['SCRIPT_NAME'] = $path;
        require $file;
        return true;
    }
    $types = [
        'js' => 'text/javascript', 'mjs' => 'text/javascript', 'css' => 'text/css',
        'svg' => 'image/svg+xml', 'json' => 'application/json', 'wasm' => 'application/wasm',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'woff2' => 'font/woff2', 'woff' => 'font/woff',
        'bcmap' => 'application/octet-stream', 'ftl' => 'text/plain', 'map' => 'application/json',
    ];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    return true;
}

http_response_code(404);
echo 'not found';
return true;
