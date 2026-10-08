<?php
// Shared frame of the reader and audiobook pages (included by download.php).

const MEDIA_PAGE_CSP = "default-src 'self'; script-src 'self'; worker-src 'self'; style-src 'self' 'unsafe-inline'; "
    . "img-src 'self' data: blob:; media-src 'self'; connect-src 'self'; font-src 'self'; "
    . "frame-src 'none'; object-src 'none'; base-uri 'none'";

function media_html(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function media_asset(string $file): string {
    return '/share/' . $file . '?v=' . (int)@filemtime(__DIR__ . '/../' . $file);
}

/**
 * @param string[] $css
 */
function media_page_open(string $title, string $backUrl, ?string $cover, array $css): void {
    header('Content-Security-Policy: ' . MEDIA_PAGE_CSP);
    header('Cache-Control: no-store');
    // The CSP has no Google Fonts: drop the @import, system fonts take over.
    $base = preg_replace('/^@import[^\n]*\n/m', '', css_public());
    $titleHtml = media_html($title);
    $links = '';
    foreach (array_merge(['reader-theme.css'], $css) as $f) {
        $links .= '<link rel="stylesheet" href="' . media_html(media_asset($f)) . '">';
    }
    $coverHtml = $cover !== null ? '<img class="mp-cover" src="' . media_html($cover) . '" alt="">' : '';
    $backHtml = media_html($backUrl);
    echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0c0e14">
    <link rel="icon" type="image/svg+xml" href="/share/favicon.svg">
    <title>{$titleHtml}</title>
    <style>{$base}
    .mp-page { position: relative; z-index: 1; min-height: 100dvh; padding: .75rem 1rem 2rem; }
    .mp-bar { display: flex; align-items: center; gap: .6rem; max-width: 960px; margin: 0 auto .9rem; }
    .mp-back { display: inline-flex; align-items: center; gap: .35rem; padding: .4rem .8rem; border: 1px solid var(--border); border-radius: var(--radius-sm); color: var(--text-secondary); font-size: .82rem; font-weight: 600; text-decoration: none; white-space: nowrap; }
    .mp-back:hover { color: var(--text-primary); border-color: rgba(255,255,255,.16); }
    .mp-cover { width: 44px; height: 44px; object-fit: cover; border-radius: var(--radius-sm); flex-shrink: 0; }
    .mp-title { flex: 1; min-width: 0; font-size: 1rem; font-weight: 600; overflow-wrap: anywhere; }
    .mp-fallback a { color: var(--accent); }
    .ab-cover { display: block; width: min(220px, 60vw); aspect-ratio: 1; object-fit: cover; border-radius: var(--radius-md); margin: 0 auto 1rem; }
    </style>
    {$links}
</head>
<body>
<div class="mp-page">
    <div class="mp-bar">
        <a class="mp-back" href="{$backHtml}"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg> Retour</a>
        {$coverHtml}
        <h1 class="mp-title">{$titleHtml}</h1>
    </div>

HTML;
}

/**
 * @param string[] $js
 */
function media_page_close(array $js): void {
    echo "</div>\n";
    foreach ($js as $f) {
        echo '<script src="' . media_html(media_asset($f)) . '"></script>' . "\n";
    }
    echo "</body>\n</html>\n";
}

/**
 * Folder to show: the one asked for, or the parent of an opened book; null when that parent is
 * not shared (a file link): the page must then show the shared file alone.
 * The sub-path is rebuilt from the real folder, so 'a/..' gets the same title and resume key as ''.
 *
 * @return array{0:?string, 1:string, 2:?string} [real path, sub-path in the share, opened book]
 */
function media_page_folder(string $resolvedPath, string $subPath, string $basePath): array {
    if (!is_file($resolvedPath)) return [$resolvedPath, media_rel_path($resolvedPath, $basePath), null];
    // A symlinked book: list the folder of the link, the one the URLs are built on.
    $dir = realpath(dirname($basePath . '/' . trim($subPath, '/')));
    if (is_path_within($dir, $basePath)) return [$dir, media_rel_path($dir, $basePath), basename($subPath)];
    $dir = dirname($resolvedPath);
    if (is_path_within($dir, $basePath)) return [$dir, media_rel_path($dir, $basePath), basename($resolvedPath)];
    return [null, '', basename($resolvedPath)];
}

function media_rel_path(string $realPath, string $basePath): string {
    foreach (array_unique([rtrim($basePath, '/'), (string)realpath($basePath)]) as $base) {
        if ($realPath === $base) return '';
        if ($base !== '' && str_starts_with($realPath, $base . '/')) return substr($realPath, strlen($base) + 1);
    }
    return '';
}

/**
 * Same entries as the listing's files: no dot names, no HIDDEN_DIRS, no folders (symlinked or not)
 *
 * @return string[]
 */
function media_page_names(string $dir): array {
    $hidden = defined('HIDDEN_DIRS') ? HIDDEN_DIRS : [];
    return array_values(array_filter(scandir($dir) ?: [],
        fn($n) => $n[0] !== '.' && !in_array($n, $hidden, true) && !is_dir($dir . '/' . $n)));
}
