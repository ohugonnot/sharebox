<?php
/**
 * ShareBox — Panneau utilisateur (gestion compte + administration)
 * Accessible à tous les utilisateurs connectés. Onglets admin réservés aux admins.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

require_auth();

$isAdmin = ($_SESSION['sharebox_role'] ?? '') === 'admin';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// Detect seedbox mode (rtorrent/ruTorrent/SFTP scripts installed)
$seedboxMode = is_executable('/usr/local/bin/seedbox-adduser');

// ── API actions (JSON responses) ───────────────────────────────
$action = $_GET['action'] ?? '';

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');

    // Actions admin-only
    $adminOnlyActions = ['list_users','create_user','update_user','delete_user',
                         'restart_rtorrent','stop_rtorrent','tmdb_status','tmdb_scan',
                         'purge_expired','recent_activity','activity_events',
                         'ai_scan_launch','ai_scan_status','ai_scan_log'];
    if (in_array($action, $adminOnlyActions, true) && !$isAdmin) {
        http_response_code(403);
        echo json_encode(['error' => 'Accès réservé aux administrateurs.']);
        exit;
    }

    // CSRF check for POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

            case 'list_users':
                $db = get_db();
                $users = $db->query("SELECT id, username, role, private, created_at FROM users ORDER BY created_at ASC")->fetchAll();

                foreach ($users as &$u) {
                    if ($seedboxMode) {
                        $svc = escapeshellarg("rtorrent@{$u['username']}");
                        exec("sudo /usr/bin/systemctl is-active {$svc} 2>&1", $out, $code);
                        $u['rtorrent_status'] = trim(implode('', $out));
                        $u['has_system_user'] = posix_getpwnam($u['username']) !== false;
                        unset($out);
                    } else {
                        $u['rtorrent_status'] = null;
                        $u['has_system_user'] = false;
                    }

                    // Quota disque
                    $u['disk_used'] = null;
                    if (defined('BASE_PATH')) {
                        $userDir = rtrim(BASE_PATH, '/') . '/' . $u['username'];
                        if (is_dir($userDir)) {
                            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
                            $proc = proc_open(['/usr/bin/du', '-sb', $userDir], $descriptors, $pipes);
                            if (is_resource($proc)) {
                                stream_set_timeout($pipes[1], 5);
                                $out = fgets($pipes[1]);
                                fclose($pipes[0]);
                                fclose($pipes[1]);
                                fclose($pipes[2]);
                                if ($out === false) {
                                    proc_terminate($proc);
                                }
                                proc_close($proc);
                                if ($out !== false && preg_match('/^(\d+)/', $out, $m)) {
                                    $u['disk_used'] = (int)$m[1];
                                }
                            }
                        }
                    }
                }
                echo json_encode(['users' => $users, 'seedbox_mode' => $seedboxMode]);
                break;

            case 'create_user':
                $username = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($input['username'] ?? '')));
                $password = $input['password'] ?? '';
                $role = in_array($input['role'] ?? '', ['admin', 'user']) ? $input['role'] : 'user';
                $private = isset($input['private']) && $input['private'] ? 1 : 0;

                if (strlen($username) < 2 || strlen($username) > 32) {
                    echo json_encode(['error' => 'Username : 2-32 caractères (a-z, 0-9, _, -)']);
                    break;
                }
                if (strlen($password) < 4) {
                    echo json_encode(['error' => 'Mot de passe : 4 caractères minimum']);
                    break;
                }

                $db = get_db();
                $exists = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                $exists->execute([$username]);
                if ((int)$exists->fetchColumn() > 0) {
                    echo json_encode(['error' => "L'utilisateur '$username' existe déjà"]);
                    break;
                }

                // DB first
                $stmt = $db->prepare("INSERT INTO users (username, password_hash, role, private) VALUES (?, ?, ?, ?)");
                $stmt->execute([$username, password_hash($password, PASSWORD_BCRYPT), $role, $private]);

                // Create seedbox user if in seedbox mode
                if ($seedboxMode) {
                    $cmd = sprintf(
                        'sudo /usr/local/bin/seedbox-adduser %s %s 2>&1',
                        escapeshellarg($username),
                        escapeshellarg($password)
                    );
                    exec($cmd, $cmdOut, $cmdCode);

                    if ($cmdCode !== 0) {
                        $db->prepare("DELETE FROM users WHERE username = ?")->execute([$username]);
                        echo json_encode(['error' => 'Erreur création seedbox: ' . implode("\n", $cmdOut)]);
                        break;
                    }
                }

                log_activity('admin_create_user', $_SESSION['sharebox_user'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null, $username);
                echo json_encode(['ok' => true, 'message' => "Utilisateur '$username' créé"]);
                break;

            case 'update_user':
                $userId = (int)($input['id'] ?? 0);
                $db = get_db();
                $user = $db->prepare("SELECT * FROM users WHERE id = ?");
                $user->execute([$userId]);
                $user = $user->fetch();

                if (!$user) {
                    echo json_encode(['error' => 'Utilisateur introuvable']);
                    break;
                }

                $newPassword = $input['password'] ?? '';
                $newRole = in_array($input['role'] ?? '', ['admin', 'user']) ? $input['role'] : $user['role'];
                $newPrivate = array_key_exists('private', $input) ? ($input['private'] ? 1 : 0) : (int)$user['private'];

                // Update role and private
                $db->prepare("UPDATE users SET role = ?, private = ? WHERE id = ?")->execute([$newRole, $newPrivate, $userId]);

                // Update password if provided
                if ($newPassword !== '') {
                    if (strlen($newPassword) < 4) {
                        echo json_encode(['error' => 'Mot de passe : 4 caractères minimum']);
                        break;
                    }
                    $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                       ->execute([password_hash($newPassword, PASSWORD_BCRYPT), $userId]);

                    // Update htpasswd too (seedbox mode)
                    if ($seedboxMode) {
                        exec(sprintf(
                            'sudo /usr/bin/htpasswd -b /etc/nginx/.htpasswd %s %s 2>&1',
                            escapeshellarg($user['username']),
                            escapeshellarg($newPassword)
                        ));
                    }
                }

                log_activity('admin_edit_user', $_SESSION['sharebox_user'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null, $user['username']);
                echo json_encode(['ok' => true, 'message' => "Utilisateur '{$user['username']}' mis à jour"]);
                break;

            case 'delete_user':
                $userId = (int)($input['id'] ?? 0);
                $db = get_db();
                $user = $db->prepare("SELECT * FROM users WHERE id = ?");
                $user->execute([$userId]);
                $user = $user->fetch();

                if (!$user) {
                    echo json_encode(['error' => 'Utilisateur introuvable']);
                    break;
                }

                // Can't delete yourself
                if ($user['username'] === ($_SESSION['sharebox_user'] ?? '')) {
                    echo json_encode(['error' => 'Vous ne pouvez pas supprimer votre propre compte']);
                    break;
                }

                $deleteData = !empty($input['delete_data']);

                // Delete seedbox user if in seedbox mode
                if ($seedboxMode) {
                    $flags = $deleteData ? '--delete-data' : '';
                    exec(sprintf(
                        'sudo /usr/local/bin/seedbox-deluser %s %s 2>&1',
                        escapeshellarg($user['username']),
                        $flags
                    ), $cmdOut, $cmdCode);
                }

                // Remove from ShareBox DB
                $db->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);

                log_activity('admin_delete_user', $_SESSION['sharebox_user'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null, $user['username']);
                echo json_encode(['ok' => true, 'message' => "Utilisateur '{$user['username']}' supprimé"]);
                break;

            case 'restart_rtorrent':
                $username = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($input['username'] ?? '')));
                if ($username === '') {
                    echo json_encode(['error' => 'Username manquant']);
                    break;
                }
                $svcName = escapeshellarg("rtorrent@{$username}");
                exec("sudo /usr/bin/systemctl restart {$svcName} 2>&1", $out, $code);
                sleep(2);
                exec("sudo /usr/bin/systemctl is-active {$svcName} 2>&1", $statusOut);
                $status = trim(implode('', $statusOut));
                echo json_encode(['ok' => $code === 0, 'status' => $status, 'message' => "rtorrent@{$username}: {$status}"]);
                break;

            case 'stop_rtorrent':
                $username = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($input['username'] ?? '')));
                if ($username === '') {
                    echo json_encode(['error' => 'Username manquant']);
                    break;
                }
                $svcName = escapeshellarg("rtorrent@{$username}");
                exec("sudo /usr/bin/systemctl stop {$svcName} 2>&1", $out, $code);
                echo json_encode(['ok' => true, 'status' => 'inactive', 'message' => "rtorrent@{$username} arrêté"]);
                break;

            case 'tmdb_status':
                $db = new PDO('sqlite:' . DB_PATH, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
                $db->exec('PRAGMA journal_mode=WAL');
                $db->exec('PRAGMA query_only = ON');
                $db->exec('PRAGMA busy_timeout = 1000');
                $total = (int)$db->query("SELECT COUNT(*) FROM folder_posters")->fetchColumn();
                $matched = (int)$db->query("SELECT COUNT(*) FROM folder_posters WHERE poster_url IS NOT NULL AND poster_url != '__none__'")->fetchColumn();
                $hidden = (int)$db->query("SELECT COUNT(*) FROM folder_posters WHERE poster_url = '__none__'")->fetchColumn();
                $pending = (int)$db->query("SELECT COUNT(*) FROM folder_posters WHERE poster_url IS NULL AND (verified IS NULL OR verified >= 0)")->fetchColumn();
                $failed = (int)$db->query("SELECT COUNT(*) FROM folder_posters WHERE poster_url IS NULL AND verified = -1")->fetchColumn();
                $scanLock = dirname(DB_PATH) . '/sharebox_tmdb_cron.lock';
                $scanning = false;
                if (file_exists($scanLock)) {
                    $scanPid = (int)@file_get_contents($scanLock);
                    $scanning = $scanPid > 0 && is_dir("/proc/$scanPid");
                }
                // Confidence breakdown
                $high = (int)$db->query("SELECT COUNT(*) FROM folder_posters WHERE poster_url IS NOT NULL AND poster_url != '__none__' AND verified >= 80")->fetchColumn();
                $medium = (int)$db->query("SELECT COUNT(*) FROM folder_posters WHERE poster_url IS NOT NULL AND poster_url != '__none__' AND verified >= 50 AND verified < 80")->fetchColumn();
                $low = (int)$db->query("SELECT COUNT(*) FROM folder_posters WHERE poster_url IS NOT NULL AND poster_url != '__none__' AND (verified < 50 OR verified IS NULL OR verified = 0)")->fetchColumn();
                $avgConf = (int)$db->query("SELECT COALESCE(AVG(verified), 0) FROM folder_posters WHERE poster_url IS NOT NULL AND poster_url != '__none__' AND verified > 0")->fetchColumn();
                echo json_encode([
                    'total' => $total, 'matched' => $matched, 'hidden' => $hidden,
                    'pending' => $pending, 'failed' => $failed, 'scanning' => $scanning,
                    'confidence' => ['high' => $high, 'medium' => $medium, 'low' => $low, 'avg' => $avgConf],
                ]);
                break;

            case 'tmdb_scan':
                // Launch tmdb-worker.php in background
                $lockFile = dirname(DB_PATH) . '/sharebox_tmdb_cron.lock';
                if (file_exists($lockFile)) {
                    $lockPid = (int)@file_get_contents($lockFile);
                    if ($lockPid > 0 && is_dir("/proc/$lockPid")) {
                        echo json_encode(['ok' => false, 'message' => 'Scan déjà en cours']);
                        break;
                    }
                }
                $script = realpath(__DIR__ . '/tools/tmdb-worker.php');
                $logFile = dirname(DB_PATH) . '/tmdb-worker.log';
                $cmd = sprintf(
                    'nohup /usr/bin/php %s --cron >> %s 2>&1 </dev/null & echo $!',
                    escapeshellarg($script),
                    escapeshellarg($logFile)
                );
                $pid = trim(shell_exec($cmd));
                echo json_encode(['ok' => true, 'message' => 'Scan TMDB lancé', 'pid' => (int)$pid]);
                break;

            case 'ai_scan_launch':
                $lockFile  = __DIR__ . '/data/sharebox_ai_scan.lock';
                $logFile   = __DIR__ . '/data/ai-scan.log';
                $tsFile    = __DIR__ . '/data/ai-scan-last-launch.txt';
                if (file_exists($lockFile)) {
                    $pid = (int)file_get_contents($lockFile);
                    if ($pid > 0 && is_dir("/proc/$pid")) {
                        echo json_encode(['ok' => false, 'message' => 'Scan IA déjà en cours', 'pid' => $pid]);
                        break;
                    }
                }
                // Rate limit : 1 scan par heure
                if (file_exists($tsFile)) {
                    $elapsed = time() - (int)file_get_contents($tsFile);
                    if ($elapsed < 3600) {
                        $wait = 3600 - $elapsed;
                        $min  = ceil($wait / 60);
                        echo json_encode(['ok' => false, 'message' => "Scan IA disponible dans $min min"]);
                        break;
                    }
                }
                file_put_contents($logFile, '');
                file_put_contents($tsFile, (string)time());
                // Effacer le résultat du scan précédent
                @unlink(__DIR__ . '/data/ai-scan-result.json');
                $cmd = 'sudo /usr/local/bin/sharebox-tmdb-ai-scan >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';
                $pid = (int)trim(shell_exec($cmd));
                if ($pid <= 0) {
                    echo json_encode(['ok' => false, 'message' => 'Échec du lancement']);
                    break;
                }
                file_put_contents($lockFile, (string)$pid);
                echo json_encode(['ok' => true, 'pid' => $pid]);
                break;

            case 'ai_scan_status':
                $lockFile = __DIR__ . '/data/sharebox_ai_scan.lock';
                $logFile  = __DIR__ . '/data/ai-scan.log';
                $running  = false;
                $pid      = 0;
                if (file_exists($lockFile)) {
                    $pid = (int)file_get_contents($lockFile);
                    if ($pid > 0 && is_dir("/proc/$pid")) {
                        $running = true;
                    }
                }
                $logSize    = file_exists($logFile) ? filesize($logFile) : 0;
                $resultFile = __DIR__ . '/data/ai-scan-result.json';
                $lastResult = file_exists($resultFile) ? json_decode(file_get_contents($resultFile), true) : null;
                $tsFile     = __DIR__ . '/data/ai-scan-last-launch.txt';
                $cooldown   = 0;
                if (file_exists($tsFile)) {
                    $elapsed  = time() - (int)file_get_contents($tsFile);
                    $cooldown = max(0, 3600 - $elapsed);
                }
                $aiAvailable = file_exists('/usr/local/bin/sharebox-tmdb-ai-scan');
                // Live progress: count ia_checked entries (updated by Claude in real-time)
                $iaChecked = 0;
                $iaTotal = 0;
                $recentChanges = [];
                try {
                    $aiDb = new PDO('sqlite:' . DB_PATH, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
                    $aiDb->exec('PRAGMA journal_mode=WAL');
                    $aiDb->exec('PRAGMA query_only = ON');
                    $aiDb->exec('PRAGMA busy_timeout = 1000');
                    $iaChecked = (int)$aiDb->query("SELECT COUNT(*) FROM folder_posters WHERE ia_checked = 1")->fetchColumn();
                    $iaTotal = (int)$aiDb->query("SELECT COUNT(*) FROM folder_posters")->fetchColumn();
                    // Last 20 AI-verified entries with details
                    $since = $_GET['since'] ?? '';
                    $q = $since
                        ? $aiDb->prepare("SELECT path, title, verified, tmdb_type, updated_at FROM folder_posters WHERE ia_checked = 1 AND updated_at > ? ORDER BY updated_at DESC LIMIT 20")
                        : $aiDb->prepare("SELECT path, title, verified, tmdb_type, updated_at FROM folder_posters WHERE ia_checked = 1 ORDER BY updated_at DESC LIMIT 20");
                    $since ? $q->execute([$since]) : $q->execute();
                    foreach ($q->fetchAll() as $row) {
                        $short = preg_replace('#^' . preg_quote(rtrim(BASE_PATH, '/'), '#') . '/#', '', $row['path']);
                        $recentChanges[] = [
                            'path' => $short,
                            'title' => $row['title'],
                            'verified' => (int)$row['verified'],
                            'type' => $row['tmdb_type'],
                            'at' => $row['updated_at'],
                        ];
                    }
                } catch (PDOException $e) {}
                echo json_encode(['running' => $running, 'pid' => $pid, 'log_size' => $logSize, 'last_result' => $lastResult, 'cooldown' => $cooldown, 'ai_available' => $aiAvailable, 'ia_checked' => $iaChecked, 'ia_total' => $iaTotal, 'recent' => $recentChanges]);
                break;

            case 'ai_scan_log':
                $logFile = __DIR__ . '/data/ai-scan.log';
                $offset  = max(0, (int)($_GET['offset'] ?? 0));
                $lines   = [];
                $nextOffset = $offset;
                if (file_exists($logFile)) {
                    $fh = fopen($logFile, 'r');
                    if ($fh) {
                        fseek($fh, $offset);
                        $chunk = fread($fh, 131072);
                        fclose($fh);
                        if ($chunk !== false && $chunk !== '') {
                            $nextOffset = $offset + strlen($chunk);
                            // Parse stream-json JSONL — extraire les lignes lisibles
                            foreach (explode("\n", $chunk) as $jsonLine) {
                                $jsonLine = trim($jsonLine);
                                if ($jsonLine === '') continue;
                                $ev = json_decode($jsonLine, true);
                                if (!$ev) continue;
                                $type = $ev['type'] ?? '';
                                if ($type === 'assistant') {
                                    foreach ($ev['message']['content'] ?? [] as $c) {
                                        if ($c['type'] === 'text' && trim($c['text']) !== '') {
                                            $lines[] = trim($c['text']);
                                        } elseif ($c['type'] === 'tool_use') {
                                            $desc = $c['input']['description'] ?? $c['input']['command'] ?? '';
                                            $name = $c['name'] ?? 'tool';
                                            $lines[] = '▶ ' . $name . ($desc ? ': ' . mb_substr($desc, 0, 120) : '');
                                        }
                                    }
                                } elseif ($type === 'user') {
                                    foreach ($ev['message']['content'] ?? [] as $c) {
                                        if (($c['type'] ?? '') === 'tool_result') {
                                            $out = $c['content'] ?? '';
                                            if (is_array($out)) $out = implode(' ', array_column($out, 'text'));
                                            $out = trim((string)$out);
                                            if ($out !== '') $lines[] = '  ' . mb_substr($out, 0, 300);
                                        }
                                    }
                                } elseif ($type === 'result') {
                                    $res = trim($ev['result'] ?? '');
                                    if ($res !== '') $lines[] = $res;
                                }
                            }
                        }
                    }
                }
                $lockFile = __DIR__ . '/data/sharebox_ai_scan.lock';
                $running  = false;
                if (file_exists($lockFile)) {
                    $pid = (int)file_get_contents($lockFile);
                    if ($pid > 0 && is_dir("/proc/$pid")) {
                        $running = true;
                    }
                }
                $fileSize = file_exists($logFile) ? filesize($logFile) : 0;
                $done     = !$running && $nextOffset >= $fileSize;
                // Quand le scan vient de se terminer, extraire le résumé du log brut
                if ($done && file_exists($logFile)) {
                    $rawLog = file_get_contents($logFile);
                    $resultFile = __DIR__ . '/data/ai-scan-result.json';
                    if (!file_exists($resultFile) && $rawLog) {
                        $matched = 0; $skipped = 0; $failed = 0;
                        $confirmed = 0; $fixed = 0;
                        if (preg_match('/matched=(\d+)/', $rawLog, $m)) $matched = (int)$m[1];
                        if (preg_match('/skipped=(\d+)/', $rawLog, $m)) $skipped = (int)$m[1];
                        if (preg_match('/failed=(\d+)/', $rawLog, $m)) $failed = (int)$m[1];
                        if (preg_match('/confirmed=(\d+)/', $rawLog, $m)) $confirmed = (int)$m[1];
                        if (preg_match('/fixed=(\d+)/', $rawLog, $m)) $fixed = (int)$m[1];
                        file_put_contents($resultFile, json_encode([
                            'ts' => time(), 'matched' => $matched, 'skipped' => $skipped,
                            'failed' => $failed, 'confirmed' => $confirmed, 'fixed' => $fixed,
                        ]));
                    }
                }
                echo json_encode(['lines' => $lines, 'next_offset' => $nextOffset, 'done' => $done]);
                break;

            case 'purge_expired':
                $db = get_db();
                $deleted = purge_expired_links($db);
                echo json_encode(['ok' => true, 'deleted' => $deleted]);
                break;

            case 'recent_activity':
                $db = get_db();
                $userFilter = trim($input['user'] ?? '');
                $search     = trim($input['search'] ?? '');
                $offset     = max(0, (int)($input['offset'] ?? 0));
                $limit      = 15;

                $where = [];
                $params = [];
                if ($userFilter !== '') { $where[] = 'l.created_by = ?'; $params[] = $userFilter; }
                if ($search !== '')     { $where[] = 'l.name LIKE ?';    $params[] = '%' . $search . '%'; }
                $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

                $base = "FROM download_logs dl JOIN links l ON dl.link_id = l.id $whereClause";
                $totalStmt = $db->prepare("SELECT COUNT(*) $base");
                $totalStmt->execute($params);
                $totalCount = (int)$totalStmt->fetchColumn();

                $stmt = $db->prepare("SELECT dl.id, l.name, l.token, l.created_by, dl.ip, dl.downloaded_at $base ORDER BY dl.downloaded_at DESC LIMIT $limit OFFSET ?");
                $stmt->execute([...$params, $offset]);
                echo json_encode(['logs' => $stmt->fetchAll(), 'total' => $totalCount, 'limit' => $limit, 'offset' => $offset]);
                break;

            case 'activity_events':
                $db = get_db();
                $typeFilter = trim($input['type_filter'] ?? '');
                $offset     = max(0, (int)($input['offset'] ?? 0));
                $limit      = 15;

                $where  = [];
                $params = [];

                if ($typeFilter === 'connexions') {
                    $where[] = "event_type IN ('login_ok','login_fail')";
                } elseif ($typeFilter === 'liens') {
                    $where[] = "event_type IN ('link_create','link_delete')";
                } elseif ($typeFilter === 'admin') {
                    $where[] = "event_type IN ('admin_create_user','admin_edit_user','admin_delete_user')";
                }

                $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

                $total = (int)$db->query("SELECT COUNT(*) FROM activity_logs $whereClause")->fetchColumn();

                $stmt = $db->prepare("SELECT id, event_type, username, ip, details, created_at FROM activity_logs $whereClause ORDER BY created_at DESC LIMIT $limit OFFSET ?");
                $stmt->execute([...$params, $offset]);
                echo json_encode(['logs' => $stmt->fetchAll(), 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
                break;

            default:
                http_response_code(400);
                echo json_encode(['error' => 'Action inconnue']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── HTML page ──────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0c0e14">
    <link rel="manifest" href="/share/manifest.json">
    <link rel="icon" type="image/svg+xml" href="/share/favicon.svg">
    <link rel="stylesheet" href="/share/style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <title>ShareBox — Admin</title>
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --bg-void: #06080c;
            --bg-deep: #0f1119;
            --bg-card: #111420;
            --bg-input: #0d1018;
            --bg-row-hover: #161a26;
            --accent: #f0a030;
            --accent-dim: rgba(240, 160, 48, .08);
            --accent-glow: rgba(240, 160, 48, .15);
            --text: #d8dce8;
            --text-dim: #7e879e;
            --text-muted: #555968;
            --border: rgba(255, 255, 255, .04);
            --border-strong: rgba(255, 255, 255, .08);
            --red: #e8453c;
            --green: #3ddc84;
            --blue: #42a5f5;
        }

        body {
            background: var(--bg-deep);
            color: var(--text);
            min-height: 100vh;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(ellipse 80% 50% at 50% -20%, rgba(240, 160, 48, .03), transparent),
                linear-gradient(rgba(255,255,255,.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.03) 1px, transparent 1px);
            background-size: 100% 100%, 48px 48px, 48px 48px;
            pointer-events: none;
            z-index: 0;
        }

        /* ── Main ── */

        .page-title {
            font-size: 1.4rem;
            font-weight: 700;
            letter-spacing: -.02em;
            margin-bottom: .4rem;
        }

        .page-sub {
            font-size: .82rem;
            color: var(--text-dim);
            margin-bottom: 2rem;
        }

        /* ── Card ── */
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.4rem;
            border-bottom: 1px solid var(--border);
        }

        .card-title {
            font-size: .85rem;
            font-weight: 600;
            letter-spacing: -.01em;
        }

        .accordion-toggle { cursor: pointer; user-select: none; }
        .accordion-toggle:hover .card-title { color: var(--accent); }
        .accordion-chevron {
            font-size: .75rem;
            color: var(--text-secondary);
            transition: transform .2s;
            flex-shrink: 0;
        }
        .accordion-chevron.open { transform: rotate(180deg); }

        /* ── Table ── */
        .user-table {
            width: 100%;
            border-collapse: collapse;
        }

        .user-table th {
            text-align: left;
            padding: .7rem 1.4rem;
            font-size: .68rem;
            font-weight: 600;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--text-dim);
            border-bottom: 1px solid var(--border);
        }

        .user-table td {
            padding: .8rem 1.4rem;
            font-size: .85rem;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        .user-table tr:last-child td { border-bottom: none; }
        .user-table tr:hover td { background: var(--bg-row-hover); }

        .username-cell {
            font-weight: 600;
            font-family: 'JetBrains Mono', monospace;
            font-size: .82rem;
        }

        /* ── Badges ── */
        .badge {
            display: inline-block;
            padding: .15rem .5rem;
            border-radius: 4px;
            font-size: .7rem;
            font-weight: 600;
            letter-spacing: .02em;
        }

        .badge-admin { background: var(--accent-dim); color: var(--accent); }
        .badge-user { background: rgba(66, 165, 245, .1); color: var(--blue); }
        .badge-active { background: rgba(61, 220, 132, .1); color: var(--green); }
        .badge-inactive { background: rgba(232, 69, 60, .1); color: var(--red); }
        .badge-failed { background: rgba(232, 69, 60, .1); color: var(--red); }
        .badge-no-system { background: rgba(90, 96, 120, .15); color: var(--text-dim); }

        /* ── Buttons ── */
        .btn {
            padding: .4rem .7rem;
            border: none;
            border-radius: 6px;
            font-family: 'Sora', sans-serif;
            font-size: .75rem;
            font-weight: 500;
            cursor: pointer;
            transition: all .15s;
            display: inline-flex;
            align-items: center;
            gap: .3rem;
        }

        .btn-accent {
            background: var(--accent);
            color: var(--bg-void);
        }
        .btn-accent:hover { box-shadow: 0 4px 16px rgba(240, 160, 48, .25); transform: translateY(-1px); }

        .btn-ghost {
            background: transparent;
            color: var(--text-dim);
            border: 1px solid var(--border-strong);
        }
        .btn-ghost:hover { color: var(--text); border-color: var(--accent); }

        .btn-danger {
            background: rgba(232, 69, 60, .1);
            color: var(--red);
            border: 1px solid rgba(232, 69, 60, .15);
        }
        .btn-danger:hover { background: rgba(232, 69, 60, .2); }

        .btn-green {
            background: rgba(61, 220, 132, .1);
            color: var(--green);
            border: 1px solid rgba(61, 220, 132, .15);
        }
        .btn-green:hover { background: rgba(61, 220, 132, .2); }

        .actions { display: flex; gap: .4rem; flex-wrap: wrap; }

        /* ── Modal ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .7);
            backdrop-filter: blur(4px);
            z-index: 200;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.open { display: flex; }

        .modal {
            background: var(--bg-card);
            border: 1px solid var(--border-strong);
            border-radius: 16px;
            width: 100%;
            max-width: 420px;
            padding: 2rem;
            animation: modalIn .25s ease-out;
        }

        @keyframes modalIn {
            from { opacity: 0; transform: scale(.95) translateY(10px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        .modal-title {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
        }

        .modal-field {
            margin-bottom: 1rem;
        }

        .modal-field label {
            display: block;
            font-size: .7rem;
            font-weight: 600;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: var(--text-dim);
            margin-bottom: .4rem;
        }

        .modal-field input,
        .modal-field select {
            width: 100%;
            padding: .65rem .8rem;
            background: var(--bg-input);
            border: 1px solid var(--border-strong);
            border-radius: 8px;
            color: var(--text);
            font-family: 'Sora', sans-serif;
            font-size: .88rem;
            outline: none;
            transition: border-color .2s;
        }

        .modal-field input:focus,
        .modal-field select:focus {
            border-color: rgba(240, 160, 48, .4);
            box-shadow: 0 0 0 3px var(--accent-dim);
        }

        .modal-field select {
            cursor: pointer;
            -webkit-appearance: none;
        }

        .modal-actions {
            display: flex;
            gap: .6rem;
            margin-top: 1.5rem;
            justify-content: flex-end;
        }

        .modal-actions .btn { padding: .55rem 1.2rem; font-size: .82rem; }

        /* ── Toast ── */
        .toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            padding: .7rem 1.2rem;
            border-radius: 8px;
            font-size: .82rem;
            font-weight: 500;
            z-index: 300;
            animation: toastIn .3s ease-out;
            max-width: 360px;
        }

        .toast-ok { background: rgba(61, 220, 132, .15); color: var(--green); border: 1px solid rgba(61, 220, 132, .2); }
        .toast-err { background: rgba(232, 69, 60, .15); color: var(--red); border: 1px solid rgba(232, 69, 60, .2); }

        @keyframes toastIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .empty-msg {
            text-align: center;
            padding: 2rem;
            color: var(--text-dim);
            font-size: .85rem;
        }

        .hint {
            font-size: .72rem;
            color: var(--text-muted);
            margin-top: .3rem;
        }

        /* ── Pagination activité ── */
        .activity-pager {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: .5rem;
            padding: .7rem 1.4rem;
            border-top: 1px solid var(--border);
            font-size: .8rem;
            color: var(--text-dim);
        }
        .activity-pager:empty { display: none; }
        .pager-btn {
            background: transparent;
            border: 1px solid var(--border-strong);
            border-radius: 6px;
            color: var(--text-dim);
            padding: .25rem .6rem;
            font-size: .78rem;
            cursor: pointer;
            font-family: inherit;
            transition: color .15s, border-color .15s;
        }
        .pager-btn:hover:not(:disabled) { color: var(--text); border-color: var(--accent); }
        .pager-btn:disabled { opacity: .35; cursor: default; }

        /* ── Tabs ── */
        .admin-tabs {
            display: flex;
            gap: 0;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1.5rem;
            overflow-x: auto;
            scrollbar-width: none;
        }
        .admin-tabs::-webkit-scrollbar { display: none; }
        .tab-btn {
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            color: var(--text-dim);
            padding: .65rem 1.2rem;
            font-family: inherit;
            font-size: .85rem;
            font-weight: 500;
            cursor: pointer;
            white-space: nowrap;
            margin-bottom: -1px;
            transition: color .15s, border-color .15s;
        }
        .tab-btn:hover { color: var(--text); }
        .tab-btn.active { color: var(--accent); border-bottom-color: var(--accent); }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }

        /* ── Responsive admin ── */
        #user-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        @media (max-width: 768px) {
            .admin-wrap { padding: 1rem .75rem; }
            .card-header { flex-wrap: wrap; gap: .5rem; }
            .user-table th, .user-table td { padding: .6rem .8rem; }
            .col-token, .col-ip, .col-date { display: none; }
        }
        @media (max-width: 480px) {
            .col-user, .col-disk, .col-rtorrent, .col-role { display: none; }
            .user-table th, .user-table td { padding: .5rem .6rem; font-size: .78rem; }
            #activity-search { width: 100%; }
            .activity-pager { justify-content: center; }
            .actions { flex-wrap: nowrap; }
            .actions .btn { padding: .25rem .45rem; font-size: .72rem; }
        }
        /* ── Streams actifs ── */
        .live-dot {
            width: 7px; height: 7px;
            border-radius: 50%;
            background: var(--text-muted);
            display: inline-block;
            flex-shrink: 0;
            transition: background .4s;
        }
        .live-dot.active {
            background: var(--green);
            animation: pulse-live 2s ease-in-out infinite;
        }
        @keyframes pulse-live {
            0%,100% { box-shadow: 0 0 0 0 rgba(61,220,132,.5); }
            50%      { box-shadow: 0 0 0 5px rgba(61,220,132,0); }
        }
        .streams-count-badge {
            font-size: .68rem; font-weight: 700;
            padding: .1rem .42rem;
            border-radius: 99px;
            background: rgba(61,220,132,.1);
            color: var(--green);
            border: 1px solid rgba(61,220,132,.18);
            font-family: 'JetBrains Mono', monospace;
            letter-spacing: .03em;
        }
        .stream-row {
            display: grid;
            grid-template-columns: 30px 1fr auto auto auto;
            align-items: center;
            column-gap: 1rem;
            padding: .6rem 1.2rem;
            border-bottom: 1px solid var(--border);
            transition: background .12s;
        }
        .stream-row:last-child { border-bottom: none; }
        .stream-row:hover { background: var(--bg-row-hover); }
        .stream-avatar {
            width: 28px; height: 28px;
            border-radius: 50%;
            background: var(--accent-dim);
            border: 1px solid rgba(240,160,48,.18);
            color: var(--accent);
            font-size: .65rem; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            font-family: 'JetBrains Mono', monospace;
        }
        .stream-info { min-width: 0; }
        .stream-filename {
            font-size: .8rem; color: var(--text);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            line-height: 1.3;
        }
        .stream-user-label {
            font-size: .7rem; color: var(--text-muted);
            margin-top: .1rem; font-family: 'JetBrains Mono', monospace;
        }
        .stream-mode-badge {
            font-size: .62rem; font-weight: 700;
            padding: .18rem .45rem; border-radius: 4px;
            letter-spacing: .07em; text-transform: uppercase;
            font-family: 'JetBrains Mono', monospace;
            white-space: nowrap; border: 1px solid transparent;
        }
        .smode-hls      { background:rgba(240,160,48,.1); color:var(--accent);   border-color:rgba(240,160,48,.2); }
        .smode-transcode{ background:rgba(66,165,245,.1);  color:var(--blue);    border-color:rgba(66,165,245,.2); }
        .smode-native   { background:rgba(61,220,132,.1);  color:var(--green);   border-color:rgba(61,220,132,.2); }
        .stream-duration {
            font-family: 'JetBrains Mono', monospace;
            font-size: .76rem; color: var(--text-dim);
            white-space: nowrap; min-width: 4.8rem; text-align: right;
        }
        .stream-cpu {
            min-width: 3.8rem; text-align: right;
            font-family: 'JetBrains Mono', monospace; font-size: .76rem;
        }
        .scpu-low  { color: var(--green); }
        .scpu-mid  { color: var(--accent); }
        .scpu-high { color: var(--red); }
        .scpu-none { color: var(--text-muted); }
        .streams-empty {
            padding: 2.2rem 1.4rem; text-align: center;
            color: var(--text-muted); font-size: .82rem;
        }
        .streams-empty svg { display:block; margin: 0 auto .7rem; opacity:.2; }
        .streams-col-heads {
            display: grid;
            grid-template-columns: 30px 1fr auto auto auto;
            column-gap: 1rem;
            padding: .4rem 1.2rem;
            border-bottom: 1px solid var(--border);
            font-size: .67rem; font-weight: 600;
            color: var(--text-muted);
            letter-spacing: .08em; text-transform: uppercase;
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
</head>
<body>

<div class="app">
    <?php $header_subtitle = 'Administration'; $header_back = true; include __DIR__ . '/header.php'; ?>

    <nav class="admin-tabs">
        <?php if ($isAdmin): ?>
        <button class="tab-btn active" onclick="switchTab('utilisateurs')">Utilisateurs</button>
        <button class="tab-btn" onclick="switchTab('activite')">Activité</button>
        <button class="tab-btn" onclick="switchTab('statistiques')">Statistiques</button>
        <button class="tab-btn" onclick="switchTab('systeme')">Système</button>
        <?php endif; ?>
        <button class="tab-btn <?= $isAdmin ? '' : 'active' ?>" onclick="switchTab('compte')">Mon compte</button>
    </nav>

    <?php if ($isAdmin): ?>
    <div id="tab-utilisateurs" class="tab-panel active">
        <div class="card">
            <div class="card-header">
                <div class="card-title">Utilisateurs<?= $seedboxMode ? ' <span style="font-weight:400;color:var(--text-dim);font-size:.75rem">(rtorrent + ruTorrent + SFTP)</span>' : '' ?></div>
                <button class="btn btn-accent" onclick="openCreateModal()">+ Nouvel utilisateur</button>
            </div>
            <div id="user-table-wrap">
                <div class="empty-msg">Chargement...</div>
            </div>
        </div>
    </div>

    <div id="tab-activite" class="tab-panel">
        <div class="card">
            <div class="card-header accordion-toggle" onclick="toggleAccordion('activite-recente')">
                <div class="card-title">Activité récente</div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center" onclick="event.stopPropagation()">
                    <input type="search" id="activity-search" placeholder="Rechercher un fichier…"
                           oninput="activitySearchDebounce()"
                           style="background:var(--bg-input);border:1px solid var(--border-strong);border-radius:6px;color:var(--text);padding:.3rem .6rem;font-size:.8rem;font-family:inherit;width:180px;outline:none">
                    <select id="activity-user-filter" onchange="reloadActivity()"
                            style="background:var(--bg-input);border:1px solid var(--border-strong);border-radius:6px;color:var(--text);padding:.3rem .6rem;font-size:.8rem;font-family:inherit">
                        <option value="">Tous les utilisateurs</option>
                    </select>
                </div>
                <span id="chevron-activite-recente" class="accordion-chevron">▾</span>
            </div>
            <div id="accordion-body-activite-recente">
                <div id="activity-wrap" style="padding:.8rem 1.4rem">
                    <div class="empty-msg">Chargement…</div>
                </div>
                <div id="activity-pagination" class="activity-pager"></div>
            </div>
        </div>

        <div class="card" style="margin-top:1.5rem">
            <div class="card-header accordion-toggle" onclick="toggleAccordion('evenements-systeme')">
                <div class="card-title">Événements système</div>
                <select id="events-type-filter" onchange="reloadEvents()" onclick="event.stopPropagation()"
                        style="background:var(--bg-input);border:1px solid var(--border-strong);border-radius:6px;color:var(--text);padding:.3rem .6rem;font-size:.8rem;font-family:inherit">
                    <option value="">Tous</option>
                    <option value="connexions">Connexions</option>
                    <option value="liens">Liens</option>
                    <option value="admin">Admin</option>
                </select>
                <span id="chevron-evenements-systeme" class="accordion-chevron">▾</span>
            </div>
            <div id="accordion-body-evenements-systeme">
                <div id="events-wrap" style="padding:.8rem 1.4rem">
                    <div class="empty-msg">Chargement…</div>
                </div>
                <div id="events-pagination" class="activity-pager"></div>
            </div>
        </div>
    </div>

    <div id="tab-systeme" class="tab-panel">
        <div class="card mb-4" id="card-streams">
          <div class="card-header d-flex justify-content-between align-items-center">
            <div style="display:flex;align-items:center;gap:.55rem">
              <span class="live-dot" id="streams-live-dot"></span>
              <span>Streams actifs</span>
              <span class="streams-count-badge" id="streams-count" style="display:none">0</span>
            </div>
            <small class="text-muted" id="streams-last-update" style="font-family:'JetBrains Mono',monospace;font-size:.7rem"></small>
          </div>
          <div id="streams-container">
            <p class="text-muted p-3 mb-0" style="font-size:.82rem">Chargement…</p>
          </div>
        </div>
        <div class="card" style="margin-bottom:1.5rem">
            <div class="card-header">
                <div class="card-title">TMDB Posters</div>
                <div style="display:flex;gap:.5rem">
                    <button class="btn btn-ghost" id="tmdb-scan-btn" onclick="launchTmdbScan()">Scan TMDB</button>
                    <button class="btn btn-accent" id="ai-scan-btn" onclick="launchAiScan()">Vérification IA</button>
                </div>
            </div>
            <div style="padding:1rem 1.4rem">
                <div id="tmdb-status" style="display:flex;gap:1.5rem;flex-wrap:wrap;font-size:.82rem;color:var(--text-dim)">
                    Chargement...
                </div>
                <div id="tmdb-bar-wrap" style="margin-top:.8rem;display:none">
                    <div style="background:var(--bg-input);border-radius:6px;height:6px;overflow:hidden">
                        <div id="tmdb-bar" style="height:100%;background:var(--accent);border-radius:6px;transition:width .5s;width:0%"></div>
                    </div>
                    <div id="tmdb-bar-label" style="font-size:.72rem;color:var(--text-muted);margin-top:.3rem"></div>
                </div>
                <div id="ai-scan-panel" style="display:none;margin-top:1rem">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.3rem">
                        <span style="font-size:.72rem;color:var(--text-muted)">Log IA</span>
                        <button onclick="closeAiPanel()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:.8rem;padding:0">×</button>
                    </div>
                    <div id="ai-scan-log" style="background:#0c0e14;border:1px solid var(--border);border-radius:var(--radius-sm);padding:.6rem .8rem;height:350px;overflow-y:auto;font-family:monospace;font-size:.75rem;color:#cdd6f4;white-space:pre-wrap;word-break:break-all;line-height:1.5"></div>
                </div>
            </div>
        </div>

    </div>
    <?php endif; ?>

    <!-- ══════ Onglet Statistiques ══════ -->
    <?php if ($isAdmin): ?>
    <div id="tab-statistiques" class="tab-panel">
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
                <div class="card-title">Upload par torrent</div>
                <div style="display:flex;gap:.5rem;align-items:center">
                    <span style="font-size:.8rem;color:var(--text-dim)">Période :</span>
                    <?php foreach (['7d'=>'7 jours','30d'=>'30 jours','90d'=>'90 jours'] as $r=>$l): ?>
                    <button class="btn btn-ghost seed-range-btn" data-range="<?= $r ?>"
                            style="font-size:.78rem;padding:.25rem .7rem"
                            onclick="seedSetRange('<?= $r ?>')"><?= $l ?></button>
                    <?php endforeach; ?>
                    <span id="seed-loading" style="font-size:.78rem;color:var(--text-dim);margin-left:.3rem;display:none">Chargement…</span>
                </div>
            </div>

            <!-- Lifecycle scatter -->
            <div style="padding:.8rem 1.4rem .4rem">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">
                    <div id="seed-chart-desc" style="font-size:.78rem;color:var(--text-dim)">Cycle de vie — âge vs vitesse d'upload (chaque point = un torrent)</div>
                    <div style="display:flex;gap:.3rem">
                        <button class="btn btn-ghost seed-metric-btn active" data-metric="speed" onclick="seedSetMetric('speed')" style="font-size:.72rem;padding:.2rem .55rem">Vitesse</button>
                        <button class="btn btn-ghost seed-metric-btn" data-metric="volume" onclick="seedSetMetric('volume')" style="font-size:.72rem;padding:.2rem .55rem">Volume</button>
                    </div>
                </div>
                <div style="position:relative;height:240px"><canvas id="seed-lifecycle-chart"></canvas></div>
            </div>

            <!-- Table top uploaders + pager -->
            <div style="padding:.6rem 1.4rem 0;overflow-x:auto">
                <table class="user-table" id="seed-table" style="font-size:.8rem">
                    <thead><tr>
                        <th style="text-align:left">Torrent</th>
                        <th>Loc</th>
                        <th style="cursor:pointer" onclick="seedSort('total_up')">Total ↕</th>
                        <th style="cursor:pointer" onclick="seedSort('avg_rate_kbps')">Moy KB/s ↕</th>
                        <th style="cursor:pointer" onclick="seedSort('size_bytes')">Taille ↕</th>
                        <th>Âge</th>
                    </tr></thead>
                    <tbody id="seed-tbody"><tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:1.5rem">En attente de données…</td></tr></tbody>
                </table>
            </div>
            <div id="seed-pager" style="padding:.5rem 1.4rem 1rem;display:flex;justify-content:space-between;align-items:center"></div>
        </div>

        <!-- Modal chart torrent individuel -->
        <div id="seed-modal" class="modal-overlay" onclick="if(event.target===this)closeSeedModal()" style="display:none">
            <div style="background:var(--bg-card);border:1px solid var(--border-strong);border-radius:16px;max-width:780px;width:95%;padding:1.4rem">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
                    <div id="seed-modal-title" style="font-weight:600;font-size:.95rem;color:var(--text)"></div>
                    <button class="btn btn-ghost" onclick="closeSeedModal()" style="padding:.2rem .6rem;font-size:.85rem">✕</button>
                </div>
                <div style="display:flex;gap:.5rem;margin-bottom:.8rem">
                    <?php foreach (['7d'=>'7j','30d'=>'30j','90d'=>'90j'] as $r=>$l): ?>
                    <button class="btn btn-ghost seed-chart-range-btn" data-range="<?= $r ?>"
                            style="font-size:.75rem;padding:.2rem .55rem"
                            onclick="seedLoadChart(seedModalHash,'<?= $r ?>')"><?= $l ?></button>
                    <?php endforeach; ?>
                    <span id="seed-modal-meta" style="font-size:.75rem;color:var(--text-dim);margin-left:.5rem;align-self:center"></span>
                </div>
                <div style="position:relative;height:240px"><canvas id="seed-torrent-chart"></canvas></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div id="tab-compte" class="tab-panel <?= $isAdmin ? '' : 'active' ?>">
        <div class="card" style="max-width:420px">
            <div class="card-header">
                <div class="card-title">Mon compte</div>
            </div>
            <div style="padding:1.2rem 1.4rem">
                <div class="modal-compte-label">Mot de passe actuel</div>
                <input type="password" id="mdp-actuel" class="modal-compte-input" autocomplete="current-password">
                <div class="modal-compte-label">Nouveau mot de passe</div>
                <input type="password" id="mdp-nouveau" class="modal-compte-input" autocomplete="new-password">
                <div class="modal-compte-label">Confirmation</div>
                <input type="password" id="mdp-confirm" class="modal-compte-input" autocomplete="new-password">
                <div id="mdp-error" style="display:none;color:var(--red,#e8453c);font-size:.82rem;margin-top:.5rem"></div>
                <div style="display:flex;justify-content:flex-end;margin-top:1.2rem">
                    <button id="mdp-submit" onclick="soumettreChangementMdp()" style="padding:.4rem .8rem;background:var(--accent,#f0a030);color:#000;border:none;border-radius:6px;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:600">Enregistrer</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create Modal -->
<div class="modal-overlay" id="modal-create">
    <div class="modal">
        <div class="modal-title">Nouvel utilisateur</div>
        <div class="modal-field">
            <label>Nom d'utilisateur</label>
            <input type="text" id="create-username" placeholder="ex: john" autocomplete="off">
            <div class="hint">Minuscules, chiffres, tirets, underscores (2-32 car.)</div>
        </div>
        <div class="modal-field">
            <label>Mot de passe</label>
            <input type="password" id="create-password" placeholder="4 caractères minimum">
        </div>
        <div class="modal-field">
            <label>Rôle</label>
            <select id="create-role">
                <option value="user">Utilisateur</option>
                <option value="admin">Administrateur</option>
            </select>
        </div>
        <div class="modal-field">
            <label style="display:flex;align-items:flex-start;gap:.6rem;cursor:pointer;font-size:.85rem;color:var(--text)">
                <input type="checkbox" id="create-private" style="margin-top:.2rem;accent-color:var(--accent);width:14px;height:14px;flex-shrink:0;cursor:pointer">
                <span>
                    Mode privé
                    <span style="display:block;font-size:.72rem;color:var(--text-dim);margin-top:.1rem">Ne voit que son propre contenu et ses propres liens</span>
                </span>
            </label>
        </div>
        <div class="modal-actions">
            <button class="btn btn-ghost" onclick="closeModals()">Annuler</button>
            <button class="btn btn-accent" onclick="createUser()">Créer</button>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="modal-edit">
    <div class="modal">
        <div class="modal-title">Modifier l'utilisateur</div>
        <input type="hidden" id="edit-id">
        <div class="modal-field">
            <label>Nom d'utilisateur</label>
            <input type="text" id="edit-username" disabled style="color:var(--text-dim)">
        </div>
        <div class="modal-field">
            <label>Nouveau mot de passe</label>
            <input type="password" id="edit-password" placeholder="Laisser vide pour ne pas changer">
        </div>
        <div class="modal-field">
            <label>Rôle</label>
            <select id="edit-role">
                <option value="user">Utilisateur</option>
                <option value="admin">Administrateur</option>
            </select>
        </div>
        <div class="modal-field">
            <label style="display:flex;align-items:flex-start;gap:.6rem;cursor:pointer;font-size:.85rem;color:var(--text)">
                <input type="checkbox" id="edit-private" style="margin-top:.2rem;accent-color:var(--accent);width:14px;height:14px;flex-shrink:0;cursor:pointer">
                <span>
                    Mode privé
                    <span style="display:block;font-size:.72rem;color:var(--text-dim);margin-top:.1rem">Ne voit que son propre contenu et ses propres liens</span>
                </span>
            </label>
        </div>
        <div class="modal-actions">
            <button class="btn btn-ghost" onclick="closeModals()">Annuler</button>
            <button class="btn btn-accent" onclick="updateUser()">Enregistrer</button>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal-overlay" id="modal-delete">
    <div class="modal">
        <div class="modal-title">Supprimer l'utilisateur</div>
        <input type="hidden" id="delete-id">
        <p style="font-size:.88rem;color:var(--text);margin-bottom:1rem">
            Supprimer <strong id="delete-username-label"></strong> ?
        </p>
        <label style="display:flex;align-items:center;gap:.5rem;font-size:.82rem;color:var(--red);cursor:pointer">
            <input type="checkbox" id="delete-data-check">
            Supprimer aussi toutes les données (downloads, torrents)
        </label>
        <div class="modal-actions">
            <button class="btn btn-ghost" onclick="closeModals()">Annuler</button>
            <button class="btn btn-danger" onclick="deleteUser()">Supprimer</button>
        </div>
    </div>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

async function api(action, data = null) {
    const opts = { headers: { 'Content-Type': 'application/json' } };
    if (data) {
        opts.method = 'POST';
        opts.body = JSON.stringify({ ...data, csrf_token: CSRF });
    }
    const r = await fetch('/share/admin.php?action=' + action, opts);
    return r.json();
}

function formatBytes(bytes) {
    if (bytes === null || bytes === undefined) return '—';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    if (bytes < 1024 * 1024 * 1024) return (bytes / 1024 / 1024).toFixed(1) + ' MB';
    return (bytes / 1024 / 1024 / 1024).toFixed(2) + ' GB';
}

function esc(s) {
    if (!s) return '';
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function toast(msg, ok = true) {
    document.querySelectorAll('.toast').forEach(t => t.remove());
    const el = document.createElement('div');
    el.className = 'toast ' + (ok ? 'toast-ok' : 'toast-err');
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 4000);
}

function closeModals() {
    document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('open'));
}

