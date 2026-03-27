<?php
/**
 * Gestion de la base de données SQLite
 * La BDD est créée automatiquement au premier appel
 */

require_once __DIR__ . '/config.php';

/**
 * Retourne une connexion PDO vers la base SQLite
 * Crée les tables si elles n'existent pas encore
 */
function get_db(): PDO {
    static $db = null;
    if ($db !== null) return $db;

    $db = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // WAL : lectures concurrentes sans bloquer les écritures
    $statements = [
        'PRAGMA journal_mode=WAL',
        'PRAGMA busy_timeout=3000',
    ];
    foreach ($statements as $s) $db->query($s);

    // Crée les tables si elles n'existent pas
    $db->query("
        CREATE TABLE IF NOT EXISTS probe_cache (
            path TEXT NOT NULL PRIMARY KEY,
            mtime INTEGER NOT NULL,
            result TEXT NOT NULL
        )
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT NOT NULL UNIQUE,
            path TEXT NOT NULL,
            type TEXT NOT NULL DEFAULT 'file',
            name TEXT NOT NULL,
            password_hash TEXT DEFAULT NULL,
            expires_at TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            download_count INTEGER NOT NULL DEFAULT 0
        )
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS net_speed (
            ts INTEGER NOT NULL,
            upload REAL NOT NULL,
            download REAL NOT NULL
        )
    ");
    $db->query("CREATE INDEX IF NOT EXISTS idx_net_speed_ts ON net_speed(ts)");
    $db->query("CREATE INDEX IF NOT EXISTS idx_links_expires ON links(expires_at)");
    $db->query("CREATE INDEX IF NOT EXISTS idx_links_created ON links(created_at DESC)");

    // ── Migrations one-shot via PRAGMA user_version ─────────────────────────
    $version = (int)$db->query('PRAGMA user_version')->fetchColumn();

    if ($version < 1) {
        // v1 : supprimer password_plain si elle existe (ancienne colonne insecure)
        $cols = array_column($db->query("PRAGMA table_info(links)")->fetchAll(), 'name');
        if (in_array('password_plain', $cols, true)) {
            // SQLite < 3.35 ne supporte pas DROP COLUMN — on vide la colonne
            $db->prepare("UPDATE links SET password_plain = NULL WHERE password_plain IS NOT NULL")->execute();
        }
        $db->query('PRAGMA user_version = 1');
    }

    return $db;
}

/**
 * Purge les entrées probe_cache orphelines (fichiers plus partagés).
 * Appelé périodiquement (cron ou admin), pas à chaque requête.
 */
function purge_probe_cache(PDO $db): int {
    $stmt = $db->prepare("DELETE FROM probe_cache WHERE NOT EXISTS (
        SELECT 1 FROM links
        WHERE probe_cache.path = links.path
           OR probe_cache.path LIKE rtrim(links.path, '/') || '/%'
    )");
    $stmt->execute();
    return $stmt->rowCount();
}
