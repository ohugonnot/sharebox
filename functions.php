<?php
/**
 * Fonctions pures réutilisables — extraites de download.php et ctrl.php
 */

// ── Tuning constants ────────────────────────────────────────────────────────
// Adjust these to match your hardware. See CLAUDE.md for rationale.

const PROBE_MAX_CONCURRENT    = 5;              // max parallel ffprobe processes
const PROBE_TIMEOUT           = 10;             // ffprobe timeout (seconds)
const SUBTITLE_EXTRACT_TIMEOUT = 120;           // ffmpeg subtitle extraction timeout (seconds)
const SUBTITLE_TRACK_MAX      = 99;             // max subtitle track index (anti-DoS)
const KEYFRAME_LOOKUP_TIMEOUT = 5;              // ffprobe keyframe PTS lookup timeout (seconds)
const STREAM_SLOT_TIMEOUT     = 15;             // max wait for a stream slot (seconds)
const VMTOUCH_SIZE_LIMIT      = 2 * 1024 * 1024 * 1024; // page-cache warm only files < 2 GB
const LOG_ROTATION_SIZE       = 5 * 1024 * 1024;        // rotate stream.log at 5 MB
const LOG_ROTATION_COUNT      = 3;              // keep 3 rotated log files
const AUTH_MAX_ATTEMPTS       = 10;             // password attempts before lockout
const AUTH_LOCKOUT_SLEEP      = 3;              // sleep seconds on lockout
const AUTH_FAIL_SLEEP         = 1;              // sleep seconds per failed attempt

// FFmpeg encoding defaults
const FFMPEG_CRF              = 23;             // x264 quality (lower = better, 18-28 typical)
const FFMPEG_PRESET           = 'ultrafast';    // x264 preset (ultrafast→veryslow)
const FFMPEG_THREADS          = 4;              // x264 thread count (half your cores for 2 concurrent)
const FFMPEG_AUDIO_BITRATE    = '192k';         // AAC audio bitrate
const FFMPEG_AUDIO_CHANNELS   = 2;              // stereo downmix
const FFMPEG_GOP_SIZE_DEFAULT = 25;             // keyframe interval (frames)

/**
 * Acquire a stream slot using flock — limits concurrent ffmpeg processes.
 * Tries non-blocking first, falls back to blocking wait on slot 1.
 * @return array{resource, bool} [file pointer, true if had to wait]
 */
function acquireStreamSlot(): array {
    $max = defined('STREAM_MAX_CONCURRENT') ? STREAM_MAX_CONCURRENT : 3;
    for ($i = 1; $i <= $max; $i++) {
        $fp = fopen("/tmp/sharebox_stream_slot_{$i}.lock", 'w');
        if (flock($fp, LOCK_EX | LOCK_NB)) {
            return [$fp, false];
        }
        fclose($fp);
    }
    // Tous les slots occupés — attendre slot 1 avec polling + détection déconnexion
    $fp = fopen('/tmp/sharebox_stream_slot_1.lock', 'w');
    $deadline = microtime(true) + STREAM_SLOT_TIMEOUT;
    while (microtime(true) < $deadline) {
        if (flock($fp, LOCK_EX | LOCK_NB)) return [$fp, true];
        if (connection_aborted()) { fclose($fp); exit; }
        usleep(100000); // 100ms entre chaque essai
    }
    fclose($fp);
    http_response_code(503);
    exit;
}