document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) closeModals(); });
});

// ── List users ──
async function loadUsers() {
    const res = await api('list_users');
    const wrap = document.getElementById('user-table-wrap');

    if (!res.users || res.users.length === 0) {
        wrap.innerHTML = '<div class="empty-msg">Aucun utilisateur</div>';
        return;
    }

    const sbMode = !!res.seedbox_mode;

    let html = '<table class="user-table"><thead><tr>';
    html += '<th>Utilisateur</th><th class="col-role">Rôle</th>';
    if (sbMode) html += '<th class="col-rtorrent">rtorrent</th>';
    html += '<th class="col-disk">Disque</th><th class="col-date">Créé le</th><th>Actions</th>';
    html += '</tr></thead><tbody>';

    for (const u of res.users) {
        const roleBadge = u.role === 'admin'
            ? '<span class="badge badge-admin">admin</span>'
            : '<span class="badge badge-user">user</span>';
        const privBadge = u.private ? '<span class="badge" style="background:rgba(240,160,48,.12);color:var(--accent);border:1px solid rgba(240,160,48,.2);font-size:.65rem">privé</span>' : '';

        const date = u.created_at ? new Date(u.created_at + 'Z').toLocaleDateString('fr-FR') : '-';
        const uJson = JSON.stringify(u).replace(/'/g, "\\'").replace(/"/g, '&quot;');

        html += '<tr>';
        html += '<td class="username-cell">' + u.username + '</td>';
        html += '<td class="col-role">' + roleBadge + (u.private ? ' ' + privBadge : '') + '</td>';

        if (sbMode) {
            let statusBadge;
            if (!u.has_system_user) {
                statusBadge = '<span class="badge badge-no-system">pas de compte</span>';
            } else if (u.rtorrent_status === 'active') {
                statusBadge = '<span class="badge badge-active">actif</span>';
            } else if (u.rtorrent_status === 'failed') {
                statusBadge = '<span class="badge badge-failed">crash</span>';
            } else {
                statusBadge = '<span class="badge badge-inactive">inactif</span>';
            }
            html += '<td class="col-rtorrent">' + statusBadge + '</td>';
        }

        const diskStr = formatBytes(u.disk_used);
        html += '<td class="col-disk" style="font-size:.8rem;color:var(--text-dim)">' + diskStr + '</td>';

        html += '<td class="col-date" style="font-size:.8rem;color:var(--text-dim)">' + date + '</td>';
        html += '<td><div class="actions">';
        html += '<button class="btn btn-ghost" onclick=\'openEditModal(' + uJson + ')\'>Modifier</button>';

        if (sbMode && u.has_system_user) {
            if (u.rtorrent_status === 'active') {
                html += '<button class="btn btn-ghost" onclick="stopRtorrent(\'' + u.username + '\')">Stop</button>';
            } else {
                html += '<button class="btn btn-green" onclick="restartRtorrent(\'' + u.username + '\')">Start</button>';
            }
        }

        html += '<button class="btn btn-danger" onclick=\'openDeleteModal(' + uJson + ')\'>Suppr.</button>';
        html += '</div></td>';
        html += '</tr>';
    }

    html += '</tbody></table>';
    wrap.innerHTML = html;
}

