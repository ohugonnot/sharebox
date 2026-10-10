<?php
// ?read=1 on a folder, or ?play=1 on a book (opened directly). Expects download.php's scope.
require_once __DIR__ . '/media_layout.php';

/** @var string $resolvedPath */
/** @var string $subPath */
/** @var string $basePath */
/** @var string $token */
/** @var array{name:string} $link */

[$rdDir, $rdSub, $rdBook] = media_page_folder($resolvedPath, $subPath, $basePath);
$rdFiles = [];
foreach ($rdDir !== null ? media_page_names($rdDir) : [(string)$rdBook] as $rdName) {
    $rdFull = $rdDir !== null ? $rdDir . '/' . $rdName : $resolvedPath;
    // Only books pay filesize/filemtime: the folder may hold hundreds of other files on a busy disk.
    if (reader_kind($rdName) === null) {
        $rdFiles[] = ['name' => $rdName, 'size' => 0, 'mtime' => 0];
    } elseif (is_file($rdFull)) {
        $rdFiles[] = ['name' => $rdName, 'size' => (int)filesize($rdFull), 'mtime' => (int)filemtime($rdFull)];
    }
}
$rd = reader_page_data($rdFiles, $token, $rdSub, $rdBook, READER_EPUB_ENABLED);
if ($rdDir === null && $rd['books']) {
    // A file link only answers on its own URL: no ?p=.
    $rd['books'][0]['url'] = '/dl/' . $token . '?inline=1';
    if (isset($rd['books'][0]['download'])) $rd['books'][0]['download'] = '/dl/' . $token;
}
$rdTitle = $rdSub !== '' ? basename($rdSub) : $link['name'];
stream_log('READER open | ' . ($rdSub ?: '(root)') . ' | books=' . count($rd['books']));

media_page_open($rdTitle, $rd['backUrl'], $rd['cover'], ['reader.css']);
echo '<div id="asc-reader" data-title="' . media_html($rdTitle) . '"><ul class="mp-fallback">';
foreach ($rd['books'] as $b) {
    echo '<li><a href="' . media_html($b['url']) . '">' . media_html($b['name']) . '</a> <small>' . format_taille($b['size']) . '</small></li>';
}
echo '</ul></div>';
// Not asc-reader-data: reader.js would mount itself without our libBase and open book.
echo '<script type="application/json" id="sb-reader-data">' . page_json([
    'books' => $rd['books'], 'open' => $rd['open'], 'libBase' => $rd['libBase'], 'storageToken' => $rd['storageToken'], 'backUrl' => $rd['backUrl'],
]) . '</script>';
media_page_close(['reader.js', 'reader-boot.js']);
