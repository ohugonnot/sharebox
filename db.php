<?php
/**
 * Gestion de la base de données SQLite
 * La BDD est créée automatiquement au premier appel
 */

require_once __DIR__ . '/config.php';

/**
 * Retourne une connexion PDO vers la base SQLite
 * Crée la table "links" si elle n'existe pas encore
 */
function get_db(): PDO {
    static $db = null;
    if ($db !== null) return $db;

    $db = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // WAL : lectures concurrentes sans bloquer les écritures
    $db->exec('PRAGMA journal_mode=WAL');
    // Attendre jusqu'à 3s si la DB est verrouillée (évite SQLITE_BUSY sur probe concurrent)
    $db->exec('PRAGMA busy_timeout=3000');

    // Crée la table si elle n'existe pas
    $db->exec("
        CREATE TABLE IF NOT EXISTS probe_cache (
            path TEXT NOT NULL PRIMARY KEY,
            mtime INTEGER NOT NULL,
            result TEXT NOT NULL
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT NOT NULL UNIQUE,
            path TEXT NOT NULL,
            type TEXT NOT NULL DEFAULT 'file',
            name TEXT NOT NULL,
            password_hash TEXT DEFAULT NULL,
            password_plain TEXT DEFAULT NULL,
            expires_at TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            download_count INTEGER NOT NULL DEFAULT 0
        )
    ");

    // Purge les entrées probe_cache dont le fichier n'est plus partagé.
    // On vérifie à la fois les liens fichier (égalité exacte) et les liens dossier
    // (le probe_cache.path commence par links.path) pour ne pas purger les fichiers
    // dans un dossier partagé à chaque requête stream.
    $db->exec("DELETE FROM probe_cache WHERE NOT EXISTS (
        SELECT 1 FROM links
        WHERE probe_cache.path = links.path
           OR probe_cache.path LIKE rtrim(links.path, '/') || '/%'
    )");

    // Migration : ajouter password_plain si la colonne n'existe pas encore
    $cols = $db->query("PRAGMA table_info(links)")->fetchAll();
    $colNames = array_column($cols, 'name');
    if (!in_array('password_plain', $colNames)) {
        $db->exec("ALTER TABLE links ADD COLUMN password_plain TEXT DEFAULT NULL");
    }

    return $db;
}