// ── Create ──
function openCreateModal() {
    document.getElementById('create-username').value = '';
    document.getElementById('create-password').value = '';
    document.getElementById('create-role').value = 'user';
    document.getElementById('create-private').checked = false;
    document.getElementById('modal-create').classList.add('open');
    document.getElementById('create-username').focus();
}

async function createUser() {
    const username = document.getElementById('create-username').value.trim();
    const password = document.getElementById('create-password').value;
    const role = document.getElementById('create-role').value;
    const isPrivate = document.getElementById('create-private').checked;

    if (!username || !password) { toast('Remplissez tous les champs', false); return; }

    const btn = event.target;
    btn.textContent = 'Création...';
    btn.disabled = true;

    const res = await api('create_user', { username, password, role, private: isPrivate });

    btn.textContent = 'Créer';
    btn.disabled = false;

    if (res.error) { toast(res.error, false); return; }
    toast(res.message);
    closeModals();
    loadUsers();
}

// ── Edit ──
function openEditModal(user) {
    document.getElementById('edit-id').value = user.id;
    document.getElementById('edit-username').value = user.username;
    document.getElementById('edit-password').value = '';
    document.getElementById('edit-role').value = user.role;
    document.getElementById('edit-private').checked = !!user.private;
    document.getElementById('modal-edit').classList.add('open');
}