function releaseStreamSlot(mixed $fp): void {
    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * Acquire a probe slot (non-blocking, max 5 concurrent ffprobe processes).
 * Returns file pointer or null if all slots busy → caller should return 429.
 * @return resource|null
 */
function acquireProbeSlot(): mixed {
    for ($i = 1; $i <= PROBE_MAX_CONCURRENT; $i++) {
        $fp = fopen("/tmp/sharebox_probe_slot_{$i}.lock", 'w');
        if ($fp !== false && flock($fp, LOCK_EX | LOCK_NB)) return $fp;
        if ($fp !== false) fclose($fp);
    }
    return null;
}

function releaseProbeSlot(mixed $fp): void {
    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * Formate une taille en octets
 */
function format_taille(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' o';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' Ko';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' Mo';
    return round($bytes / 1073741824, 2) . ' Go';
}

/**
 * Retourne le type MIME de streaming pour une extension donnée
 */
function get_stream_mime(string $ext): ?string {
    return match($ext) {
        'mp4', 'm4v', 'mov' => 'video/mp4',
        'webm' => 'video/webm',
        'mkv' => 'video/x-matroska',
        'avi' => 'video/x-msvideo',
        'mp3' => 'audio/mpeg',
        'aac', 'm4a' => 'audio/mp4',
        'ogg', 'oga', 'opus' => 'audio/ogg',
        'flac' => 'audio/flac',
        'wav' => 'audio/wav',
        default => null,
    };
}

/**
 * Vérifie récursivement si un dossier contient au moins un fichier vidéo.
 * S'arrête dès le premier trouvé (early return).
 */
function dir_has_video(string $path): bool {
    static $videoExts = ['mp4' => 1, 'm4v' => 1, 'mov' => 1, 'webm' => 1, 'mkv' => 1, 'avi' => 1];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $f) {
        if ($f->isFile() && isset($videoExts[strtolower($f->getExtension())])) {
            return true;
        }
    }
    return false;
}

/**
 * Retourne 'video', 'audio' ou null selon l'extension du fichier
 */
function get_media_type(string $filename): ?string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mime = get_stream_mime($ext);
    if (!$mime) return null;
    return str_starts_with($mime, 'video/') ? 'video' : 'audio';
}

/**
 * Extrait un titre et une année depuis un nom de dossier/fichier média.
 * Ex: "Mobile Suit Gundam 0079" → ['title' => 'Mobile Suit Gundam 0079', 'year' => null]
 *     "Batman.Begins.2005.MULTI.2160p" → ['title' => 'Batman Begins', 'year' => 2005]
 */
function extract_title_year(string $name): array {
    // Retirer l'extension si c'est un fichier
    $clean = pathinfo($name, PATHINFO_EXTENSION) ? pathinfo($name, PATHINFO_FILENAME) : $name;
    // Retirer les tags entre crochets (ex: [Torrent911.com], [1080p], [FR])
    $clean = preg_replace('/\[.*?\]/', '', $clean);
    // Remplacer les séparateurs courants par des espaces
    $clean = preg_replace('/[._()[\]{}]+/', ' ', $clean);
    // Retirer la numérotation en début (ex: "01 - ", "01. ", "95 - ")
    $clean = preg_replace('/^\s*\d{1,3}\s*[-–.]\s*/', '', $clean);
    // Chercher une année (4 chiffres entre 1950 et 2099)
    $year = null;
    if (preg_match('/\b((?:19|20)\d{2})\b/', $clean, $m)) {
        $year = (int)$m[1];
    }
    // Couper au premier tag technique
    $title = preg_replace('/\b(multi|vff|vfq|truefrench|french|english|vostfr|subfrench|bluray|blu-ray|bdrip|brrip|webrip|web-?dl|hdtv|dvdrip|hdrip|x264|x265|h264|h265|hevc|avc|xvid|divx|avi|mpeg|mpg|10bit|remux|2160p|1080p|720p|480p|uhd|4k|hdr|hdr10|dts|truehd|atmos|aac|ac3|flac|ddp?\d|proper|repack|internal|extended|unrated|directors?-?cut|complete|s\d{2}e?\d{0,2})\b.*/i', '', $clean);
    // Si on a coupé à l'année, la retirer du titre aussi
    if ($year) {
        $title = preg_replace('/\b' . $year . '\b/', '', $title);
    }
    $title = preg_replace('/\s{2,}/', ' ', trim($title));
    // Fallback : le nom brut nettoyé
    if ($title === '') $title = trim($clean);
    return ['title' => $title, 'year' => $year];
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
    $attempts = 0;
    do {
        if (++$attempts > 100) {
            throw new RuntimeException('Impossible de générer un token unique après 100 tentatives');
        }
        $suffix = '';
        for ($i = 0; $i < 4; $i++) $suffix .= $chars[random_int(0, 35)];
        $candidate = $slug . '-' . $suffix;
        $check = $db->prepare("SELECT COUNT(*) FROM links WHERE token = :t");
        $check->execute([':t' => $candidate]);
    } while ($check->fetchColumn() > 0);

    return $candidate;
}

