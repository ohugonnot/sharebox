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
$input = [];

// Validation CSRF pour les requêtes POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start();
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrfToken = $input['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Token CSRF invalide']);
        exit;
    }
}
/**
 * CPU% moyen depuis le lancement du process (single-pass, non-bloquant).
 * Retourne null si le PID est invalide ou illisible.
 */
function get_pid_cpu(int $pid): ?float
{
    $stat = @file_get_contents("/proc/$pid/stat");
    $uptime_raw = @file_get_contents('/proc/uptime');
    if ($stat === false || $uptime_raw === false) return null;

    $fields  = explode(' ', $stat);
    $utime   = (float)($fields[13] ?? 0); // jiffies user
    $stime   = (float)($fields[14] ?? 0); // jiffies kernel
    $start   = (float)($fields[21] ?? 0); // starttime en jiffies depuis boot

    static $hz = null;
    if ($hz === null) $hz = (int)shell_exec('getconf CLK_TCK') ?: 100;

    $uptime     = (float)explode(' ', $uptime_raw)[0];
    $age_sec    = $uptime - ($start / $hz);
    if ($age_sec <= 0) return null;

    return round((($utime + $stime) / $hz / $age_sec) * 100, 1);
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

            // Limite optionnelle de téléchargements
            $maxDownloads = null;
            if (isset($input['max_downloads']) && (int)$input['max_downloads'] > 0) {
                $maxDownloads = (int)$input['max_downloads'];
            }

            $db = get_db();

            // Générer un slug lisible à partir du nom + suffixe random
            $token = generate_slug($name, $db);
            $createdBy = $_SESSION['sharebox_user'] ?? null;
            $stmt = $db->prepare("
                INSERT INTO links (token, path, type, name, password_hash, expires_at, created_by, max_downloads)
                VALUES (:token, :path, :type, :name, :password_hash, :expires_at, :created_by, :max_downloads)
            ");
            $stmt->execute([
                ':token'         => $token,
                ':path'          => $fullPath,
                ':type'          => $type,
                ':name'          => $name,
                ':password_hash' => $passwordHash,
                ':expires_at'    => $expiresAt,
                ':created_by'    => $createdBy,
                ':max_downloads' => $maxDownloads,
            ]);

            log_activity('link_create', $createdBy, $_SERVER['REMOTE_ADDR'] ?? null, $name . ' [' . $token . ']');

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
            $fetchLink = $db->prepare("SELECT name, created_by FROM links WHERE id = ?");
            $fetchLink->execute([$id]);
            $linkRow = $fetchLink->fetch();

            if (($_SESSION['sharebox_role'] ?? '') !== 'admin') {
                if ($linkRow && $linkRow['created_by'] !== null && $linkRow['created_by'] !== ($_SESSION['sharebox_user'] ?? '')) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Vous ne pouvez supprimer que vos propres liens']);
                    exit;
                }
            }
            $stmt = $db->prepare("DELETE FROM links WHERE id = :id");
            $stmt->execute([':id' => $id]);

            log_activity('link_delete', $_SESSION['sharebox_user'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null, $linkRow ? $linkRow['name'] : "id:$id");

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

        case 'mark_watched':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Méthode POST requise']);
                exit;
            }
            // POST {path, duration, csrf_token}
            // Disponible pour tout user connecté (non admin-only)
            $path = $input['path'] ?? '';
            $duration = isset($input['duration']) ? (int)$input['duration'] : null;
            $currentUser = $_SESSION['sharebox_user'] ?? '';
            if (!$currentUser || !$path) {
                http_response_code(400);
                echo json_encode(['error' => 'missing params']);
                break;
            }
            if (!file_exists($path)) {
                http_response_code(404);
                echo json_encode(['error' => 'file not found']);
                break;
            }
            $db = get_db();
            $db->prepare("
                INSERT INTO watch_history (user, path, watched_at, duration_sec)
                VALUES (:u, :p, datetime('now'), :d)
                ON CONFLICT(user, path) DO UPDATE SET watched_at = datetime('now'), duration_sec = :d
            ")->execute([':u' => $currentUser, ':p' => $path, ':d' => $duration]);
            echo json_encode(['ok' => true]);
            break;

        /**
         * ACTIVE_STREAMS — Liste les streams actifs (admin only)
         * Lit les fichiers /tmp/sharebox_stream_*.json écrits par download.php
         */
        case 'active_streams':
            if (($_SESSION['sharebox_role'] ?? '') !== 'admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Réservé aux admins']);
                exit;
            }
            // Libère le verrou de session avant usleep() — sinon toutes les requêtes
            // PHP du navigateur sont bloquées pendant la mesure CPU (500ms).
            session_write_close();

            $streams = [];
            $now = time();
            $db    = get_db();
            $files = glob('/tmp/sharebox_stream_*.json') ?: [];

            foreach ($files as $file) {
                $raw = @file_get_contents($file);
                if ($raw === false) continue;
                $data = json_decode($raw, true);
                if (!is_array($data)) continue;

                $lastSeen = isset($data['last_seen']) ? (int)$data['last_seen'] : 0;
                if ($lastSeen < $now - 120) continue;

                $startTime = isset($data['start_time']) ? strtotime($data['start_time']) : $now;
                $durationSec = $now - $startTime;

                $cpuPct = null;
                if (($data['mode'] ?? '') === 'hls' && !empty($data['hls_pid_file'])) {
                    $pidRaw = @file_get_contents($data['hls_pid_file']);
                    if ($pidRaw !== false) {
                        $pid = (int)trim($pidRaw);
                        if ($pid > 0) $cpuPct = get_pid_cpu($pid);
                    }
                }

                $path   = $data['path'] ?? '';
                $folder = $path ? basename(dirname($path)) : null;

                $user = $data['user'] ?? null;
                if ((!$user || $user === 'anonymous') && !empty($data['token'])) {
                    $row = $db->prepare("SELECT name FROM links WHERE token = :t");
                    $row->execute([':t' => $data['token']]);
                    $linkName = $row->fetchColumn();
                    if ($linkName) $user = $linkName;
                }

                $streams[] = [
                    'user'         => $user,
                    'filename'     => $data['filename'] ?? null,
                    'folder'       => $folder,
                    'path'         => $path,
                    'mode'         => $data['mode'] ?? null,
                    'duration_sec' => $durationSec,
                    'cpu_pct'      => $cpuPct,
                ];
            }

            echo json_encode(['streams' => $streams, 'count' => count($streams)]);
            break;

        /**
         * SEARCH — Recherche de fichiers/dossiers sur le disque entier
         * Paramètre GET : q (termes séparés par espaces, ET logique)
         * Retourne : tableau de résultats {name, path, type, size}
         */
        case 'search':
            $q = trim($_GET['q'] ?? '');
            if ($q === '') {
                echo json_encode(['results' => [], 'query' => '']);
                break;
            }
            if (mb_strlen($q) > 100) {
                http_response_code(400);
                echo json_encode(['error' => 'Terme trop long (max 100 caractères)']);
                exit;
            }

            $searchBase = BASE_PATH;
            if (($_SESSION['sharebox_role'] ?? '') !== 'admin'
                && (int)($_SESSION['sharebox_private'] ?? 0) === 1) {
                $searchBase = BASE_PATH . ($_SESSION['sharebox_user'] ?? '') . '/';
            }

            $words = array_values(array_filter(array_map('trim', explode(' ', $q))));
            if (count($words) > 10) $words = array_slice($words, 0, 10);

            $inameArgs = implode(' ', array_map(
                fn($w) => '-iname ' . escapeshellarg("*{$w}*"),
                $words
            ));

            $cmd = 'timeout 10 find ' . escapeshellarg(rtrim($searchBase, '/'))
                 . ' -mindepth 1 -maxdepth 8 ' . $inameArgs
                 . " ! -name '.*' 2>/dev/null | head -150";
            $output = shell_exec($cmd) ?? '';

            $results = [];
            $baseLen = strlen(rtrim($searchBase, '/')) + 1;
            foreach (explode("\n", $output) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $isDir   = is_dir($line);
                $relPath = substr($line, $baseLen);
                $results[] = [
                    'name' => basename($line),
                    'path' => $relPath,
                    'type' => $isDir ? 'folder' : 'file',
                    'size' => $isDir ? null : @filesize($line),
                ];
            }

            echo json_encode(['results' => $results, 'query' => $q]);
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

