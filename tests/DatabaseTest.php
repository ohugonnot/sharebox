<?php

use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    private string $dbFile;

    /**
     * Define all config constants before db.php's require_once config.php
     * tries to define them. Already-defined constants silently win.
     */
    public static function setUpBeforeClass(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sharebox_test_');
        // Store for the class — individual tests get it via getDbPath()
        // We need DB_PATH defined before requiring db.php
        if (!defined('DB_PATH')) {
            define('DB_PATH', $tmp);
        }
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', '/tmp');
        }
        if (!defined('XACCEL_PREFIX')) {
            define('XACCEL_PREFIX', '/internal-download');
        }
        if (!defined('DL_BASE_URL')) {
            define('DL_BASE_URL', '/dl/');
        }
        if (!defined('STREAM_MAX_CONCURRENT')) {
            define('STREAM_MAX_CONCURRENT', 4);
        }
        if (!defined('STREAM_REMUX_ENABLED')) {
            define('STREAM_REMUX_ENABLED', false);
        }
        if (!defined('STREAM_LOG')) {
            define('STREAM_LOG', false);
        }
        if (!defined('BANDWIDTH_QUOTA_TB')) {
            define('BANDWIDTH_QUOTA_TB', 100);
        }

        require_once __DIR__ . '/../db.php';
    }

    public static function tearDownAfterClass(): void
    {
        $path = DB_PATH;
        foreach ([$path, $path . '-wal', $path . '-shm'] as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }
    }

    // ── 1. get_db() returns a PDO instance ──────────────────────────────

    public function testGetDbReturnsPdo(): void
    {
        $db = get_db();
        $this->assertInstanceOf(PDO::class, $db);
    }

    public function testGetDbReturnsSameInstance(): void
    {
        $a = get_db();
        $b = get_db();
        $this->assertSame($a, $b);
    }

    // ── 2. Schema: tables exist with correct columns ────────────────────

    public function testProbeCacheTableExists(): void
    {
        $db = get_db();
        $cols = $this->getColumnNames($db, 'probe_cache');
        $this->assertContains('path', $cols);
        $this->assertContains('mtime', $cols);
        $this->assertContains('result', $cols);
    }

    public function testLinksTableExists(): void
    {
        $db = get_db();
        $cols = $this->getColumnNames($db, 'links');
        $expected = ['id', 'token', 'path', 'type', 'name', 'password_hash',
                     'expires_at', 'created_at', 'download_count'];
        foreach ($expected as $col) {
            $this->assertContains($col, $cols, "links table missing column: $col");
        }
    }

    public function testNetSpeedTableExists(): void
    {
        $db = get_db();
        $cols = $this->getColumnNames($db, 'net_speed');
        $this->assertContains('ts', $cols);
        $this->assertContains('upload', $cols);
        $this->assertContains('download', $cols);
    }

    // ── 3. Index existence ──────────────────────────────────────────────

    /**
     * @dataProvider indexProvider
     */
    public function testIndexExists(string $indexName): void
    {
        $db = get_db();
        $indexes = $db->query("SELECT name FROM sqlite_master WHERE type='index'")
                      ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains($indexName, $indexes, "Missing index: $indexName");
    }

    public static function indexProvider(): array
    {
        return [
            'idx_net_speed_ts'  => ['idx_net_speed_ts'],
            'idx_links_expires' => ['idx_links_expires'],
            'idx_links_created' => ['idx_links_created'],
        ];
    }

    // ── 4. WAL mode is active ───────────────────────────────────────────

    public function testWalModeActive(): void
    {
        $db = get_db();
        $mode = $db->query('PRAGMA journal_mode')->fetchColumn();
        $this->assertSame('wal', strtolower($mode));
    }

    // ── 5. Migration: user_version is current ──────────────────────────

    public function testUserVersionIsCurrent(): void
    {
        $db = get_db();
        $version = (int) $db->query('PRAGMA user_version')->fetchColumn();
        $this->assertSame(19, $version);
    }

    public function testUsersTableHasPrivateColumn(): void
    {
        $db = get_db();
        $cols = array_column($db->query("PRAGMA table_info(users)")->fetchAll(), 'name');
        $this->assertContains('private', $cols);
    }

    public function testUsersPrivateDefaultsToZero(): void
    {
        $db = get_db();
        $db->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'user')")
           ->execute(['testprivuser', password_hash('pass', PASSWORD_BCRYPT)]);
        try {
            $row = $db->query("SELECT private FROM users WHERE username = 'testprivuser'")->fetch();
            $this->assertSame(0, (int)$row['private']);
        } finally {
            $db->prepare("DELETE FROM users WHERE username = ?")->execute(['testprivuser']);
        }
    }

    public function testLinksTableHasCreatedByColumn(): void
    {
        $db = get_db();
        $cols = array_column($db->query("PRAGMA table_info(links)")->fetchAll(), 'name');
        $this->assertContains('created_by', $cols);
    }

    // ── 5b. folder_posters table exists ──────────────────────────────────

    public function testFolderPostersTableExists(): void
    {
        $db = get_db();
        $cols = $this->getColumnNames($db, 'folder_posters');
        $this->assertContains('path', $cols);
        $this->assertContains('poster_url', $cols);
        $this->assertContains('tmdb_id', $cols);
    }

    // ── 5c. folder_posters has folder_type column ─────────────────────────

    public function testFolderPostersHasFolderTypeColumn(): void
    {
        $db = get_db();
        $cols = $this->getColumnNames($db, 'folder_posters');
        $this->assertContains('folder_type', $cols);
    }

    public function testFolderTypeDefaultsToSeries(): void
    {
        $db = get_db();
        $db->prepare("INSERT OR IGNORE INTO folder_posters (path) VALUES (?)")
           ->execute(['/test/default-type']);
        $row = $db->prepare("SELECT folder_type FROM folder_posters WHERE path = ?");
        $row->execute(['/test/default-type']);
        $value = $row->fetchColumn();
        $this->assertSame('series', $value);
    }

    // ── 5d. folder_posters has tmdb_year and tmdb_type columns ──────────

    public function testFolderPostersHasTmdbYearAndTypeColumns(): void
    {
        $db = get_db();
        $cols = $this->getColumnNames($db, 'folder_posters');
        $this->assertContains('tmdb_year', $cols);
        $this->assertContains('tmdb_type', $cols);
    }

    // ── 5e. folder_posters has match_attempts column (renamed from ai_attempts) ──

    public function testFolderPostersHasMatchAttemptsColumn(): void
    {
        $db = get_db();
        $cols = $this->getColumnNames($db, 'folder_posters');
        $this->assertContains('match_attempts', $cols);
        $this->assertNotContains('ai_attempts', $cols);
    }

    // ── 6. purge_probe_cache() ──────────────────────────────────────────

    public function testPurgeRemovesOrphanedEntries(): void
    {
        $db = get_db();
        $this->clearTables($db);

        // Insert a probe entry with no matching link
        $db->prepare("INSERT INTO probe_cache (path, mtime, result) VALUES (?, ?, ?)")
           ->execute(['/orphan/file.mkv', 1000, '{}']);

        $deleted = purge_probe_cache($db);
        $this->assertSame(1, $deleted);

        $count = (int) $db->query("SELECT COUNT(*) FROM probe_cache")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testPurgeKeepsFileLinkedDirectly(): void
    {
        $db = get_db();
        $this->clearTables($db);

        // Link points to exact file
        $db->prepare("INSERT INTO links (token, path, type, name) VALUES (?, ?, ?, ?)")
           ->execute(['tok1', '/movies/movie.mkv', 'file', 'movie']);
        $db->prepare("INSERT INTO probe_cache (path, mtime, result) VALUES (?, ?, ?)")
           ->execute(['/movies/movie.mkv', 1000, '{}']);

        $deleted = purge_probe_cache($db);
        $this->assertSame(0, $deleted);

        $count = (int) $db->query("SELECT COUNT(*) FROM probe_cache")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testPurgeKeepsFileInsideLinkedFolder(): void
    {
        $db = get_db();
        $this->clearTables($db);

        // Link points to a folder, probe entry is a file inside it
        $db->prepare("INSERT INTO links (token, path, type, name) VALUES (?, ?, ?, ?)")
           ->execute(['tok2', '/series/show/', 'directory', 'show']);
        $db->prepare("INSERT INTO probe_cache (path, mtime, result) VALUES (?, ?, ?)")
           ->execute(['/series/show/s01e01.mkv', 2000, '{}']);

        $deleted = purge_probe_cache($db);
        $this->assertSame(0, $deleted);
    }

    public function testPurgeMixedOrphanAndValid(): void
    {
        $db = get_db();
        $this->clearTables($db);

        // One valid link + probe, one orphaned probe
        $db->prepare("INSERT INTO links (token, path, type, name) VALUES (?, ?, ?, ?)")
           ->execute(['tok3', '/keep/file.mp4', 'file', 'keep']);
        $db->prepare("INSERT INTO probe_cache (path, mtime, result) VALUES (?, ?, ?)")
           ->execute(['/keep/file.mp4', 1000, '{}']);
        $db->prepare("INSERT INTO probe_cache (path, mtime, result) VALUES (?, ?, ?)")
           ->execute(['/gone/old.mkv', 1000, '{}']);

        $deleted = purge_probe_cache($db);
        $this->assertSame(1, $deleted);

        $remaining = $db->query("SELECT path FROM probe_cache")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['/keep/file.mp4'], $remaining);
    }

    // ── 7. links table has no password_plain column ─────────────────────

    public function testLinksTableHasNoPasswordPlainColumn(): void
    {
        $db = get_db();
        $cols = $this->getColumnNames($db, 'links');
        $this->assertNotContains('password_plain', $cols,
            'New links table should not have password_plain column');
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function getColumnNames(PDO $db, string $table): array
    {
        $rows = $db->query("PRAGMA table_info($table)")->fetchAll();
        return array_column($rows, 'name');
    }

    private function clearTables(PDO $db): void
    {
        $db->exec("DELETE FROM probe_cache");
        $db->exec("DELETE FROM subtitle_cache");
        $db->exec("DELETE FROM links");
    }
}
