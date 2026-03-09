<?php
/**
 * API JSON pour l'application Share
 * 3 actions : browse (lister fichiers), create (créer un lien), delete (supprimer un lien)
 * Protégée par htpasswd via nginx (accès admin uniquement)
 */

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        /**
         * BROWSE — Liste les fichiers et dossiers d'un répertoire
         * Paramètre : path (relatif à BASE_PATH)
         * Retourne : tableau d'entrées {name, type, size}
         */
        case 'browse':
            $relPath = $_GET['path'] ?? '';
            $fullPath = realpath(BASE_PATH . $relPath);

            // Sécurité anti path-traversal : le chemin résolu doit rester sous BASE_PATH
            $base = rtrim(BASE_PATH, '/');
            if ($fullPath === false || ($fullPath !== $base && strpos($fullPath, $base . '/') !== 0)) {
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

            $input = json_decode(file_get_contents('php://input'), true);
            $relPath = $input['path'] ?? '';
            $password = $input['password'] ?? '';
            $expiresHours = $input['expires'] ?? null;

            $fullPath = realpath(BASE_PATH . $relPath);

            // Sécurité anti path-traversal
            $base = rtrim(BASE_PATH, '/');
            if ($fullPath === false || ($fullPath !== $base && strpos($fullPath, $base . '/') !== 0)) {
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
            $stmt = $db->prepare("
                INSERT INTO links (token, path, type, name, password_hash, password_plain, expires_at)
                VALUES (:token, :path, :type, :name, :password_hash, :password_plain, :expires_at)
            ");
            $stmt->execute([
                ':token' => $token,
                ':path' => $fullPath,
                ':type' => $type,
                ':name' => $name,
                ':password_hash' => $passwordHash,
                ':password_plain' => !empty($password) ? $password : null,
                ':expires_at' => $expiresAt,
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

            $input = json_decode(file_get_contents('php://input'), true);
            $id = (int)($input['id'] ?? 0);

            if ($id <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'ID invalide']);
                exit;
            }

            $db = get_db();
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

            $input = json_decode(file_get_contents('php://input'), true);
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
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $fullUrl = $proto . '://' . $host . '/dl/' . $link['token'];

            // Construire le corps du mail
            $body = "Bonjour,\n\n";
            $body .= "Un fichier a été partagé avec vous :\n\n";
            $body .= "Nom : " . $link['name'] . "\n";
            $body .= "Lien : " . $fullUrl . "\n";
            if ($link['password_plain']) {
                $body .= "Mot de passe : " . $link['password_plain'] . "\n";
            }
            if ($link['expires_at']) {
                $body .= "Expire le : " . date('d/m/Y à H:i', strtotime($link['expires_at'])) . "\n";
            }
            $body .= "\nBonne réception !";

            // Envoyer le mail via la fonction PHP mail()
            $subject = "Partage : " . $link['name'];
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

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Action inconnue']);
    }

} catch (Exception $e) {
    error_log('Share app error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur interne']);
}

/**
 * Génère un slug lisible depuis un nom de fichier/dossier
 * Ex: "Batman.Begins.2005.MULTI.2160p.mkv" → "batman-begins-2005-x7k2"
 */
function generate_slug(string $name, PDO $db): string {
    // Retirer l'extension
    $slug = pathinfo($name, PATHINFO_FILENAME);

    // Translittérer les accents (é→e, ü→u, etc.)
    $slug = @iconv('UTF-8', 'ASCII//TRANSLIT', $slug);
    if (!$slug) $slug = pathinfo($name, PATHINFO_FILENAME);
    $slug = strtolower($slug);

    // Remplacer séparateurs courants par des tirets
    $slug = preg_replace('/[\s._()[\]{}]+/', '-', $slug);

    // Couper au premier tag technique (tout ce qui suit est du bruit)
    $slug = preg_replace('/-(multi|vff|vfq|truefrench|french|english|vostfr|subfrench|bluray|blu-ray|bdrip|brrip|webrip|web-?dl|hdtv|dvdrip|hdrip|x264|x265|h264|h265|hevc|avc|10bit|remux|2160p|1080p|720p|480p|uhd|4k|hdr|hdr10|dts|truehd|atmos|aac|ac3|flac|ddp?\d|proper|repack|internal|extended|unrated|directors?-?cut|complete|s\d{2}e?\d{0,2}).*/i', '', $slug);

    // Ne garder que lettres, chiffres, tirets
    $slug = preg_replace('/[^a-z0-9-]/', '', $slug);

    // Nettoyer les tirets multiples et aux extrémités
    $slug = preg_replace('/-{2,}/', '-', $slug);
    $slug = trim($slug, '-');

    // Tronquer à 40 chars max
    if (strlen($slug) > 40) {
        $slug = substr($slug, 0, 40);
        $slug = rtrim($slug, '-');
    }

    // Fallback si slug vide
    if ($slug === '') $slug = 'partage';

    // Ajouter suffixe random (4 chars alphanum)
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    do {
        $suffix = '';
        for ($i = 0; $i < 4; $i++) $suffix .= $chars[random_int(0, 35)];
        $candidate = $slug . '-' . $suffix;
        $check = $db->prepare("SELECT COUNT(*) FROM links WHERE token = :t");
        $check->execute([':t' => $candidate]);
    } while ($check->fetchColumn() > 0);

    return $candidate;
}
