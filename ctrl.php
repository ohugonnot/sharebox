<?php
/**
 * API JSON pour l'application Share
 * 3 actions : browse (lister fichiers), create (créer un lien), delete (supprimer un lien)
 * Protégée par authentification PHP
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

// Auth check for API (skip in CLI/test mode)
if (PHP_SAPI !== 'cli' && !is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

$action = $_GET['cmd'] ?? '';

// Validation CSRF pour les requêtes POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start();
    $input = json_decode(file_get_contents('php://input'), true);
    $csrfToken = $input['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Token CSRF invalide']);
        exit;
    }
}

try {
    switch ($action) {

        /**
         * BROWSE — Liste les fichiers et dossiers d'un répertoire
         * Paramètre : path (relatif à BASE_PATH)
         * Retourne : tableau d'entrées {name, type, size}
         */
        case 'browse':
            $relPath = $_GET['path'] ?? '';

            // Private users are restricted to their own subfolder
            $browseBase = BASE_PATH;
            if (($_SESSION['sharebox_role'] ?? '') !== 'admin'
                && (int)($_SESSION['sharebox_private'] ?? 0) === 1) {
                $browseBase = BASE_PATH . ($_SESSION['sharebox_user'] ?? '') . '/';
            }

            $fullPath = realpath($browseBase . $relPath);

            if (!is_path_within($fullPath, $browseBase)) {
                http_response_code(403);
                echo json_encode(['error' => 'Chemin interdit']);
                exit;
            }

            if (!is_dir($fullPath)) {
                http_response_code(400);
                echo json_encode(['error' => 'Pas un répertoire']);
                exit;
            }

            $entries = [];
            $items = scandir($fullPath);
            foreach ($items as $item) {
                // On ignore les entrées spéciales et les fichiers cachés (commençant par un point)
                if ($item[0] === '.') continue;

                $itemPath = $fullPath . '/' . $item;
                $isDir = is_dir($itemPath);

                $entries[] = [
                    'name' => $item,
                    'type' => $isDir ? 'folder' : 'file',
                    'size' => $isDir ? null : filesize($itemPath),
                ];
            }

            // Tri : dossiers d'abord, puis par nom
            usort($entries, function($a, $b) {
                if ($a['type'] !== $b['type']) return $a['type'] === 'folder' ? -1 : 1;
                return strnatcasecmp($a['name'], $b['name']);
            });

            echo json_encode(['path' => $relPath, 'entries' => $entries]);
            break;

        /**
         * CREATE — Crée un nouveau lien de partage
         * Paramètres POST : path, password (optionnel), expires (optionnel, en heures)
         */
        case 'create':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Méthode POST requise']);
                exit;
            }

            $relPath = $input['path'] ?? '';
            $password = $input['password'] ?? '';
            $expiresHours = $input['expires'] ?? null;

            $fullPath = realpath(BASE_PATH . $relPath);

            if (!is_path_within($fullPath, BASE_PATH)) {
                http_response_code(403);
                echo json_encode(['error' => 'Chemin interdit']);
                exit;
            }

            // Déterminer le type (fichier ou dossier)
            $type = is_dir($fullPath) ? 'folder' : 'file';
            $name = basename($fullPath);

            // Hacher le mot de passe si fourni
            $passwordHash = !empty($password) ? password_hash($password, PASSWORD_BCRYPT) : null;

            // Calculer la date d'expiration si demandée
            $expiresAt = null;
            if ($expiresHours !== null && $expiresHours > 0) {
                $expiresAt = date('c', time() + (int)$expiresHours * 3600);
            }

            $db = get_db();

            // Générer un slug lisible à partir du nom + suffixe random
            $token = generate_slug($name, $db);
            $createdBy = $_SESSION['sharebox_user'] ?? null;
            $stmt = $db->prepare("
                INSERT INTO links (token, path, type, name, password_hash, expires_at, created_by)
                VALUES (:token, :path, :type, :name, :password_hash, :expires_at, :created_by)
            ");
            $stmt->execute([
                ':token'         => $token,
                ':path'          => $fullPath,
                ':type'          => $type,
                ':name'          => $name,
                ':password_hash' => $passwordHash,
                ':expires_at'    => $expiresAt,
                ':created_by'    => $createdBy,
            ]);

            echo json_encode([
                'success' => true,
                'token' => $token,
                'url' => DL_BASE_URL . $token,
                'name' => $name,
                'type' => $type,
                'expires_at' => $expiresAt,
            ]);
            break;

        /**
         * DELETE — Supprime (révoque) un lien de partage
         * Paramètre POST : id (identifiant du lien)
         */
        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Méthode POST requise']);
                exit;
            }

            $id = (int)($input['id'] ?? 0);

            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'ID invalide']);
                exit;
            }

            $db = get_db();
            if (($_SESSION['sharebox_role'] ?? '') !== 'admin') {
                $owner = $db->prepare("SELECT created_by FROM links WHERE id = ?");
                $owner->execute([$id]);
                $row = $owner->fetch();
                if ($row && $row['created_by'] !== null && $row['created_by'] !== ($_SESSION['sharebox_user'] ?? '')) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Vous ne pouvez supprimer que vos propres liens']);
                    exit;
                }
            }
            $stmt = $db->prepare("DELETE FROM links WHERE id = :id");
            $stmt->execute([':id' => $id]);

            echo json_encode(['success' => true]);
            break;

        /**
         * SEND_EMAIL — Envoie un lien de partage par email
         * Paramètres POST : id (identifiant du lien), email (adresse destinataire)
         */
        case 'send_email':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Méthode POST requise']);
                exit;
            }

            $id = (int)($input['id'] ?? 0);
            $email = trim($input['email'] ?? '');

            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'ID invalide']);
                exit;
            }

            // Validation basique de l'email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['error' => 'Adresse email invalide']);
                exit;
            }

            $db = get_db();
            $stmt = $db->prepare("SELECT * FROM links WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $link = $stmt->fetch();

            if (!$link) {
                http_response_code(404);
                echo json_encode(['error' => 'Lien introuvable']);
                exit;
            }

            // Construire l'URL complète
            $host = preg_replace('/[^a-z0-9.\-:]/i', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $fullUrl = $proto . '://' . $host . '/dl/' . $link['token'];

            // Construire le corps du mail
            $body = "Bonjour,\n\n";
            $body .= "Un fichier a été partagé avec vous :\n\n";
            $body .= "Nom : " . $link['name'] . "\n";
            $body .= "Lien : " . $fullUrl . "\n";
            if ($link['password_hash']) {
                $body .= "Ce lien est protégé par un mot de passe.\n";
            }
            if ($link['expires_at']) {
                $body .= "Expire le : " . date('d/m/Y à H:i', strtotime($link['expires_at'])) . "\n";
            }
            $body .= "\nBonne réception !";

            // Envoyer le mail via la fonction PHP mail()
            $subject = str_replace(["\r", "\n"], '', "Partage : " . $link['name']);
            $headers = "From: Share <noreply@" . $host . ">\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

            $sent = mail($email, $subject, $body, $headers);

            if ($sent) {
                echo json_encode(['success' => true]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Échec de l\'envoi du mail']);
            }
            break;

        case 'change_password':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Méthode POST requise']);
                exit;
            }
            $username = $_SESSION['sharebox_user'] ?? '';
            if (empty($username)) {
                http_response_code(401);
                echo json_encode(['error' => 'Non authentifié']);
                exit;
            }
            echo json_encode(change_password_for_user(
                get_db(),
                $username,
                $input['current_password'] ?? '',
                $input['new_password'] ?? '',
                $input['confirm_password'] ?? ''
            ));
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Action inconnue']);
    }

} catch (Exception $e) {
    error_log('Share app error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur interne']);
}

