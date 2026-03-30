<?php
/**
 * Gestion de la base de données SQLite
 * La BDD est créée automatiquement au premier appel
 */

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

/**
 * Retourne une connexion PDO vers la base SQLite
 * Crée les tables si elles n'existent pas encore
 */
function get_db(): PDO {
    static $db = null;
    if ($db !== null) return $db;

    if (!defined('DB_PATH')) {
        throw new RuntimeException('DB_PATH not defined — config.php not loaded');
    }

    $dbFile = DB_PATH;
    $backupFile = dirname($dbFile) . '/share.db.bak';
    $dbExisted = file_exists($dbFile) && filesize($dbFile) > 0;

    // Safety: if DB is empty/missing but backup has data, restore it
    if (!$dbExisted && file_exists($backupFile) && filesize($backupFile) > 4096) {
        @copy($backupFile, $dbFile);
        error_log('ShareBox DB: restored from backup (DB was empty/missing)');
    }

    $db = new PDO('sqlite:' . $dbFile, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // WAL : lectures concurrentes sans bloquer les écritures
    $statements = [
        'PRAGMA journal_mode=WAL',
        'PRAGMA busy_timeout=10000',
    ];
    foreach ($statements as $s) $db->query($s);

    // Safety: auto-backup après ouverture (max 1h).
    // Checkpoint WAL d'abord : sans ça, @copy capture share.db sans share.db-wal
    // et le backup manque toutes les écritures non encore fusionnées dans le main file.
    if ($dbExisted && filesize($dbFile) > 4096) {
        if (!file_exists($backupFile) || (time() - filemtime($backupFile)) > 3600) {
            $db->exec('PRAGMA wal_checkpoint(PASSIVE)');
            @copy($dbFile, $backupFile);
        }
    }

    // Crée les tables si elles n'existent pas
    $db->query("
        CREATE TABLE IF NOT EXISTS probe_cache (
            path TEXT NOT NULL PRIMARY KEY,
            mtime INTEGER NOT NULL,
            result TEXT NOT NULL
        )
    ");

    $db->query("
        CREATE TABLE IF NOT EXISTS subtitle_cache (
            path TEXT NOT NULL,
            track INTEGER NOT NULL,
            mtime INTEGER NOT NULL,
            vtt TEXT NOT NULL,
            PRIMARY KEY (path, track)
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
    $targetVersion = 10; // bump when adding migrations

    if ($version < 1) {
        // v1 : supprimer password_plain si elle existe (ancienne colonne insecure)
        $cols = array_column($db->query("PRAGMA table_info(links)")->fetchAll(), 'name');
        if (in_array('password_plain', $cols, true)) {
            // SQLite < 3.35 ne supporte pas DROP COLUMN — on vide la colonne
            $db->prepare("UPDATE links SET password_plain = NULL WHERE password_plain IS NOT NULL")->execute();
        }
        $db->query('PRAGMA user_version = 1');
    }

    if ($version < 2) {
        // v2 : table pour les posters TMDB (cache par chemin de dossier)
        $db->query("
            CREATE TABLE IF NOT EXISTS folder_posters (
                path TEXT NOT NULL PRIMARY KEY,
                poster_url TEXT,
                tmdb_id INTEGER,
                title TEXT,
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $db->query('PRAGMA user_version = 2');
    }

    if ($version < 3) {
        // v3 : ajouter overview (résumé TMDB) à folder_posters
        $cols = array_column($db->query("PRAGMA table_info(folder_posters)")->fetchAll(), 'name');
        if (!in_array('overview', $cols, true)) {
            $db->query("ALTER TABLE folder_posters ADD COLUMN overview TEXT");
        }
        $db->query('PRAGMA user_version = 3');
    }

    if ($version < 4) {
        // v4 : type de dossier (series ou movies) pour adapter le rendu grille
        $cols = array_column($db->query("PRAGMA table_info(folder_posters)")->fetchAll(), 'name');
        if (!in_array('folder_type', $cols, true)) {
            $db->query("ALTER TABLE folder_posters ADD COLUMN folder_type TEXT DEFAULT 'series'");
        }
        $db->query('PRAGMA user_version = 4');
    }

    if ($version < 5) {
        // v5 : flag verified pour éviter de re-vérifier les matchs AI confirmés
        $cols = array_column($db->query("PRAGMA table_info(folder_posters)")->fetchAll(), 'name');
        if (!in_array('verified', $cols, true)) {
            $db->query("ALTER TABLE folder_posters ADD COLUMN verified INTEGER DEFAULT 0");
        }
        $db->query('PRAGMA user_version = 5');
    }

    if ($version < 6) {
        // v6 : compteur de tentatives AI (abandon après N échecs)
        $cols = array_column($db->query("PRAGMA table_info(folder_posters)")->fetchAll(), 'name');
        if (!in_array('ai_attempts', $cols, true)) {
            $db->query("ALTER TABLE folder_posters ADD COLUMN ai_attempts INTEGER DEFAULT 0");
        }
        $db->query('PRAGMA user_version = 6');
    }

    if ($version < 7) {
        // v7 : année et type TMDB pour enrichir le contexte AI de vérification
        $cols = array_column($db->query("PRAGMA table_info(folder_posters)")->fetchAll(), 'name');
        if (!in_array('tmdb_year', $cols, true)) {
            $db->query("ALTER TABLE folder_posters ADD COLUMN tmdb_year TEXT");
        }
        if (!in_array('tmdb_type', $cols, true)) {
            $db->query("ALTER TABLE folder_posters ADD COLUMN tmdb_type TEXT");
        }
        $db->query('PRAGMA user_version = 7');
    }

    if ($version < 8) {
        // v8 : table users pour l'authentification PHP
        $db->query("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'admin',
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $db->query('PRAGMA user_version = 8');
    }

    if ($version < 9) {
        // v9 : mode privé par utilisateur
        $cols = array_column($db->query("PRAGMA table_info(users)")->fetchAll(), 'name');
        if (!in_array('private', $cols, true)) {
            $db->query("ALTER TABLE users ADD COLUMN private INTEGER NOT NULL DEFAULT 0");
        }
        $db->query('PRAGMA user_version = 9');
    }

    if ($version < 10) {
        // v10 : attribution des liens à leur créateur
        $cols = array_column($db->query("PRAGMA table_info(links)")->fetchAll(), 'name');
        if (!in_array('created_by', $cols, true)) {
            $db->query("ALTER TABLE links ADD COLUMN created_by TEXT REFERENCES users(username)");
        }
        $db->query('PRAGMA user_version = 10');
    }

    if ($version < $targetVersion) {
        error_log('ShareBox DB migrated from v' . $version . ' to v' . $targetVersion);
    }

    return $db;
}

/**
 * Purge les entrées probe_cache orphelines (fichiers plus partagés).
 * Appelé périodiquement (cron ou admin), pas à chaque requête.
 */
function purge_probe_cache(PDO $db): int {
    $where = "NOT EXISTS (
        SELECT 1 FROM links
        WHERE probe_cache.path = links.path
           OR probe_cache.path LIKE rtrim(links.path, '/') || '/%'
    )";
    $stmt = $db->prepare("DELETE FROM probe_cache WHERE $where");
    $stmt->execute();
    $count = $stmt->rowCount();
    // Purge aussi le cache sous-titres orphelin
    $stmt2 = $db->prepare("DELETE FROM subtitle_cache WHERE NOT EXISTS (
        SELECT 1 FROM links
        WHERE subtitle_cache.path = links.path
           OR subtitle_cache.path LIKE rtrim(links.path, '/') || '/%'
    )");
    $stmt2->execute();
    return $count + $stmt2->rowCount();
}
