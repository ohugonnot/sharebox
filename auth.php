<?php
/**
 * Authentification PHP pour ShareBox
 * - require_auth() : redirige vers login si pas connecté
 * - Brute-force protection : AUTH_LOGIN_MAX_ATTEMPTS tentatives max par IP / 15 min (share_attempt)
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

function is_admin(): bool {
    return is_logged_in() && (($_SESSION['sharebox_role'] ?? '') === 'admin');
}

/** Client key for rate limiting: REMOTE_ADDR only (forwarded headers are spoofable); IPv6 is cut to its /64. */
function rate_limit_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $bin = @inet_pton($ip);
    if ($bin !== false && strlen($bin) === 16 && !str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
        return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8));
    }
    return $ip;
}

/** Counts one password attempt for (token, ip) in a fixed window; returns the new count. */
function share_attempt(string $token, string $ip): int {
    $db = get_db();
    $now = time();
    $cutoff = $now - AUTH_LOCKOUT_WINDOW;
    // One transaction: the write lock is held until the read, so a parallel request cannot bump the count in between.
    $db->beginTransaction();
    try {
        $db->prepare("
            INSERT INTO share_attempts (token, ip, count, first_ts) VALUES (:t, :ip, 1, :now)
            ON CONFLICT(token, ip) DO UPDATE SET
                count = CASE WHEN first_ts <= :cutoff THEN 1 ELSE count + 1 END,
                first_ts = CASE WHEN first_ts <= :cutoff THEN :now ELSE first_ts END
        ")->execute([':t' => $token, ':ip' => $ip, ':now' => $now, ':cutoff' => $cutoff]);
        $stmt = $db->prepare("SELECT count FROM share_attempts WHERE token = :t AND ip = :ip");
        $stmt->execute([':t' => $token, ':ip' => $ip]);
        $count = (int)$stmt->fetchColumn();
        $stmt->closeCursor(); // an open cursor makes later writes fail with "database is locked"
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    if (mt_rand(0, 99) === 0) {
        $db->prepare("DELETE FROM share_attempts WHERE first_ts <= :cutoff")->execute([':cutoff' => $cutoff]);
    }
    return $count;
}

function clear_share_attempts(string $token, string $ip): void {
    get_db()->prepare("DELETE FROM share_attempts WHERE token = :t AND ip = :ip")->execute([':t' => $token, ':ip' => $ip]);
}

/**
 * Ensure at least one admin user exists (first-run bootstrap).
 * Uses SHAREBOX_ADMIN_USER / SHAREBOX_ADMIN_PASS env vars, or defaults to admin + random password.
 * Returns the generated password, or null when none was generated.
 */
function ensure_admin_exists(): ?string {
    $db = get_db();
    $count = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count === 0) {
        $user = getenv('SHAREBOX_ADMIN_USER') ?: 'admin';
        $pass = getenv('SHAREBOX_ADMIN_PASS') ?: bin2hex(random_bytes(8));
        $db->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')")
           ->execute([$user, password_hash($pass, PASSWORD_BCRYPT)]);
        if (!getenv('SHAREBOX_ADMIN_PASS')) {
            error_log("ShareBox: admin user '$user' created with password: $pass");
            return $pass;
        }
    }
    return null;
}