/**
 * Calcule la taille totale d'un répertoire récursivement
 */
function dir_size(string $path): int {
    $size = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile()) $size += $file->getSize();
    }
    return $size;
}

/**
 * Vérifie qu'un chemin résolu reste dans le répertoire de base autorisé
 */
function is_path_within(string|false $resolvedPath, string $basePath): bool {
    if ($resolvedPath === false) return false;
    $base = rtrim($basePath, '/');
    return $resolvedPath === $base || str_starts_with($resolvedPath, $base . '/');
}

// ── Logging ─────────────────────────────────────────────────────────────────

/**
 * Log un message dans le fichier STREAM_LOG avec rotation (5 MB max, 3 fichiers).
 */
function stream_log(string $msg): void {
    if (!defined('STREAM_LOG') || !STREAM_LOG) return;
    $logFile = STREAM_LOG;
    if (@filesize($logFile) > LOG_ROTATION_SIZE) {
        for ($r = LOG_ROTATION_COUNT; $r > 1; $r--) @rename($logFile . '.' . ($r - 1), $logFile . '.' . $r);
        @rename($logFile, $logFile . '.1');
    }
    $line = '[' . date('Y-m-d H:i:s') . '] [' . ($_SERVER['REMOTE_ADDR'] ?? '-') . '] ' . $msg . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Log un message dans le fichier poster.log avec rotation (5 MB max, 3 fichiers).
 * Utilisé pour tracer les opérations TMDB, AI, et les changements en DB.
 */
function poster_log(string $msg): void {
    $logFile = (defined('STREAM_LOG') && STREAM_LOG) ? dirname(STREAM_LOG) . '/poster.log' : null;
    if (!$logFile) return;
    if (@filesize($logFile) > LOG_ROTATION_SIZE) {
        for ($r = LOG_ROTATION_COUNT; $r > 1; $r--) @rename($logFile . '.' . ($r - 1), $logFile . '.' . $r);
        @rename($logFile, $logFile . '.1');
    }
    $caller = php_sapi_name() === 'cli' ? 'CLI' : ($_SERVER['REMOTE_ADDR'] ?? '-');
    $line = '[' . date('Y-m-d H:i:s') . '] [' . $caller . '] ' . $msg . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Log générique dans app.log — pour les composants sans log dédié (API, cron, DB).
 * Même format que stream_log/poster_log pour cohérence et parsing AI.
 */
function app_log(string $msg): void {
    $logFile = (defined('STREAM_LOG') && STREAM_LOG) ? dirname(STREAM_LOG) . '/app.log' : null;
    if (!$logFile) return;
    if (@filesize($logFile) > LOG_ROTATION_SIZE) {
        for ($r = LOG_ROTATION_COUNT; $r > 1; $r--) @rename($logFile . '.' . ($r - 1), $logFile . '.' . $r);
        @rename($logFile, $logFile . '.1');
    }
    $caller = php_sapi_name() === 'cli' ? 'CLI' : ($_SERVER['REMOTE_ADDR'] ?? '-');
    $line = '[' . date('Y-m-d H:i:s') . '] [' . $caller . '] ' . $msg . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

// ── FFmpeg helpers ──────────────────────────────────────────────────────────

const ALLOWED_QUALITIES = [480, 576, 720, 1080];

function validateQuality(int $quality): int {
    return in_array($quality, ALLOWED_QUALITIES, true) ? $quality : 720;
}

/**
 * Construit le filter_complex ffmpeg pour transcode (avec ou sans burn-in sous-titre).
 */
function buildFilterGraph(int $quality, int $audioTrack, int $burnSub = -1): string {
    if ($burnSub >= 0) {
        return '"[0:s:' . $burnSub . '][0:v]scale2ref[ss][sv];[sv][ss]overlay=eof_action=pass[ov];'
            . '[ov]scale=-2:\'min(' . $quality . ',ih)\',format=yuv420p[v];'
            . '[0:a:' . $audioTrack . ']aresample=async=3000[a]"';
    }
    return '"[0:v:0]scale=-2:\'min(' . $quality . ',ih)\',format=yuv420p[v];'
        . '[0:a:' . $audioTrack . ']aresample=async=3000[a]"';
}

/**
 * Construit les arguments d'entrée ffmpeg communs.
 */
function buildFfmpegInputArgs(string $filePath, string $seekBefore = ''): string {
    return 'ffmpeg' . $seekBefore . ' -thread_queue_size 512 -fflags +genpts+discardcorrupt -i ' . escapeshellarg($filePath);
}

/**
 * Construit les arguments encodeur x264+AAC communs.
 */
function buildFfmpegCodecArgs(int $gopSize = FFMPEG_GOP_SIZE_DEFAULT): string {
    return ' -c:v libx264 -preset ' . FFMPEG_PRESET . ' -crf ' . FFMPEG_CRF
        . ' -g ' . $gopSize . ' -threads ' . FFMPEG_THREADS
        . ' -c:a aac -ac ' . FFMPEG_AUDIO_CHANNELS . ' -b:a ' . FFMPEG_AUDIO_BITRATE . ' -shortest';
}

/**
 * Arguments muxer fMP4 (fragmented MP4 pour streaming progressif).
 */
function buildFmp4MuxerArgs(): string {
    return ' -avoid_negative_ts make_zero -start_at_zero'
        . ' -max_muxing_queue_size 1024 -min_frag_duration 300000'
        . ' -movflags frag_keyframe+empty_moov+default_base_moof';
}

/**
 * Calcule les épisodes précédent/suivant pour la navigation dans le player.
 *
 * @param string $subPath   Sous-chemin relatif du fichier courant (ex: "Season1/ep02.mkv")
 * @param string $basePath  Chemin absolu du dossier partagé
 * @param string $baseUrl   URL de base du lien (ex: "/dl/mon-token")
 * @return array{prev: ?array<string, string>, next: ?array<string, string>}
 */
function computeEpisodeNav(string $subPath, string $basePath, string $baseUrl): array {
    $prevFile = null;
    $nextFile = null;

    $parentSub = dirname($subPath);
    $parentDir = ($parentSub === '.') ? $basePath : $basePath . '/' . $parentSub;
    if (is_dir($parentDir)) {
        $siblings = [];
        foreach (scandir($parentDir) as $item) {
            if ($item[0] === '.') continue;
            if (is_file($parentDir . '/' . $item) && get_media_type($item) === 'video') {
                $siblings[] = $item;
            }
        }
        usort($siblings, 'strnatcasecmp');
        $currentName = basename($subPath);
        $idx = array_search($currentName, $siblings, true);
        if ($idx !== false) {
            if ($idx > 0) {
                $pSub = ($parentSub === '.') ? $siblings[$idx - 1] : $parentSub . '/' . $siblings[$idx - 1];
                $prevFile = ['name' => $siblings[$idx - 1], 'url' => $baseUrl . '?p=' . rawurlencode($pSub) . '&play=1', 'pp' => 'p=' . rawurlencode($pSub) . '&'];
            }
            if ($idx < count($siblings) - 1) {
                $nSub = ($parentSub === '.') ? $siblings[$idx + 1] : $parentSub . '/' . $siblings[$idx + 1];
                $nextFile = ['name' => $siblings[$idx + 1], 'url' => $baseUrl . '?p=' . rawurlencode($nSub) . '&play=1', 'pp' => 'p=' . rawurlencode($nSub) . '&'];
            }
        }
    }

    return ['prev' => $prevFile, 'next' => $nextFile];
}

/**
 * Warm le fichier dans le page cache si < 2 Go.
 */
function warmFileCache(string $filePath): void {
    if (filesize($filePath) < VMTOUCH_SIZE_LIMIT) {
        shell_exec('vmtouch -qt ' . escapeshellarg($filePath) . ' >/dev/null 2>&1 &');
    }
}
