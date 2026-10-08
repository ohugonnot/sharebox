<?php
// ?listen=1 on a folder. Expects download.php's scope.
require_once __DIR__ . '/media_layout.php';

/** @var string $resolvedPath */
/** @var string $subPath */
/** @var string $basePath */
/** @var string $token */
/** @var array{name:string} $link */

[$abDir, $abSub] = media_page_folder($resolvedPath, $subPath, $basePath);
$ab = audiobook_page_data($abDir !== null ? media_page_names($abDir) : [], $token, $abSub);
$abTitle = $abSub !== '' ? basename($abSub) : $link['name'];
stream_log('AUDIOBOOK open | ' . ($abSub ?: '(root)') . ' | tracks=' . count($ab['tracks']));

// The big cover replaces the small one of the header bar.
media_page_open($abTitle, $ab['backUrl'], null, ['audiobook.css']);
if ($ab['cover'] !== null) {
    echo '<img class="ab-cover" src="' . media_html($ab['cover']) . '" alt="">';
}
// audiobook.js mounts itself on these two ids; the list is the no-JS fallback it replaces.
echo '<div id="asc-audiobook" data-token="' . media_html($ab['storageToken']) . '" data-title="' . media_html($abTitle) . '"><ul class="mp-fallback">';
foreach ($ab['tracks'] as $t) {
    echo '<li><a href="' . media_html($t['url']) . '">' . media_html($t['name']) . '</a></li>';
}
echo '</ul></div>';
echo '<script type="application/json" id="asc-audiobook-data">' . page_json($ab['tracks']) . '</script>';
media_page_close(['audiobook.js']);
