<?php
require_once __DIR__ . '/../auth.php';
if (PHP_SAPI !== 'cli' && !is_logged_in()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}
