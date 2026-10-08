<?php
// Creates the test DB and the share links. Usage: SB_E2E_DIR=<dir> php reader-audio-seed.php
require __DIR__ . '/reader-audio-config.php';
require __DIR__ . '/../../db.php';

$db = get_db();
$ins = $db->prepare('INSERT INTO links (token, path, type, name, password_hash) VALUES (?, ?, ?, ?, ?)');
$ins->execute(['e2e-root', BASE_PATH, 'dir', 'E2E', null]);
$ins->execute(['e2e-pwd', BASE_PATH . 'livres', 'dir', 'Prive', password_hash('secret-e2e', PASSWORD_BCRYPT, ['cost' => 4])]);