async function updateUser() {
    const id = parseInt(document.getElementById('edit-id').value);
    const password = document.getElementById('edit-password').value;
    const role = document.getElementById('edit-role').value;
    const isPrivate = document.getElementById('edit-private').checked;

    const res = await api('update_user', { id, password, role, private: isPrivate });
    if (res.error) { toast(res.error, false); return; }
    toast(res.message);
    closeModals();
    loadUsers();
}

// ── Delete ──
function openDeleteModal(user) {
    document.getElementById('delete-id').value = user.id;
    document.getElementById('delete-username-label').textContent = user.username;
    document.getElementById('delete-data-check').checked = false;
    document.getElementById('modal-delete').classList.add('open');
}

async function deleteUser() {
    const id = parseInt(document.getElementById('delete-id').value);
    const delete_data = document.getElementById('delete-data-check').checked;

    const res = await api('delete_user', { id, delete_data });
    if (res.error) { toast(res.error, false); return; }
    toast(res.message);
    closeModals();
    loadUsers();
}

// ── rtorrent control ──
async function restartRtorrent(username) {
    toast('Redémarrage de rtorrent@' + username + '...');
    const res = await api('restart_rtorrent', { username });
    if (res.error) { toast(res.error, false); return; }
    toast(res.message, res.status === 'active');
    loadUsers();
}

