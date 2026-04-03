<?php
/**
 * browse.php — Protected file browser endpoint
 *
 * Serves the same content as download.php with token=home,
 * but sits behind Apache Digest auth (REMOTE_USER required).
 * Accessed via /dl/home → rewrite to /share/browse.php
 */

$_GET['token'] = 'home';
require __DIR__ . '/download.php';
