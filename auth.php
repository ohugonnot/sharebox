<?php
/**
 * Authentification PHP pour ShareBox
 * - require_auth() : redirige vers login si pas connecté
 * - Brute-force protection : 5 tentatives max par IP / 15 min
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function require_auth(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // ── Trusted header auth (Apache Digest, reverse proxy, etc.) ────────
    if (defined('TRUSTED_AUTH_HEADER') && TRUSTED_AUTH_HEADER !== '') {
        $remoteUser = $_SERVER[TRUSTED_AUTH_HEADER] ?? '';
        if ($remoteUser !== '') {
            // Sanitize username
            $remoteUser = preg_replace('/[^a-z0-9_-]/i', '', $remoteUser);

            // If session already set for this user, nothing to do
            if (($_SESSION['sharebox_user'] ?? '') === $remoteUser) {
                return;
            }

            // Auto-provision user in DB if not exists
            $db = get_db();
            $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$remoteUser]);
            $user = $stmt->fetch();

            $isAdmin = defined('ADMIN_USER') && $remoteUser === ADMIN_USER;

            if (!$user) {
                // Auto-create user — admin if ADMIN_USER matches, else regular user
                $role = $isAdmin ? 'admin' : 'user';
                $db->prepare("INSERT INTO users (username, password_hash, role, private) VALUES (?, ?, ?, 0)")
                   ->execute([$remoteUser, password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT), $role]);
                $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
                $stmt->execute([$remoteUser]);
                $user = $stmt->fetch();
            }

            // Create session
            session_regenerate_id(true);
            $_SESSION['sharebox_user'] = $user['username'];
            $_SESSION['sharebox_role'] = $user['role'];
            $_SESSION['sharebox_private'] = (int)($user['private'] ?? 0);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            log_activity('login_trusted', $user['username'], $_SERVER['REMOTE_ADDR'] ?? null,
                        'header=' . TRUSTED_AUTH_HEADER);
            return;
        }
    }

    // ── Standard session check ──────────────────────────────────────────
    if (empty($_SESSION['sharebox_user'])) {
        header('Location: /share/login.php');
        exit;
    }
}

function is_logged_in(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Trusted header: auto-provision session if not set
    if (defined('TRUSTED_AUTH_HEADER') && TRUSTED_AUTH_HEADER !== '') {
        $remoteUser = $_SERVER[TRUSTED_AUTH_HEADER] ?? '';
        if ($remoteUser !== '' && empty($_SESSION['sharebox_user'])) {
            require_auth(); // This will create the session
            return true;
        }
    }

    return !empty($_SESSION['sharebox_user']);
}

function get_current_user_name(): ?string {
    return $_SESSION['sharebox_user'] ?? null;
}

/**
 * Brute-force rate limiting (file-based, simple)
 */
function check_rate_limit(string $ip): bool {
    $file = sys_get_temp_dir() . '/sharebox_login_' . md5($ip);
    if (!file_exists($file)) return true;

    $data = json_decode(file_get_contents($file), true);
    if (!$data) return true;

    // Reset after 15 minutes
    if (time() - $data['first'] > 900) {
        unlink($file);
        return true;
    }

    return $data['count'] < 5;
}

function record_failed_attempt(string $ip): void {
    $file = sys_get_temp_dir() . '/sharebox_login_' . md5($ip);
    $data = ['count' => 1, 'first' => time()];

    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?: $data;
        $data['count']++;
    }

    file_put_contents($file, json_encode($data));
}

function clear_rate_limit(string $ip): void {
    $file = sys_get_temp_dir() . '/sharebox_login_' . md5($ip);
    if (file_exists($file)) unlink($file);
}

/**
 * Ensure at least one admin user exists (first-run bootstrap).
 * Uses SHAREBOX_ADMIN_USER / SHAREBOX_ADMIN_PASS env vars, or defaults to admin + random password.
 */
function ensure_admin_exists(): void {
    $db = get_db();
    $count = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count === 0) {
        $user = getenv('SHAREBOX_ADMIN_USER') ?: 'admin';
        $pass = getenv('SHAREBOX_ADMIN_PASS') ?: bin2hex(random_bytes(8));
        $db->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')")
           ->execute([$user, password_hash($pass, PASSWORD_BCRYPT)]);
        if (!getenv('SHAREBOX_ADMIN_PASS')) {
            error_log("ShareBox: admin user '$user' created with password: $pass");
        }
    }
}