async function stopRtorrent(username) {
    const res = await api('stop_rtorrent', { username });
    if (res.error) { toast(res.error, false); return; }
    toast(res.message);
    loadUsers();
}

// ── Streams actifs ──
function loadActiveStreams() {
    fetch('ctrl.php?cmd=active_streams')
        .then(r => r.json())
        .then(data => {
            const container = document.getElementById('streams-container');
            const upd      = document.getElementById('streams-last-update');
            const dot      = document.getElementById('streams-live-dot');
            const cnt      = document.getElementById('streams-count');
            if (upd) upd.textContent = new Date().toLocaleTimeString();

            const streams = data.streams || [];
            if (dot) dot.classList.toggle('active', streams.length > 0);
            if (cnt) { cnt.textContent = streams.length; cnt.style.display = streams.length ? '' : 'none'; }

            if (!streams.length) {
                container.innerHTML = `<div class="streams-empty">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4">
                        <rect x="2" y="7" width="20" height="15" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/>
                        <line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/>
                    </svg>
                    Aucun stream actif</div>`;
                return;
            }

            const esc = t => (t||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            const modeClass = { hls: 'smode-hls', transcode: 'smode-transcode', native: 'smode-native' };
            const rows = streams.map(s => {
                const dur = s.duration_sec >= 3600
                    ? Math.floor(s.duration_sec/3600)+'h'+String(Math.floor((s.duration_sec%3600)/60)).padStart(2,'0')+'m'
                    : Math.floor(s.duration_sec/60)+'m'+String(s.duration_sec%60).padStart(2,'0')+'s';
                let cpuHtml;
                if (s.cpu_pct !== null && s.cpu_pct >= 0) {
                    const cls = s.cpu_pct < 30 ? 'scpu-low' : s.cpu_pct < 70 ? 'scpu-mid' : 'scpu-high';
                    cpuHtml = `<span class="${cls}">${s.cpu_pct.toFixed(1)}%</span>`;
                } else {
                    cpuHtml = `<span class="scpu-none">—</span>`;
                }
                const rawName = s.filename.length > 44 ? s.filename.slice(0,41)+'…' : s.filename;
                const fname = esc(rawName);
                const initials = esc((s.user||'?').slice(0,2).toUpperCase());
                const mc = modeClass[s.mode] || 'smode-native';
                return `<div class="stream-row">
                    <div class="stream-avatar">${initials}</div>
                    <div class="stream-info">
                        <div class="stream-filename" title="${esc(s.path || s.filename)}">${fname}</div>
                        <div class="stream-user-label">${s.folder ? esc(s.folder) + ' · ' : ''}${esc(s.user)}</div>
                    </div>
                    <span class="stream-mode-badge ${mc}">${s.mode}</span>
                    <span class="stream-duration">${dur}</span>
                    <div class="stream-cpu">${cpuHtml}</div>
                </div>`;
            }).join('');

            container.innerHTML = `<div class="streams-col-heads">
                <span></span><span>Fichier</span>
                <span style="text-align:right">Mode</span>
                <span style="text-align:right">Durée</span>
                <span style="text-align:right">CPU</span>
            </div>${rows}`;
        })
        .catch(() => {
            const c = document.getElementById('streams-container');
            if (c) c.innerHTML = '<p style="color:var(--red);padding:.8rem 1.2rem;font-size:.8rem">Erreur de chargement</p>';
        });
}

// ── TMDB status & scan ──
var tmdbPolling = false;

async function loadTmdbStatus() {
    try {
        const res = await api('tmdb_status');
        const el = document.getElementById('tmdb-status');
        const barWrap = document.getElementById('tmdb-bar-wrap');
        const bar = document.getElementById('tmdb-bar');
        const barLabel = document.getElementById('tmdb-bar-label');
        const btn = document.getElementById('tmdb-scan-btn');

        const total = res.total || 0;
        const matched = res.matched || 0;
        const pending = res.pending || 0;
        const failed = res.failed || 0;
        const hidden = res.hidden || 0;
        const pct = total > 0 ? Math.round((matched + hidden) / total * 100) : 0;

        const conf = res.confidence || {};
        el.innerHTML =
            '<span style="color:var(--green)">' + matched + ' matchés</span>' +
            '<span>' + hidden + ' masqués</span>' +
            '<span style="color:var(--accent)">' + pending + ' en attente</span>' +
            (failed > 0 ? '<span style="color:var(--red)">' + failed + ' échoués</span>' : '') +
            '<span style="color:var(--text-muted)">' + total + ' total</span>' +
            (matched > 0 ? '<span style="opacity:.5;font-size:.75rem">|</span>' +
                '<span title="verified ≥80%" style="color:#3ddc84;font-size:.75rem">● ' + (conf.high || 0) + '</span>' +
                '<span title="verified 50-79%" style="color:#f0a030;font-size:.75rem">● ' + (conf.medium || 0) + '</span>' +
                (conf.low > 0 ? '<span title="verified <50%" style="color:#e8453c;font-size:.75rem">● ' + conf.low + '</span>' : '') +
                '<span style="color:var(--text-muted);font-size:.75rem">avg ' + (conf.avg || 0) + '%</span>' : '');

        barWrap.style.display = '';
        bar.style.width = pct + '%';
        barLabel.textContent = pct + '% couvert — confiance moyenne ' + (conf.avg || 0) + '%';

        if (res.scanning) {
            btn.textContent = 'Scan en cours...';
            btn.disabled = true;
            if (!tmdbPolling) {
                tmdbPolling = true;
                setTimeout(function poll() {
                    loadTmdbStatus().then(function() {
                        if (document.getElementById('tmdb-scan-btn').disabled) {
                            setTimeout(poll, 3000);
                        } else {
                            tmdbPolling = false;
                        }
                    });
                }, 3000);
            }
        } else {
            btn.textContent = pending > 0 ? 'Scan TMDB (' + pending + ')' : 'Scan TMDB';
            btn.disabled = pending === 0 && failed === 0;
        }
    } catch(e) {}
}

async function launchTmdbScan() {
    const btn = document.getElementById('tmdb-scan-btn');
    btn.textContent = 'Lancement...';
    btn.disabled = true;
    const res = await api('tmdb_scan', {});
    if (res.error) { toast(res.error, false); btn.disabled = false; btn.textContent = 'Scan TMDB'; return; }
    toast(res.message);
    setTimeout(loadTmdbStatus, 2000);
}

// ── AI scan ──
var aiScanPollTimer = null;

async function refreshAiScanBtn() {
    try {
        const res = await fetch('/share/admin.php?action=ai_scan_status');
        const data = await res.json();
        const btn = document.getElementById('ai-scan-btn');
        if (!btn) return;
        if (!data.ai_available) {
            btn.style.display = 'none';
            return;
        }
        if (data.running) {
            btn.textContent = 'IA en cours...';
            btn.disabled = true;
            var panel = document.getElementById('ai-scan-panel');
            if (panel) panel.style.display = '';
            pollAiProgress();
            return;
        }
        if (data.cooldown > 0) {
            const min = Math.ceil(data.cooldown / 60);
            btn.textContent = 'IA — ' + min + ' min';
            btn.disabled = true;
            btn.title = 'Disponible dans ' + min + ' minute' + (min > 1 ? 's' : '');
        } else {
            const lr = data.last_result;
            if (lr && lr.confirmed === 0 && lr.fixed === 0 && lr.matched === 0) {
                btn.textContent = '✓ Déjà vérifié';
                btn.style.opacity = '0.5';
                btn.disabled = false;
                btn.title = 'Dernier scan : rien à corriger. Cliquer pour forcer une nouvelle analyse.';
            } else {
                btn.textContent = 'Vérification IA';
                btn.style.opacity = '';
                btn.disabled = false;
                btn.title = '';
            }
        }
    } catch(e) {}
}

async function launchAiScan() {
    const btn = document.getElementById('ai-scan-btn');
    btn.textContent = 'IA en cours...';
    btn.disabled = true;
    const panel = document.getElementById('ai-scan-panel');
    const log   = document.getElementById('ai-scan-log');
    log.textContent = '';
    panel.style.display = '';
    const res = await api('ai_scan_launch', {});
    if (res.error || !res.ok) {
        appendAiLog('Erreur : ' + (res.message || res.error || 'Échec du lancement'));
        btn.textContent = 'Vérification IA';
        btn.disabled = false;
        return;
    }
    appendAiLog('Scan IA démarré (PID ' + res.pid + ')…\n');
    pollAiLog(0);
    pollAiProgress();
}

function appendAiLog(text) {
    const log = document.getElementById('ai-scan-log');
    if (!log) return;
    log.textContent += text;
    log.scrollTop = log.scrollHeight;
}

var _aiLastSince = '';
var _aiLastChecked = 0;
async function pollAiProgress() {
    try {
        var url = '/share/admin.php?action=ai_scan_status';
        if (_aiLastSince) url += '&since=' + encodeURIComponent(_aiLastSince);
        const res = await fetch(url);
        const data = await res.json();
        // Progress counter
        if (data.ia_checked > _aiLastChecked) {
            appendAiLog('\n── Progression : ' + data.ia_checked + '/' + data.ia_total + ' vérifiés ──\n');
            _aiLastChecked = data.ia_checked;
        }
        // Show recent changes
        if (data.recent && data.recent.length > 0) {
            var confLabel = function(v) {
                if (v >= 90) return '✓ ' + v + '%';
                if (v >= 70) return '~ ' + v + '%';
                return '? ' + v + '%';
            };
            data.recent.reverse().forEach(function(c) {
                var parts = c.path.split('/');
                var short = parts.length > 3 ? '…/' + parts.slice(-2).join('/') : c.path;
                appendAiLog(confLabel(c.verified) + '  ' + (c.title || '?') + '  (' + (c.type || '?') + ')  ← ' + short + '\n');
            });
            _aiLastSince = data.recent[data.recent.length - 1].at || _aiLastSince;
        }
        if (data.running) {
            setTimeout(pollAiProgress, 5000);
        } else {
            appendAiLog('\n── Scan IA terminé ──\n');
            loadTmdbStatus();
            refreshAiScanBtn();
        }
    } catch(e) {}
}

async function pollAiLog(offset) {
    try {
        const res = await fetch('/share/admin.php?action=ai_scan_log&offset=' + offset);
        const data = await res.json();
        if (data.lines && data.lines.length) {
            appendAiLog(data.lines.join('\n') + '\n');
        }
        if (data.done) {
            onAiScanDone();
            return;
        }
        aiScanPollTimer = setTimeout(function() { pollAiLog(data.next_offset); }, 2000);
    } catch(e) {
        aiScanPollTimer = setTimeout(function() { pollAiLog(offset); }, 3000);
    }
}

function onAiScanDone() {
    const btn = document.getElementById('ai-scan-btn');
    btn.disabled = false;
    appendAiLog('\n--- Scan terminé ---');
    loadTmdbStatus();
    setTimeout(refreshAiScanBtn, 1000);
}

function closeAiPanel() {
    clearTimeout(aiScanPollTimer);
    document.getElementById('ai-scan-panel').style.display = 'none';
}


let activityOffset = 0;
let activityDebounceTimer = null;

function activitySearchDebounce() {
    clearTimeout(activityDebounceTimer);
    activityDebounceTimer = setTimeout(reloadActivity, 300);
}

function reloadActivity() {
    activityOffset = 0;
    loadRecentActivity();
}

async function loadRecentActivity() {
    const wrap = document.getElementById('activity-wrap');
    const pager = document.getElementById('activity-pagination');
    const userFilter = document.getElementById('activity-user-filter')?.value ?? '';
    const search = document.getElementById('activity-search')?.value ?? '';
    wrap.innerHTML = '<div class="empty-msg">Chargement…</div>';
    pager.innerHTML = '';
    try {
        const res = await api('recent_activity', { user: userFilter, search, offset: activityOffset });
        if (!res.logs || res.logs.length === 0) {
            wrap.innerHTML = '<div class="empty-msg">Aucune activité enregistrée.</div>';
            return;
        }
        let html = '<table class="user-table"><thead><tr>';
        html += '<th>Fichier</th><th class="col-token">Token</th><th class="col-user">User</th><th class="col-ip">IP</th><th>Date</th>';
        html += '</tr></thead><tbody>';
        for (const log of res.logs) {
            const d = log.downloaded_at ? new Date(log.downloaded_at + 'Z').toLocaleString('fr-FR') : '-';
            html += '<tr>';
            html += '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(log.name) + '">' + esc(log.name) + '</td>';
            html += '<td class="col-token"><a href="/dl/' + esc(log.token) + '" target="_blank" style="color:var(--blue);font-size:.8rem">' + esc(log.token) + '</a></td>';
            html += '<td class="col-user" style="font-size:.8rem;color:var(--text-dim)">' + (log.created_by ? esc(log.created_by) : '—') + '</td>';
            html += '<td class="col-ip" style="font-family:monospace;font-size:.8rem">' + esc(log.ip) + '</td>';
            html += '<td style="font-size:.78rem;color:var(--text-dim)">' + esc(d) + '</td>';
            html += '</tr>';
        }
        html += '</tbody></table>';
        wrap.innerHTML = html;

        const total = res.total ?? 0;
        const limit = res.limit ?? 25;
        const page = Math.floor(activityOffset / limit) + 1;
        const pages = Math.ceil(total / limit);
        let pagerHtml = '<span style="margin-right:auto">' + total + ' téléchargement' + (total > 1 ? 's' : '') + '</span>';
        if (pages > 1) {
            pagerHtml +=
                '<button class="pager-btn" onclick="activityPage(-1)"' + (page <= 1 ? ' disabled' : '') + '>← Préc.</button>' +
                '<span>Page ' + page + ' / ' + pages + '</span>' +
                '<button class="pager-btn" onclick="activityPage(1)"' + (page >= pages ? ' disabled' : '') + '>Suiv. →</button>';
        }
        pager.innerHTML = pagerHtml;
    } catch (_) {
        wrap.innerHTML = '<div class="empty-msg">Erreur de chargement.</div>';
    }
}

function activityPage(dir) {
    activityOffset = Math.max(0, activityOffset + dir * 15);
    loadRecentActivity();
}

async function populateActivityUserFilter() {
    const sel = document.getElementById('activity-user-filter');
    if (!sel) return;
    const res = await api('list_users');
    if (!res.users) return;
    for (const u of res.users) {
        const opt = document.createElement('option');
        opt.value = u.username;
        opt.textContent = u.username;
        sel.appendChild(opt);
    }
}

// ── Mon compte ───────────────────────────────────────────────────────────────
async function soumettreChangementMdp() {
    const btn = document.getElementById('mdp-submit');
    const errDiv = document.getElementById('mdp-error');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    btn.disabled = true;
    errDiv.style.display = 'none';
    try {
        const resp = await fetch('/share/ctrl.php?cmd=change_password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                current_password: document.getElementById('mdp-actuel').value,
                new_password: document.getElementById('mdp-nouveau').value,
                confirm_password: document.getElementById('mdp-confirm').value,
                csrf_token: csrfToken
            })
        });
        const data = await resp.json();
        if (data.error) {
            errDiv.textContent = data.error;
            errDiv.style.color = 'var(--red, #e8453c)';
            errDiv.style.display = 'block';
        } else {
            errDiv.style.color = 'var(--green, #3ddc84)';
            errDiv.textContent = 'Mot de passe modifié.';
            errDiv.style.display = 'block';
            document.getElementById('mdp-actuel').value = '';
            document.getElementById('mdp-nouveau').value = '';
            document.getElementById('mdp-confirm').value = '';
            setTimeout(() => {
                errDiv.style.display = 'none';
                errDiv.style.color = 'var(--red, #e8453c)';
            }, 1800);
        }
    } catch (_) {
        errDiv.textContent = 'Erreur de connexion';
        errDiv.style.color = 'var(--red, #e8453c)';
        errDiv.style.display = 'block';
    } finally {
        btn.disabled = false;
    }
}

