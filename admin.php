<?php
/**
 * ShareBox - Admin panel (user management + seedbox control)
 * Requires admin role.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

require_auth();

if (($_SESSION['sharebox_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo 'Accès réservé aux administrateurs.';
    exit;
}

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
                $db = get_db();
                $total = (int)$db->query("SELECT COUNT(*) FROM folder_posters")->fetchColumn();
                $matched = (int)$db->query("SELECT COUNT(*) FROM folder_posters WHERE poster_url IS NOT NULL AND poster_url != '__none__'")->fetchColumn();
                $hidden = (int)$db->query("SELECT COUNT(*) FROM folder_posters WHERE poster_url = '__none__'")->fetchColumn();
                $pending = (int)$db->query("SELECT COUNT(*) FROM folder_posters WHERE poster_url IS NULL AND (verified IS NULL OR verified >= 0)")->fetchColumn();
                $failed = (int)$db->query("SELECT COUNT(*) FROM folder_posters WHERE poster_url IS NULL AND verified = -1")->fetchColumn();
                $scanning = file_exists(sys_get_temp_dir() . '/sharebox_tmdb_scan.lock');
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
                $lockFile = sys_get_temp_dir() . '/sharebox_tmdb_scan.lock';
                if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 300) {
                    echo json_encode(['ok' => false, 'message' => 'Scan déjà en cours']);
                    break;
                }
                touch($lockFile);
                $script = realpath(__DIR__ . '/tools/tmdb-worker.php');
                $logFile = __DIR__ . '/data/tmdb-worker.log';
                $cmd = sprintf(
                    '/usr/bin/php %s --cron >> %s 2>&1 & echo $!',
                    escapeshellarg($script),
                    escapeshellarg($logFile)
                );
                $pid = trim(shell_exec($cmd));
                echo json_encode(['ok' => true, 'message' => 'Scan TMDB lancé', 'pid' => $pid]);
                break;

            case 'purge_expired':
                $db = get_db();
                require_once __DIR__ . '/functions.php';
                $deleted = purge_expired_links($db);
                echo json_encode(['ok' => true, 'deleted' => $deleted]);
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
    <link rel="icon" type="image/svg+xml" href="/share/favicon.svg">
    <link rel="stylesheet" href="/share/style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <title>ShareBox — Admin</title>
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --bg-void: #06080c;
            --bg-deep: #0c0e14;
            --bg-card: #111420;
            --bg-input: #0d1018;
            --bg-row-hover: #161a26;
            --accent: #f0a030;
            --accent-dim: rgba(240, 160, 48, .08);
            --accent-glow: rgba(240, 160, 48, .15);
            --text: #d8dce8;
            --text-dim: #5a6078;
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
                linear-gradient(rgba(255,255,255,.015) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.015) 1px, transparent 1px);
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
            color: var(--text-muted);
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
    </style>
</head>
<body>

<div class="app">
    <?php $header_subtitle = 'Administration'; $header_back = true; include __DIR__ . '/header.php'; ?>

    <div class="page-title">Gestion des utilisateurs</div>
    <div class="page-sub">Gérer les utilisateurs<?= $seedboxMode ? ' (rtorrent + ruTorrent + SFTP + ShareBox)' : '' ?></div>

    <div class="card" style="margin-bottom:1.5rem">
        <div class="card-header">
            <div class="card-title">TMDB Posters</div>
            <button class="btn btn-accent" id="tmdb-scan-btn" onclick="launchTmdbScan()">Scan TMDB</button>
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
        </div>
    </div>

    <div class="card" style="margin-bottom:1.5rem">
        <div class="card-header">
            <div class="card-title">Maintenance</div>
        </div>
        <div style="padding:1rem 1.4rem;display:flex;gap:.8rem;align-items:center;flex-wrap:wrap">
            <button class="btn btn-ghost" id="purge-btn" onclick="purgeExpired()">Purger les liens expirés</button>
            <span id="purge-result" style="font-size:.82rem;color:var(--text-dim)"></span>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="card-title">Utilisateurs</div>
            <button class="btn btn-accent" onclick="openCreateModal()">+ Nouvel utilisateur</button>
        </div>
        <div id="user-table-wrap">
            <div class="empty-msg">Chargement...</div>
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
            <input type="text" id="edit-username" disabled style="opacity:.5">
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
    html += '<th>Utilisateur</th><th>Rôle</th>';
    if (sbMode) html += '<th>rtorrent</th>';
    html += '<th>Disque</th><th>Créé le</th><th>Actions</th>';
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
        html += '<td>' + roleBadge + (u.private ? ' ' + privBadge : '') + '</td>';

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
            html += '<td>' + statusBadge + '</td>';
        }

        const diskStr = formatBytes(u.disk_used);
        html += '<td style="font-size:.8rem;color:var(--text-dim)">' + diskStr + '</td>';

        html += '<td style="font-size:.8rem;color:var(--text-dim)">' + date + '</td>';
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

async function purgeExpired() {
    const btn = document.getElementById('purge-btn');
    const result = document.getElementById('purge-result');
    btn.disabled = true;
    btn.textContent = 'Purge…';
    try {
        const res = await api('purge_expired', {});
        if (res.error) { toast(res.error, false); }
        else {
            const msg = res.deleted === 0 ? 'Aucun lien expiré.' : res.deleted + ' lien(s) supprimé(s).';
            result.textContent = msg;
            toast(msg);
        }
    } finally {
        btn.disabled = false;
        btn.textContent = 'Purger les liens expirés';
    }
}

// Init
loadUsers();
loadTmdbStatus();
</script>
</body>
</html>
