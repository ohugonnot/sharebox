<?php
/**
 * browse.php — Protected file browser endpoint
 *
 * Serves the same content as download.php with token=home,
 * but sits behind Apache Digest auth (REMOTE_USER required).
 * Accessed via /dl/home → rewrite to /share/browse.php
 */

// Ce sas est le SEUL chemin autorise vers les liens internes (path hors
// /home/<user>/). Apache protege browse.php par Digest, mais download.php est
// public : sans ce drapeau, download.php?token=home servait tout /home a
// n'importe qui sur Internet.
define('SHAREBOX_BROWSE_ENTRY', true);

$_GET['token'] = 'home';
require __DIR__ . '/download.php';