// ── Événements système ──────────────────────────────────────────────────────
let eventsOffset = 0;

function reloadEvents() {
    eventsOffset = 0;
    loadEvents();
}

function eventsPage(dir) {
    eventsOffset = Math.max(0, eventsOffset + dir * 15);
    loadEvents();
}

async function loadEvents() {
    const wrap  = document.getElementById('events-wrap');
    const pager = document.getElementById('events-pagination');
    const typeFilter = document.getElementById('events-type-filter')?.value ?? '';
    wrap.innerHTML  = '<div class="empty-msg">Chargement…</div>';
    pager.innerHTML = '';
    try {
        const res = await api('activity_events', { type_filter: typeFilter, offset: eventsOffset });
        if (!res.logs || res.logs.length === 0) {
            wrap.innerHTML = '<div class="empty-msg">Aucun événement enregistré.</div>';
            return;
        }

        const badgeStyle = {
            login_ok:           'background:#1a3a2a;color:#3ddc84',
            login_fail:         'background:#3a1a1a;color:#e8453c',
            link_create:        'background:#1a2a3a;color:#4a9eff',
            link_delete:        'background:#3a2a1a;color:#f0a030',
            admin_create_user:  'background:#2a1a3a;color:#c084fc',
            admin_edit_user:    'background:#2a1a3a;color:#c084fc',
            admin_delete_user:  'background:#3a1a2a;color:#f472b6',
        };

        let html = '<table class="user-table"><thead><tr>';
        html += '<th>Type</th><th class="col-user">Utilisateur</th><th class="col-ip">IP</th><th>Détails</th><th>Date</th>';
        html += '</tr></thead><tbody>';
        for (const log of res.logs) {
            const d  = log.created_at ? new Date(log.created_at + 'Z').toLocaleString('fr-FR') : '-';
            const bs = badgeStyle[log.event_type] ?? 'background:#222;color:#aaa';
            html += '<tr>';
            html += '<td><span style="' + bs + ';font-size:.72rem;padding:.15rem .45rem;border-radius:4px;white-space:nowrap">' + esc(log.event_type) + '</span></td>';
            html += '<td class="col-user" style="font-size:.8rem">' + esc(log.username ?? '—') + '</td>';
            html += '<td class="col-ip" style="font-family:monospace;font-size:.78rem">' + esc(log.ip ?? '—') + '</td>';
            html += '<td style="font-size:.8rem;color:var(--text-dim);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(log.details ?? '') + '">' + esc(log.details ?? '—') + '</td>';
            html += '<td style="font-size:.78rem;color:var(--text-dim)">' + esc(d) + '</td>';
            html += '</tr>';
        }
        html += '</tbody></table>';
        wrap.innerHTML = html;

        const total = res.total ?? 0;
        const limit = res.limit ?? 15;
        const page  = Math.floor(eventsOffset / limit) + 1;
        const pages = Math.ceil(total / limit);
        let pagerHtml = '<span style="margin-right:auto">' + total + ' événement' + (total > 1 ? 's' : '') + '</span>';
        if (pages > 1) {
            pagerHtml +=
                '<button class="pager-btn" onclick="eventsPage(-1)"' + (page <= 1 ? ' disabled' : '') + '>← Préc.</button>' +
                '<span>Page ' + page + ' / ' + pages + '</span>' +
                '<button class="pager-btn" onclick="eventsPage(1)"' + (page >= pages ? ' disabled' : '') + '>Suiv. →</button>';
        }
        pager.innerHTML = pagerHtml;
    } catch (e) {
        wrap.innerHTML = '<div class="empty-msg" style="color:var(--red)">Erreur de chargement.</div>';
    }
}

