<?php
/**
 * Router for PHP built-in server (testing only).
 * Strips /share/ prefix so assets and APIs resolve correctly.
 */
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

// Strip /share/ prefix
if (str_starts_with($path, '/share/')) {
    $path = '/' . substr($path, 7);
}

// Map to filesystem
$file = __DIR__ . $path;

// If it's a real file, serve it (CSS, JS, images)
if ($path !== '/' && is_file($file)) {
    // Let PHP serve static files with correct MIME types
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    $mimes = [
        'css' => 'text/css',
        'js'  => 'application/javascript',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
    ];
    if (isset($mimes[$ext])) {
        header('Content-Type: ' . $mimes[$ext]);
        readfile($file);
        return true;
    }
    return false; // Let PHP built-in server handle it
}

// PHP files
if (str_ends_with($path, '.php') && is_file($file)) {
    $_SERVER['SCRIPT_NAME'] = $path;
    require $file;
    return true;
}

// Default to index.php
if ($path === '/') {
    require __DIR__ . '/index.php';
    return true;
}

return false;