// ── Accordéons ──────────────────────────────────────────────────────────────
function toggleAccordion(id) {
    const body    = document.getElementById('accordion-body-' + id);
    const chevron = document.getElementById('chevron-' + id);
    if (!body) return;
    const isOpen = body.style.display !== 'none';
    body.style.display = isOpen ? 'none' : '';
    chevron.classList.toggle('open', !isOpen);
    localStorage.setItem('accordion_' + id, isOpen ? '0' : '1');
}

function initAccordion(id, defaultOpen) {
    const body    = document.getElementById('accordion-body-' + id);
    const chevron = document.getElementById('chevron-' + id);
    if (!body) return;
    const stored = localStorage.getItem('accordion_' + id);
    const open   = stored !== null ? stored === '1' : defaultOpen;
    body.style.display = open ? '' : 'none';
    chevron.classList.toggle('open', open);
}

// ── Tabs ────────────────────────────────────────────────────────────────────
let activityLoaded = false;

function switchTab(name) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name)?.classList.add('active');
    document.querySelector(`.tab-btn[onclick="switchTab('${name}')"]`)?.classList.add('active');
    location.hash = name;
    if (name === 'activite' && !activityLoaded) {
        activityLoaded = true;
        populateActivityUserFilter().then(() => loadRecentActivity());
        loadEvents();
    }
    if (name === 'statistiques' && typeof seedInit === 'function') {
        seedInit();
    }
}

// Init
const _urlTab = location.hash.slice(1);
if (_urlTab && document.getElementById('tab-' + _urlTab)) {
    switchTab(_urlTab);
}
<?php if ($isAdmin): ?>
loadUsers();
loadTmdbStatus();
refreshAiScanBtn();
loadActiveStreams();
setInterval(loadActiveStreams, 10000);
<?php endif; ?>
initAccordion('activite-recente', false);
initAccordion('evenements-systeme', false);

// ── Seed Stats ────────────────────────────────────────────────────────────────
<?php if ($isAdmin): ?>
(function() {
    const BASE = 'api/torrent_stats.php';
    let seedRange  = localStorage.getItem('seed_range') || '7d';
    let seedSortBy = 'total_up';
    let seedData   = [];
    let lifecycleChart = null;
    let torrentChart   = null;
    window.seedModalHash = null;

    function fmtBytes(b) {
        if (b >= 1e12) return (b/1e12).toFixed(2) + ' TB';
        if (b >= 1e9)  return (b/1e9).toFixed(2) + ' GB';
        if (b >= 1e6)  return (b/1e6).toFixed(1) + ' MB';
        return (b/1024).toFixed(0) + ' KB';
    }
    function fmtAge(ts) {
        if (!ts) return '—';
        const d = Math.floor((Date.now()/1000 - ts) / 86400);
        return d < 1 ? '<1j' : d + 'j';
    }

    function seedSetRange(r) {
        seedRange = r;
        localStorage.setItem('seed_range', r);
        document.querySelectorAll('.seed-range-btn').forEach(b =>
            b.classList.toggle('active', b.dataset.range === r));
        loadSeedList();
        renderLifecycle();
    }
    window.seedSetRange = seedSetRange;

    function seedSort(col) {
        seedSortBy = col;
        renderSeedTable();
    }
    window.seedSort = seedSort;

    const SEED_PER_PAGE = 20;
    let seedPage = 0;

    function renderSeedTable() {
        // Filtrer les torrents sans upload sur la période
        const active = [...seedData].filter(t => t.total_up > 0);
        const sorted = active.sort((a, b) => (b[seedSortBy] ?? 0) - (a[seedSortBy] ?? 0));
        const tbody = document.getElementById('seed-tbody');
        if (!sorted.length) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:1.5rem">Aucune donnée — attendez quelques runs du cron (5 min)</td></tr>';
            renderSeedPager(0, 0);
            return;
        }
        const totalPages = Math.ceil(sorted.length / SEED_PER_PAGE);
        if (seedPage >= totalPages) seedPage = totalPages - 1;
        const start = seedPage * SEED_PER_PAGE;
        const page  = sorted.slice(start, start + SEED_PER_PAGE);
        tbody.innerHTML = page.map(t => {
            const locBadge = t.location === 'nvme'
                ? '<span class="badge-admin" style="font-size:.7rem">NVMe</span>'
                : '<span class="badge-user"  style="font-size:.7rem">RAID</span>';
            return `<tr style="cursor:pointer" onclick="openSeedModal('${t.hash}','${t.name.replace(/'/g,"\\'")}')">
                <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${t.name}">${t.name}</td>
                <td style="text-align:center">${locBadge}</td>
                <td style="text-align:right">${fmtBytes(t.total_up)}</td>
                <td style="text-align:right">${t.avg_rate_kbps} KB/s</td>
                <td style="text-align:right">${fmtBytes(t.size_bytes)}</td>
                <td style="text-align:right">${fmtAge(t.finished_ts)}</td>
            </tr>`;
        }).join('');
        renderSeedPager(sorted.length, totalPages);
    }

    function renderSeedPager(total, totalPages) {
        let el = document.getElementById('seed-pager');
        if (!el) return;
        if (totalPages <= 1) { el.innerHTML = total ? `<span style="font-size:.75rem;color:var(--text-dim)">${total} torrents actifs</span>` : ''; return; }
        const btns = [];
        for (let i = 0; i < totalPages; i++) {
            const cls = i === seedPage ? 'active' : '';
            btns.push(`<button class="btn btn-ghost ${cls}" style="font-size:.72rem;padding:.15rem .5rem;min-width:1.6rem" onclick="seedGoPage(${i})">${i + 1}</button>`);
        }
        el.innerHTML = `<span style="font-size:.75rem;color:var(--text-dim)">${total} torrents actifs</span><span style="display:flex;gap:.25rem">${btns.join('')}</span>`;
    }

    window.seedGoPage = function(p) { seedPage = p; renderSeedTable(); };

    function loadSeedList() {
        document.getElementById('seed-loading').style.display = '';
        fetch(`${BASE}?action=list&range=${seedRange}`)
            .then(r => r.json())
            .then(j => {
                seedData = j.data || [];
                renderSeedTable();
                document.getElementById('seed-loading').style.display = 'none';
            })
            .catch(() => { document.getElementById('seed-loading').style.display = 'none'; });

        fetch(`${BASE}?action=lifecycle`)
            .then(r => r.json())
            .then(j => renderLifecycle(j.data || []));
    }

    let lifecycleAllData = [];
    let seedMetric = localStorage.getItem('seed_metric') || 'speed';

    function seedSetMetric(m) {
        seedMetric = m;
        localStorage.setItem('seed_metric', m);
        document.querySelectorAll('.seed-metric-btn').forEach(b =>
            b.classList.toggle('active', b.dataset.metric === m));
        renderLifecycle();
    }
    window.seedSetMetric = seedSetMetric;

    function renderLifecycle(data) {
        if (data) lifecycleAllData = data;
        const ctx = document.getElementById('seed-lifecycle-chart');
        if (!ctx) return;
        if (lifecycleChart) lifecycleChart.destroy();
        const rangeDays = parseInt(seedRange) || 30;
        const isSpeed = seedMetric === 'speed';
        const filtered = lifecycleAllData.filter(d => d.age_days != null && d.age_days <= rangeDays);
        const nvme = filtered.filter(d => d.location === 'nvme');
        const raid = filtered.filter(d => d.location !== 'nvme');
        const mkPts = arr => arr.map(d => ({
            x: d.age_days,
            y: isSpeed ? d.avg_rate_kbps : d.total_up / 1e9,
            label: d.name,
            raw_up: d.total_up,
            raw_rate: d.avg_rate_kbps
        }));
        const desc = document.getElementById('seed-chart-desc');
        if (desc) desc.textContent = isSpeed
            ? 'Cycle de vie — âge vs vitesse moyenne (chaque point = un torrent)'
            : 'Cycle de vie — âge vs volume uploadé (chaque point = un torrent)';
        const yLabel = isSpeed ? 'Moy KB/s' : 'Upload (GB)';
        const tooltipFn = isSpeed
            ? ctx => `${ctx.raw.label}: ${ctx.raw.raw_rate} KB/s (${ctx.raw.x}j)`
            : ctx => `${ctx.raw.label}: ${fmtBytes(ctx.raw.raw_up)} (${ctx.raw.x}j)`;
        lifecycleChart = new Chart(ctx, {
            type: 'scatter',
            data: {
                datasets: [
                    { label: 'NVMe', data: mkPts(nvme), backgroundColor: 'rgba(240,160,48,.85)', pointRadius: 6 },
                    { label: 'RAID', data: mkPts(raid), backgroundColor: 'rgba(100,140,200,.7)', pointRadius: 5 },
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                animation: false,
                plugins: {
                    legend: { labels: { color: '#aaa', font: { size: 11 } } },
                    tooltip: { callbacks: { label: tooltipFn } }
                },
                scales: {
                    x: { title: { display: true, text: 'Âge (jours)', color: '#777' }, ticks: { color: '#777' }, grid: { color: '#222' }, max: rangeDays },
                    y: { title: { display: true, text: yLabel, color: '#777' }, ticks: { color: '#777' }, grid: { color: '#222' }, min: 0 }
                }
            }
        });
    }

    window.openSeedModal = function(hash, name) {
        window.seedModalHash = hash;
        document.getElementById('seed-modal-title').textContent = name;
        document.getElementById('seed-modal').style.display = 'flex';
        document.querySelectorAll('.seed-chart-range-btn').forEach(b =>
            b.classList.toggle('active', b.dataset.range === seedRange));
        seedLoadChart(hash, seedRange);
    };
    window.closeSeedModal = function() {
        document.getElementById('seed-modal').style.display = 'none';
    };

    window.seedLoadChart = function(hash, range) {
        if (!hash) return;
        document.querySelectorAll('.seed-chart-range-btn').forEach(b =>
            b.classList.toggle('active', b.dataset.range === range));
        fetch(`${BASE}?action=chart&hash=${hash}&range=${range}`)
            .then(r => r.json())
            .then(j => renderTorrentChart(j));
    };

    function renderTorrentChart(j) {
        const ctx = document.getElementById('seed-torrent-chart');
        if (!ctx) return;
        if (torrentChart) torrentChart.destroy();
        const pts  = j.points || [];
        const meta = j.meta   || {};
        const totalUp = pts.reduce((s, p) => s + p.up_bytes, 0);
        document.getElementById('seed-modal-meta').textContent =
            `${fmtBytes(meta.size_bytes || 0)} · ${fmtBytes(totalUp)} uploadé sur la période`;
        const labels = pts.map(p => {
            const d = new Date(p.ts * 1000);
            return j.bucket >= 86400
                ? d.toLocaleDateString('fr-FR', {day:'2-digit', month:'2-digit'})
                : d.toLocaleString('fr-FR', {day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit'});
        });
        const upData = pts.map(p => +(p.up_bytes / 1048576).toFixed(2)); // MB
        const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 240);
        gradient.addColorStop(0, 'rgba(240,160,48,.35)');
        gradient.addColorStop(1, 'rgba(240,160,48,.02)');
        torrentChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'Upload (MB)',
                    data: upData,
                    borderColor: '#f0a030',
                    backgroundColor: gradient,
                    borderWidth: 1.5,
                    pointRadius: pts.length > 72 ? 0 : 2,
                    fill: true,
                    tension: 0.3,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                animation: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: c => fmtBytes(c.raw * 1048576) + ' uploadé' } }
                },
                scales: {
                    x: { ticks: { color: '#777', maxTicksLimit: 10, font: { size: 10 } }, grid: { color: '#1a1a1a' } },
                    y: { ticks: { color: '#777', font: { size: 10 } }, grid: { color: '#222' }, min: 0 }
                }
            }
        });
    }

    // Init — charge les données au premier affichage de l'onglet
    document.querySelectorAll('.seed-range-btn').forEach(b =>
        b.classList.toggle('active', b.dataset.range === seedRange));
    document.querySelectorAll('.seed-metric-btn').forEach(b =>
        b.classList.toggle('active', b.dataset.metric === seedMetric));
    let seedLoaded = false;
    window.seedInit = function() {
        if (seedLoaded) return;
        seedLoaded = true;
        loadSeedList();
    };

    // Si l'onglet est déjà actif au moment où ce script s'exécute (navigation directe via #statistiques)
    if (document.getElementById('tab-statistiques')?.classList.contains('active')) {
        window.seedInit();
    }
})();
<?php endif; ?>
</script>
</body>
</html>
