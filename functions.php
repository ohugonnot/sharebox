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

// FFmpeg hardware acceleration (overridable via config.php)
defined('FFMPEG_HW_ACCEL')         || define('FFMPEG_HW_ACCEL', 'auto');     // 'auto'|'vaapi'|'nvenc'|'v4l2m2m'|'none'
defined('FFMPEG_VAAPI_DEVICE')     || define('FFMPEG_VAAPI_DEVICE', '/dev/dri/renderD128');

// FFmpeg encoding defaults (overridable via config.php)
defined('FFMPEG_CRF')              || define('FFMPEG_CRF', 22);              // x264 quality (lower = better, 18-28 typical)
defined('FFMPEG_PRESET')           || define('FFMPEG_PRESET', 'veryfast');    // x264 preset live streaming sur 8 cores
defined('FFMPEG_PRESET_HLS')       || define('FFMPEG_PRESET_HLS', 'slow');   // x264 preset HLS (async, pré-généré en arrière-plan)
defined('FFMPEG_THREADS')          || define('FFMPEG_THREADS', 4);           // 50% des 8 cores, laisse marge pour rtorrent/flood/jellyfin
defined('FFMPEG_AUDIO_BITRATE')    || define('FFMPEG_AUDIO_BITRATE', '192k'); // AAC audio bitrate
defined('FFMPEG_AUDIO_CHANNELS')   || define('FFMPEG_AUDIO_CHANNELS', 2);    // stereo downmix
defined('FFMPEG_GOP_SIZE_DEFAULT') || define('FFMPEG_GOP_SIZE_DEFAULT', 50);  // keyframe interval transcode (2s@25fps, seek-friendly)
defined('FFMPEG_TUNE')             || define('FFMPEG_TUNE', '');              // '' = désactivé, 'film' = archivage (coût CPU élevé en live)
defined('FFMPEG_BFRAMES')          || define('FFMPEG_BFRAMES', 0);           // 0 = defaults x264, sinon 2-3 (qualité++ mais encode plus lent)
defined('FFMPEG_REFS')             || define('FFMPEG_REFS', 0);              // 0 = defaults x264, sinon 3-4 (idem)

// Remux audio sync : -af appliqué à la piste audio en mode -c:v copy.
// Defaults : async=1 + min_hard_comp=0.100 = resampling très doux, hard
// correct uniquement quand la dérive dépasse 100ms. L'ancien async=2000
// était trop agressif et créait des micro-glitches audibles sur fichiers
// proprement timbrés (drift naturel < 5ms compensé en stretch/squeeze).
// Override possible : 'aresample=async=2000:first_pts=0' pour anciens fichiers
// avec drift important, ou '' pour désactiver tout resampling.
defined('FFMPEG_REMUX_AUDIO_FILTER') || define('FFMPEG_REMUX_AUDIO_FILTER', 'aresample=async=1:min_hard_comp=0.100:first_pts=0');

// FFmpeg HDR tonemapping — CPU-intensif, nécessite plus de threads (overridable via config.php)
defined('FFMPEG_HDR_THREADS')      || define('FFMPEG_HDR_THREADS', 6);       // tonemapping float32 gourmand (75% des 8 cores)
defined('FFMPEG_HDR_CRF')          || define('FFMPEG_HDR_CRF', 22);         // aligné avec SDR (le tonemapping domine le CPU, pas l'encodage)

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
    // Tous les slots occupés — polling round-robin avec détection déconnexion
    // ignore_user_abort(false) permet à connection_aborted() de fonctionner
    // même sans output envoyé (pas de headers flush à ce stade)
    ignore_user_abort(false);
    $deadline = microtime(true) + STREAM_SLOT_TIMEOUT;
    while (microtime(true) < $deadline) {
        for ($i = 1; $i <= $max; $i++) {
            $fp = fopen("/tmp/sharebox_stream_slot_{$i}.lock", 'w');
            if (flock($fp, LOCK_EX | LOCK_NB)) return [$fp, true];
            fclose($fp);
        }
        if (connection_aborted()) exit;
        usleep(100000); // 100ms entre chaque tour
    }
    http_response_code(503);
    exit;
}

function releaseStreamSlot(mixed &$fp): void {
    if (!is_resource($fp)) return;
    flock($fp, LOCK_UN);
    fclose($fp);
    $fp = null;
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
    if (!is_resource($fp)) return;
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
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        $it->setMaxDepth(3);
        foreach ($it as $f) {
            try {
                if ($f->isFile() && isset($videoExts[strtolower($f->getExtension())])) {
                    return true;
                }
            } catch (\UnexpectedValueException $e) { continue; }
        }
    } catch (\UnexpectedValueException $e) {
        return false;
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
    $clean = preg_replace('/[._()[\]{}:]+/', ' ', $clean);
    // Retirer la numérotation en début (ex: "01 - ", "01. ", "95 - ")
    $clean = preg_replace('/^\s*\d{1,3}\s*[-–.]\s*/', '', $clean);
    // Chercher une année (4 chiffres entre 1950 et 2099)
    // Si range d'années proches (ex: "1997-2003" = série), prendre la première (TMDB indexe par début).
    // Sinon prendre la dernière (ex: "2001 A Space Odyssey 1968" → 1968 est l'année de sortie).
    $year = null;
    if (preg_match_all('/\b((?:19|20)\d{2})\b/', $clean, $m)) {
        $first = (int)reset($m[1]);
        $last = (int)end($m[1]);
        $year = (count($m[1]) > 1 && abs($last - $first) <= 10) ? $first : $last;
    }
    // Remove season/saison markers before cutting to tech tags (they pollute TMDB search)
    // Handles: "Saison 3", "Season 2", "34 Saisons", "4 Seasons", "Season 1-4", "S01-S07"
    // Also handles ordinal forms: "2nd Season", "3rd Season", "1st Season"
    $clean = preg_replace('/\b\d*\s*(saisons?|seasons?)\s*\d*(?:\s*[-–]\s*\d+)?\b/i', ' ', $clean);
    $clean = preg_replace('/\b\d+(st|nd|rd|th)\s+seasons?\b/i', ' ', $clean);
    $clean = preg_replace('/\bS\d{1,2}\s*[-–]\s*S\d{1,2}\b/i', ' ', $clean);
    // Remove common non-title words that confuse TMDB
    $clean = preg_replace('/\b(int[eé]grale|collection|custom|restored|remast(?:er)?ed|pack|films?\s+\d+\s+a\s+\d+|oav|mini\s+film)\b/iu', ' ', $clean);
    // Remove site tags like "Torrent911.com"
    $clean = preg_replace('/\b\w+\.(com|org|net|eu|io)\b/i', '', $clean);
    // Remove "HD Remasted" pattern
    $clean = preg_replace('/\bHD\s+Remast\w*/i', '', $clean);
    // Couper au premier tag technique
    $title = preg_replace('/\b(multi|vff|vfq|truefrench|french|english|vostfr|vost|subfrench|dual|bluray|blu-ray|bdrip|brrip|webrip|web-?dl|hdtv|dvdrip|hdrip|x264|x265|h264|h265|hevc|avc|xvid|divx|avi|mpeg|mpg|10bit|remux|2160p|1080p|720p|480p|uhd|4k|hdr|hdr10|dts|truehd|atmos|aac|ac3|flac|ddp?\d|proper\d?|repack|internal|extended|unrated|directors?-?cut|complete|s\d{1,2}e?\d{0,4}|e\d{2,4})\b.*/i', '', $clean);
    // "DC" = Directors Cut — retire seulement en fin de titre (évite de couper "DC Comics" au début)
    $title = preg_replace('/\s+dc\s*$/i', '', $title);
    // Si on a coupé à l'année, la retirer du titre aussi
    if ($year) {
        $title = preg_replace('/\b' . $year . '\b/', '', $title);
    }
    // Clean trailing junk: dashes, numbers, stray words
    $title = preg_replace('/\s*-\s*$/', '', $title);
    $title = preg_replace('/\s{2,}/', ' ', trim($title));
    // Fallback : le nom brut nettoyé
    if ($title === '') $title = trim($clean);
    // Un code saison/épisode nu (S01, E03...) n'est pas un titre exploitable
    if (preg_match('/^[SE]\d{1,2}$/i', $title)) $title = '';
    // Nom de dossier générique sans contexte → pas de titre exploitable
    if (preg_match('/^(movies?|films?|s[eé]ries?|videos?|specials?|extras?|bonus|ova|oav)$/i', $title)) $title = '';
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
/**
 * Normalize a path to the filesystem encoding (NFD on Linux).
 * Prevents NFC/NFD duplicates in DB by always using realpath() when possible.
 */
function normalize_path(string $path): string {
    $real = realpath($path);
    return $real !== false ? $real : $path;
}

function is_path_within(string|false $resolvedPath, string $basePath): bool {
    if ($resolvedPath === false) return false;
    $base = rtrim($basePath, '/');
    if ($resolvedPath === $base || str_starts_with($resolvedPath, $base . '/')) {
        return true;
    }
    // $basePath peut etre un symlink : verifier aussi contre sa cible reelle
    $realBase = realpath($basePath);
    if ($realBase !== false) {
        $realBase = rtrim($realBase, '/');
        if ($resolvedPath === $realBase || str_starts_with($resolvedPath, $realBase . '/')) {
            return true;
        }
    }
    // Chemin NVMe : les symlinks dans BASE_PATH peuvent pointer vers NVME_PATH
    if (defined('NVME_PATH')) {
        $nvme = rtrim(NVME_PATH, '/');
        if ($resolvedPath === $nvme || str_starts_with($resolvedPath, $nvme . '/')) {
            return true;
        }
    }
    return false;
}

// ── Logging ─────────────────────────────────────────────────────────────────

/**
 * Retourne le chemin vers ffmpeg_errors.log et rotate si nécessaire (5 MB max, 3 fichiers).
 * À appeler juste avant chaque invocation ffmpeg pour éviter que stream.log soit pollué.
 */
function ffmpeg_log_path(): string {
    if (!defined('STREAM_LOG') || !STREAM_LOG) return '/dev/null';
    $logFile = dirname(STREAM_LOG) . '/ffmpeg_errors.log';
    if (@filesize($logFile) > LOG_ROTATION_SIZE) {
        for ($r = LOG_ROTATION_COUNT; $r > 1; $r--) @rename($logFile . '.' . ($r - 1), $logFile . '.' . $r);
        @rename($logFile, $logFile . '.1');
    }
    return $logFile;
}

/**
 * Log avec rotation (5 MB max, 3 fichiers). Channels : stream, poster, app.
 */
function sharebox_log(string $msg, string $channel = 'stream'): void {
    if (!defined('STREAM_LOG') || !STREAM_LOG) return;
    $logFile = $channel === 'stream' ? STREAM_LOG : dirname(STREAM_LOG) . '/' . $channel . '.log';
    if (@filesize($logFile) > LOG_ROTATION_SIZE) {
        for ($r = LOG_ROTATION_COUNT; $r > 1; $r--) @rename($logFile . '.' . ($r - 1), $logFile . '.' . $r);
        @rename($logFile, $logFile . '.1');
    }
    $caller = php_sapi_name() === 'cli' ? 'CLI' : ($_SERVER['REMOTE_ADDR'] ?? '-');
    $line = '[' . date('Y-m-d H:i:s') . '] [' . $caller . '] ' . $msg . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Trouve le binaire PHP CLI portable.
 * PHP_BINARY pointe vers php-fpm en contexte FPM → inutilisable pour exec().
 * Cherche dans l'ordre : 1) constante PHP_CLI_BINARY override, 2) php à côté
 * de PHP_BINARY (cas typique : /usr/local/sbin/php-fpm + /usr/local/bin/php),
 * 3) fallback sur PATH ('php' tout court).
 */
function find_php_cli(): string {
    if (defined('PHP_CLI_BINARY') && PHP_CLI_BINARY) return PHP_CLI_BINARY;
    // PHP_BINARY en contexte FPM pointe vers php-fpm, pas le CLI.
    // Priorité au binaire versionné (ex: php8.2 sur Debian multi-PHP) pour éviter
    // de tomber sur /usr/bin/php qui peut être une version différente sans pdo_sqlite.
    $ver = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $binDir = dirname(PHP_BINARY);
    foreach ([
        '/usr/local/bin/php',        // Alpine/Docker (version unique)
        '/usr/bin/php' . $ver,       // Debian multi-PHP: php8.2, php8.4…
        $binDir . '/php',            // même répertoire que PHP_BINARY
        '/usr/bin/php',              // fallback générique (peut être une version différente)
    ] as $candidate) {
        if (is_executable($candidate)) return $candidate;
    }
    return 'php';
}

// Aliases pour compatibilité et lisibilité
function stream_log(string $msg): void { sharebox_log($msg, 'stream'); }
function poster_log(string $msg): void { sharebox_log($msg, 'poster'); }
function app_log(string $msg): void { sharebox_log($msg, 'app'); }
function telemetry_log(string $msg): void { sharebox_log($msg, 'telemetry'); }

// ── TMDB helpers ───────────────────────────────────────────────────────────

/**
 * Construit les variantes de recherche pour un titre (du plus précis au plus large).
 * @return string[]
 */
function tmdb_build_queries(string $title): array {
    $queries = [$title];
    $shorter = preg_replace('/\b(hd|remasted|remastered|complete|integrale|intégrale|collection|pack|coffret)\b.*/i', '', $title);
    $shorter = trim($shorter);
    if ($shorter !== '' && $shorter !== $title) $queries[] = $shorter;
    $words = explode(' ', $title);
    if (count($words) > 3) {
        $half = implode(' ', array_slice($words, 0, (int)ceil(count($words) / 2)));
        if ($half !== $title && $half !== $shorter) $queries[] = $half;
    }
    return $queries;
}

/**
 * Fetch a TMDB API URL with retry on 429/5xx and exponential backoff.
 * @return array|null Decoded JSON or null on failure
 */
/**
 * Fetch TMDB API avec retry exponentiel et erreurs catégorisées.
 *
 * Migré de file_get_contents vers cURL pour :
 *  - Différenciation des erreurs (timeout/DNS/4xx/5xx) loggées séparément
 *  - Support HTTP/2 + connection reuse via curl share handle
 *  - Timeout connect séparé du timeout transfer (évite hang sur DNS lent)
 *  - Retry-After respect sur 429
 *
 * Le 2e param $ctx est ignoré (legacy file_get_contents stream context).
 * Conservé pour compat des callers existants — supprimable après migration totale.
 *
 * @param int $maxRetries Nombre de retries après le premier essai (default 2 = 3 tentatives total)
 * @return array|null Decoded JSON ou null si toutes tentatives échouent
 */
function tmdb_fetch(string $url, $ctx = null, int $maxRetries = 2): ?array {
    static $sh = null;  // share handle (DNS + cookies + ssl session reuse)
    if ($sh === null) {
        $sh = curl_share_init();
        curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
        curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
    }

    $safeUrl = preg_replace('/api_key=[^&]+/', 'api_key=***', $url);

    for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'sharebox/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_SHARE          => $sh,
            CURLOPT_HEADER         => true,
        ]);
        $raw      = curl_exec($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        $errMsg   = curl_error($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $headers = $raw !== false ? substr($raw, 0, $headerSize) : '';
        $resp    = $raw !== false ? substr($raw, $headerSize)    : false;

        if ($resp !== false && $errno === 0 && $status >= 200 && $status < 400) {
            return json_decode($resp, true);
        }

        // Catégoriser l'erreur (utile pour debug en prod)
        $errCategory = 'unknown';
        if ($errno === CURLE_OPERATION_TIMEDOUT)        $errCategory = 'timeout';
        elseif ($errno === CURLE_COULDNT_RESOLVE_HOST)  $errCategory = 'dns';
        elseif ($errno === CURLE_COULDNT_CONNECT)       $errCategory = 'connect';
        elseif ($errno === CURLE_SSL_CONNECT_ERROR)     $errCategory = 'ssl';
        elseif ($status === 401)                        $errCategory = 'auth';
        elseif ($status === 404)                        $errCategory = 'not_found';
        elseif ($status === 429)                        $errCategory = 'rate_limit';
        elseif ($status >= 500)                         $errCategory = 'server_5xx';
        elseif ($status >= 400)                         $errCategory = 'client_4xx';

        if ($attempt < $maxRetries) {
            // Erreurs non-retryables : abandonner immédiatement
            if (in_array($errCategory, ['auth', 'not_found', 'client_4xx'], true)) {
                if (function_exists('ai_log')) ai_log('TMDB fetch ' . $errCategory . ' (no retry): ' . $safeUrl);
                return null;
            }
            // Rate limit : respecter Retry-After
            if ($errCategory === 'rate_limit') {
                $wait = 2;
                if (preg_match('/^retry-after:\s*(\d+)/im', $headers, $m)) {
                    $wait = min(5, max(1, (int)$m[1]));
                }
                usleep($wait * 1000000);
            } else {
                // Backoff exponentiel : 500ms, 1000ms, 2000ms
                usleep(500000 * (1 << $attempt));
            }
        } else {
            // Dernière tentative échouée — log avec catégorie
            if (function_exists('ai_log')) {
                ai_log('TMDB fetch failed (' . $errCategory . ', errno=' . $errno
                    . ', status=' . $status . ', err="' . substr($errMsg, 0, 80) . '"): ' . $safeUrl);
            }
        }
    }
    return null;
}

/**
 * Wrapper de tmdb_fetch avec cache SQLite (table tmdb_cache).
 * Idempotent : 2 calls identiques en TTL → 1 seul appel HTTP réseau.
 *
 * Utiliser pour les endpoints search/details qui ne changent pas vite côté TMDB.
 * NE PAS utiliser pour les endpoints write ou time-sensitive.
 *
 * @param int $ttlSec Durée de vie du cache en secondes (default 7 jours).
 *                    Mettre à 0 pour bypass le cache (force refresh).
 * @return array|null Decoded JSON ou null si echec et pas en cache.
 */
function tmdb_fetch_cached(string $url, int $ttlSec = 604800, bool &$fromCache = false): ?array {
    $fromCache = false;
    if ($ttlSec <= 0) return tmdb_fetch($url);

    static $cacheStmt = null, $writeStmt = null;
    try {
        $db = get_db();
        if ($cacheStmt === null) {
            $cacheStmt = $db->prepare("SELECT value FROM tmdb_cache WHERE cache_key = :k AND expires_at > :now");
            $writeStmt = $db->prepare("INSERT INTO tmdb_cache (cache_key, value, expires_at) VALUES (:k, :v, :e)
                                       ON CONFLICT(cache_key) DO UPDATE SET value = :v, expires_at = :e");
        }
    } catch (Exception $e) {
        // DB indisponible → fallback sans cache
        return tmdb_fetch($url);
    }

    $key = md5($url);
    $now = time();

    // Lookup
    $cacheStmt->execute([':k' => $key, ':now' => $now]);
    $hit = $cacheStmt->fetchColumn();
    if ($hit !== false) {
        $decoded = json_decode($hit, true);
        if (is_array($decoded)) { $fromCache = true; return $decoded; }
    }

    // Miss → fetch
    $result = tmdb_fetch($url);
    if ($result !== null) {
        try {
            $writeStmt->execute([
                ':k' => $key,
                ':v' => json_encode($result, JSON_UNESCAPED_UNICODE),
                ':e' => $now + $ttlSec,
            ]);
            // Probabilistic GC : 1/100 cache writes purgent les entrées expirées.
            // Évite un cron dédié et garde la table petite (< 10000 lignes typ).
            if (mt_rand(0, 99) === 0) {
                @$db->prepare("DELETE FROM tmdb_cache WHERE expires_at < :now")
                    ->execute([':now' => $now]);
            }
        } catch (PDOException $e) {
            // Cache write failure non-fatale
        }
    }
    return $result;
}

/**
 * Cherche un titre sur TMDB et retourne TOUS les candidats (pour le pick IA).
 * @return array[] Array of {id, title, year, type, overview, poster}
 */
function tmdb_search_candidates(string $title, ?int $year, string $apiKey, $ctx, int $limit = 15, bool $preferTv = false, bool &$responded = false): array {
    $candidates = [];
    $seenIds = [];
    $responded = false; // true dès qu'un appel TMDB renvoie une réponse valide (≠ échec réseau/5xx)
    $encoded = urlencode($title);

    // On interroge TOUJOURS les deux types (movie ET tv) : c'est le SCORING (popularité +
    // cohérence de type + similarité) qui tranche. Se limiter au type "probable" matchait un
    // film homonyme pour toute série dont preferTv n'avait pas été détecté (Breaking Bad → un
    // film "Breaking Bad Wolf", etc.). preferTv ne sert plus qu'à ordonner et bonifier le score.
    // Année en VRAI paramètre TMDB (year / first_air_date_year), filtré côté serveur.
    $endpoints = $preferTv ? ['tv', 'movie'] : ['movie', 'tv'];
    $perCall = max(6, (int)ceil($limit / 2)); // borne par appel → garantit la diversité de type

    foreach ($endpoints as $endpoint) {
        foreach (['fr', 'en-US'] as $lang) {
            $yearParam = $year ? '&' . ($endpoint === 'tv' ? 'first_air_date_year' : 'year') . '=' . $year : '';
            $url = "https://api.themoviedb.org/3/search/{$endpoint}?api_key={$apiKey}&query={$encoded}&language={$lang}&page=1{$yearParam}";
            $fromCache = false;
            $data = tmdb_fetch_cached($url, 604800, $fromCache);
            if ($data !== null) $responded = true; // TMDB a répondu (même 0 résultat) — pas un échec réseau
            if (!$fromCache) usleep(300000);       // rate-limit : uniquement sur appel réseau réel
            if (!$data || empty($data['results'])) continue;
            $taken = 0;
            foreach ($data['results'] as $r) {
                if (empty($r['poster_path'])) continue;
                $locTitle = $r['title'] ?? $r['name'] ?? null;
                // Déjà vu (autre langue) → on agrège juste la variante de titre pour le scoring.
                if (isset($seenIds[$r['id']])) {
                    if ($locTitle) $candidates[$seenIds[$r['id']]]['titles'][] = $locTitle;
                    continue;
                }
                $origTitle = $r['original_title'] ?? $r['original_name'] ?? null;
                $seenIds[$r['id']] = count($candidates);
                $candidates[] = [
                    'id' => $r['id'],
                    'title' => $locTitle ?? '?',
                    'titles' => array_values(array_filter([$locTitle, $origTitle])), // variantes fr/en/original
                    'original_title' => $origTitle,
                    'year' => substr($r['release_date'] ?? $r['first_air_date'] ?? '', 0, 4),
                    'type' => $r['media_type'] ?? ($endpoint === 'tv' ? 'tv' : 'movie'),
                    'overview' => substr($r['overview'] ?? '', 0, 150),
                    'poster' => 'https://image.tmdb.org/t/p/w300' . $r['poster_path'],
                    'rating' => round((float)($r['vote_average'] ?? 0), 1),
                    'vote_count' => (int)($r['vote_count'] ?? 0),
                ];
                if (++$taken >= $perCall) break;
            }
        }
    }

    return $candidates;
}

/**
 * Cherche le meilleur match TMDB pour un titre, en raccourcissant la requête
 * mot par mot (depuis la fin) jusqu'à dépasser le seuil de confiance.
 * Les releases collent souvent un sous-titre/seconde langue après le vrai titre
 * (« 1BR The Apartement » → « 1BR ») : on garde la requête la PLUS LONGUE qui passe
 * le seuil, et on score contre la requête réellement utilisée pour éviter les dérives.
 *
 * $responded (out) indique si TMDB a répondu au moins une fois : permet à l'appelant de
 * distinguer un vrai « rien trouvé » (on peut abandonner) d'un échec réseau/5xx transitoire
 * (à réessayer plus tard, sans brûler de tentative).
 *
 * @return array|null ['candidate' => array, 'score' => int] ou null si < 35 (seuil bas tmdb_score_to_verified)
 */
function tmdb_match(string $title, ?int $year, bool $preferTv, string $apiKey, $ctx = null, bool &$responded = false): ?array {
    $responded = false;
    $words = explode(' ', trim($title));
    $minWords = max(1, count($words) - 4); // borne la récursion à 5 essais max

    // Une passe word-removal pour une année de recherche donnée (filtre TMDB).
    // Le scoring utilise toujours la VRAIE année (bonus), même quand on relâche le filtre.
    $run = function (?int $searchYear) use ($words, $minWords, $year, $preferTv, $apiKey, $ctx, &$responded): array {
        $best = null;
        $bestScore = 0;
        for ($n = count($words); $n >= $minWords; $n--) {
            $query = implode(' ', array_slice($words, 0, $n));
            if (mb_strlen($query) < 2) break;

            $r = false;
            foreach (tmdb_search_candidates($query, $searchYear, $apiKey, $ctx, 15, $preferTv, $r) as $cand) {
                $score = tmdb_score_candidate($query, $year, $cand, $preferTv); // score contre la requête utilisée
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $cand;
                }
            }
            if ($r) $responded = true;
            if ($bestScore >= 55) break; // match fiable (palier "verified 60") → on arrête de tronquer
        }
        return [$best, $bestScore];
    };

    [$best, $bestScore] = $run($year);
    // Filtre année trop strict (0 candidat retenu) : on relâche le filtre côté recherche,
    // le scoring conserve le bonus d'année. Évite qu'une année off-by-one tue le match.
    if ($bestScore < 35 && $year !== null) {
        [$best, $bestScore] = $run(null);
    }

    // 35 = seuil bas de tmdb_score_to_verified (verified=40) : en dessous, aucun match retourné.
    return $bestScore >= 35 ? ['candidate' => $best, 'score' => $bestScore] : null;
}

/**
 * Score a TMDB candidate against an extracted title/year.
 * Returns 0-100 reflecting match confidence.
 *
 * @param array $candidate {id, title, year, type, overview, poster, vote_count?}
 * @param bool $preferTv True if folder context suggests TV (has season subfolders)
 */
function tmdb_score_candidate(string $extractedTitle, ?int $extractedYear, array $candidate, bool $preferTv = false): int {
    $score = 0;

    // ── Title similarity (0-65 points) ──
    $norm = function(string $s): string {
        $s = mb_strtolower($s);
        $trans = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        $ascii = ($trans !== false) ? trim(preg_replace('/[^a-z0-9 ]/', '', $trans)) : '';
        if ($ascii !== '') {
            $s = $ascii;
        } else {
            // Non-latin (kanji, hangul…): keep as-is, just strip punctuation
            $s = preg_replace('/[\p{P}\p{S}]/u', '', $s);
        }
        return trim(preg_replace('/\s+/', ' ', $s));
    };
    $a = $norm($extractedTitle);
    if ($a === '') return 0;
    // Tronquer pour éviter O(n³) de similar_text sur des noms de release longs
    if (mb_strlen($a) > 80) $a = mb_substr($a, 0, 80);
    $lenA = mb_strlen($a);

    // Score contre TOUTES les variantes de titre (fr + en + original), agrégées par id lors de
    // la recherche bilingue. Crucial pour l'anime/contenu étranger : on cherche en anglais mais
    // TMDB peut renvoyer un titre fr ou japonais ; il suffit qu'UNE variante matche.
    $variants = $candidate['titles'] ?? [];
    foreach ([$candidate['title'] ?? null, $candidate['original_title'] ?? null] as $extra) {
        if ($extra !== null && $extra !== '') $variants[] = $extra;
    }
    $bestPct = 0;
    $bestB = '';
    foreach ($variants as $variant) {
        $b = $norm((string)$variant);
        if ($b === '') continue;
        similar_text($a, $b, $pct);
        // Penalize length divergence to avoid short-title false positives ("One" vs "One Piece")
        $lenB = mb_strlen($b);
        $lenRatio = min($lenA, $lenB) / max($lenA, $lenB);
        if ($lenRatio < 0.5) $pct *= $lenRatio * 1.5;
        if ($pct > $bestPct) { $bestPct = $pct; $bestB = $b; }
    }

    if ($bestB === '') return 0;
    $score += (int)round($bestPct * 0.65);

    // Substring bonus — additive, proportional to length ratio to avoid false positives
    // Skip very short titles (< 4 chars) to prevent "One" matching "One Piece"
    if ($a !== $bestB && mb_strlen($a) >= 4 && (str_contains($bestB, $a) || str_contains($a, $bestB))) {
        $shorter = min(mb_strlen($a), mb_strlen($bestB));
        $longer = max(mb_strlen($a), mb_strlen($bestB));
        $ratio = $shorter / $longer;
        $score += (int)round(8 * $ratio);
    }

    // ── Year (0-15 points) ──
    $cYear = (int)($candidate['year'] ?? 0);
    if ($extractedYear && $cYear) {
        if ($cYear === $extractedYear) {
            $score += 15;
        } elseif (abs($cYear - $extractedYear) <= 1) {
            $score += 10;
        }
    }

    // ── Type coherence (0-18 points) ──
    // preferTv n'est vrai que si le dossier contient des saisons/épisodes : signal FORT de
    // série. On bonifie alors lourdement la TV (et on ne bonifie pas le film) pour battre un
    // film homonyme au titre exact (ex. "Demon Slayer" le film vs la série au sous-titre long).
    // Sans signal de saison (films à plat), on garde la préférence film modérée.
    $cType = $candidate['type'] ?? '';
    if ($preferTv) {
        $score += ($cType === 'tv') ? 18 : 0;
    } elseif ($cType === 'movie') {
        $score += 10;
    } elseif ($cType === 'tv') {
        $score += 3; // pas de signal de saison mais média valide
    }

    // ── Popularity (0-12 points) — finer granularity to break ties ──
    $voteCount = (int)($candidate['vote_count'] ?? 0);
    if ($voteCount > 3000) {
        $score += 12;
    } elseif ($voteCount > 1000) {
        $score += 10;
    } elseif ($voteCount > 500) {
        $score += 8;
    } elseif ($voteCount > 100) {
        $score += 5;
    } elseif ($voteCount > 10) {
        $score += 2;
    }

    return min(100, $score);
}

/**
 * Convert a tmdb_score_candidate score to a verified level.
 */
function tmdb_score_to_verified(int $score): int {
    if ($score >= 80) return 80;
    if ($score >= 55) return 60;
    if ($score >= 35) return 40;
    return 0; // below threshold — no match
}

// ── FFmpeg hardware acceleration ────────────────────────────────────────────

/**
 * Détecte le meilleur encodeur matériel disponible.
 * Priorité : VAAPI → NVENC → V4L2M2M → software.
 * Le résultat est mis en cache dans une variable statique (détection une seule fois par requête).
 *
 * @return string 'vaapi'|'nvenc'|'v4l2m2m'|'none'
 */
function detect_hw_encoder(): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    /** @var string $configured — runtime value, may differ from default via config.php */
    $configured = (string)(defined('FFMPEG_HW_ACCEL') ? FFMPEG_HW_ACCEL : 'auto');

    // Valeur explicite : retourner directement sans détection
    if ($configured !== 'auto') {
        $cached = in_array($configured, ['vaapi', 'nvenc', 'v4l2m2m', 'none'], true)
            ? $configured
            : 'none';
        return $cached;
    }

    // VAAPI (Intel iGPU — le plus courant sur NAS/mini-PC)
    $vaapiDevice = defined('FFMPEG_VAAPI_DEVICE') ? FFMPEG_VAAPI_DEVICE : '/dev/dri/renderD128';
    if (file_exists($vaapiDevice)) {
        $hwaccels = shell_exec('ffmpeg -hwaccels 2>/dev/null') ?? '';
        if (str_contains($hwaccels, 'vaapi')) {
            $cached = 'vaapi';
            return $cached;
        }
    }

    // NVENC (Nvidia GPU) — h264_nvenc peut être compilé dans ffmpeg SANS qu'aucun
    // GPU ne soit présent. Exiger un device NVIDIA réel, sinon on choisirait nvenc
    // sur une machine sans carte → échec runtime "Cannot load libcuda.so.1" et
    // lecture cassée. (VAAPI fait déjà cette vérif de device plus haut.)
    $encoders = shell_exec('ffmpeg -encoders 2>/dev/null') ?? '';
    $hasNvidiaDevice = file_exists('/dev/nvidia0') || file_exists('/dev/nvidiactl');
    if ($hasNvidiaDevice && str_contains($encoders, 'h264_nvenc')) {
        $cached = 'nvenc';
        return $cached;
    }

    // V4L2 M2M (Raspberry Pi 4) — pareil : exiger le device ET l'encodeur, pas l'un
    // ou l'autre (un ffmpeg complet liste h264_v4l2m2m même hors Pi).
    if (file_exists('/dev/video10') && str_contains($encoders, 'h264_v4l2m2m')) {
        $cached = 'v4l2m2m';
        return $cached;
    }

    $cached = 'none';
    return $cached;
}

// ── FFmpeg helpers ──────────────────────────────────────────────────────────

const ALLOWED_QUALITIES = [480, 576, 720, 1080];
const ALLOWED_FILTER_MODES = ['none', 'anime', 'detail', 'night', 'deinterlace', 'hdr'];

function validateQuality(int $quality): int {
    return in_array($quality, ALLOWED_QUALITIES, true) ? $quality : 720;
}

function validateFilterMode(string $mode): string {
    return in_array($mode, ALLOWED_FILTER_MODES, true) ? $mode : 'none';
}

function validateBurnSub(int $burnSub, ?int $subCount = null): int {
    if ($burnSub < 0 || $burnSub >= 50) return -1;
    if ($subCount !== null && $burnSub >= $subCount) return -1;
    return $burnSub;
}

/**
 * Lit les données ffprobe en cache pour un chemin.
 * @return array<string,mixed>|null Le résultat ffprobe décodé, ou null si absent/illisible.
 */
function probe_cache_data(PDO $db, string $path): ?array {
    try {
        $stmt = $db->prepare("SELECT result FROM probe_cache WHERE path = :p");
        $stmt->execute([':p' => $path]);
        if ($row = $stmt->fetch()) {
            $data = json_decode($row['result'], true);
            return is_array($data) ? $data : null;
        }
    } catch (PDOException $e) { /* ignore */ }
    return null;
}

/**
 * Compte les pistes sous-titres d'un fichier via probe_cache.
 */
function getSubtitleCount(PDO $db, string $path): int {
    $data = probe_cache_data($db, $path);
    return $data === null ? 0 : count($data['subtitles'] ?? []);
}

/**
 * Détecte automatiquement si un fichier nécessite le filtre HDR→SDR.
 * @param PDO $db Database connection
 * @param string $path Chemin absolu du fichier
 * @return bool True si le fichier est en HDR (smpte2084, arib-std-b67, smpte428)
 */
function isHDRFile(PDO $db, string $path): bool {
    $data = probe_cache_data($db, $path);
    if ($data === null) {
        return false;
    }
    return in_array($data['colorTransfer'] ?? '', ['smpte2084', 'arib-std-b67', 'smpte428'], true);
}

/**
 * Construit le filter_complex ffmpeg pour transcode (avec ou sans burn-in sous-titre).
 *
 * @param string $hwEncoder Encodeur matériel actif ('vaapi'|'nvenc'|'v4l2m2m'|'none').
 *                          Quand 'vaapi', le filtre scale_vaapi remplace scale+lanczos
 *                          et hwupload est injecté pour transférer les frames vers le GPU.
 *                          Les autres modes (hdr, anime, etc.) restent en software même avec GPU.
 */
function buildFilterGraph(int $quality, int $audioTrack, int $burnSub = -1, string $filterMode = 'none', string $hwEncoder = 'none'): string {
    // VAAPI avec filtre mode 'none' : utiliser scale_vaapi + hwupload (tout sur GPU)
    // Les modes avec filtres CPU (hdr, anime, etc.) passent par software — pas de hwupload
    $useVaapiScale = ($hwEncoder === 'vaapi' && $filterMode === 'none');

    if ($useVaapiScale) {
        // scale_vaapi garde le format nv12 natif du GPU ; hwupload transfère vers surface vaapi
        $videoFilters = 'scale_vaapi=w=-2:h=\'min(' . $quality . '\\,ih)\':format=nv12,hwupload';
    } else {
        $scaleFilter = 'scale=-2:\'min(' . $quality . ',ih)\':flags=lanczos';
        // HDR : downscale dans le premier zscale AVANT conversion float32 (2.6x plus rapide)
        // Convertit quality (hauteur) en largeur 16:9 pour réduire les pixels avant le pipeline float32
        $hdrWidth = (int)(ceil($quality * 16 / 9 / 2) * 2); // arrondi pair
        $videoFilters = match($filterMode) {
            'hdr'    => 'zscale=w=\'min(' . $hdrWidth . '\\,iw)\':h=-2:t=linear:npl=100:p=bt709,format=gbrpf32le,tonemap=mobius:desat=0,zscale=t=bt709:m=bt709:r=tv',
            'anime'       => $scaleFilter . ',deband=1thr=0.04:2thr=0.04:3thr=0.04:4thr=0.04,unsharp=5:5:0.7:5:5:0.0',
            'detail'      => $scaleFilter . ',cas=0.5',
            'night'       => $scaleFilter . ',eq=gamma=1.4:brightness=0.05:contrast=1.1',
            'deinterlace' => 'bwdif=mode=send_frame:parity=auto:deint=all,' . $scaleFilter,
            default  => $scaleFilter,
        };
        $videoFilters .= ',format=yuv420p';
    }

    if ($burnSub >= 0) {
        // Scale vidéo d'abord, puis overlay sous-titres (meilleure lisibilité du texte)
        return '"[0:v:0]' . $videoFilters . '[sv];[0:s:' . $burnSub . '][sv]scale2ref[ss][sv2];[sv2][ss]overlay=eof_action=pass[v];'
            . '[0:a:' . $audioTrack . ']aresample=async=3000[a]"';
    }
    return '"[0:v:0]' . $videoFilters . '[v];'
        . '[0:a:' . $audioTrack . ']aresample=async=3000[a]"';
}

/**
 * Vérifie si un fichier a au moins une piste audio (via probe_cache).
 */
function hasAudioTrack(PDO $db, string $path): bool {
    $data = probe_cache_data($db, $path);
    // Pas de données probe → on suppose qu'il y a de l'audio (comportement historique).
    return $data === null ? true : !empty($data['audio']);
}

/**
 * Construit les arguments d'entrée ffmpeg communs.
 *
 * @param string $hwEncoder Encodeur matériel actif. 'vaapi' ajoute les flags
 *                          -hwaccel vaapi -hwaccel_output_format vaapi avant -i,
 *                          requis pour que scale_vaapi reçoive des frames déjà sur GPU.
 *                          Ignoré pour les autres backends (décodage CPU standard).
 */
function buildFfmpegInputArgs(string $filePath, string $seekBefore = '', string $hwEncoder = 'none'): string {
    $hwFlags = '';
    if ($hwEncoder === 'vaapi') {
        $vaapiDevice = defined('FFMPEG_VAAPI_DEVICE') ? FFMPEG_VAAPI_DEVICE : '/dev/dri/renderD128';
        $hwFlags = ' -hwaccel vaapi -hwaccel_device ' . escapeshellarg($vaapiDevice) . ' -hwaccel_output_format vaapi';
    }
    return 'timeout 3600 ionice -c 2 -n 0 nice -n 5 ffmpeg -nostdin' . $seekBefore . $hwFlags . ' -thread_queue_size 512 -fflags +genpts+discardcorrupt -i ' . escapeshellarg($filePath);
}

/**
 * Construit les arguments encodeur vidéo+AAC communs.
 *
 * @param string $hwEncoder Encodeur matériel à utiliser ('vaapi'|'nvenc'|'v4l2m2m'|'none').
 *                          'none' (défaut) → libx264 avec les presets configurés.
 *                          Les autres valeurs activent l'encodeur GPU correspondant.
 *                          Note : $isHDR et le filtre 'hdr' forcent software (VAAPI ne
 *                          supporte pas le tonemapping HDR→SDR).
 */
function buildFfmpegCodecArgs(int $gopSize = FFMPEG_GOP_SIZE_DEFAULT, bool $isHDR = false, bool $isHLS = false, string $hwEncoder = 'none'): string {
    // HDR tonemapping nécessite le pipeline CPU (zscale+tonemap) — ignorer le GPU
    $effectiveEncoder = $isHDR ? 'none' : $hwEncoder;

    $audioArgs = ' -c:a aac -ac ' . FFMPEG_AUDIO_CHANNELS . ' -b:a ' . FFMPEG_AUDIO_BITRATE . ' -shortest';
    if ($isHLS) {
        $audioArgs .= ' -force_key_frames "expr:gte(t,n_forced*4)"';
    }

    if ($effectiveEncoder === 'vaapi') {
        // h264_vaapi : QP fixe (pas de CRF), bf=0 (VAAPI ne supporte pas les B-frames en H.264)
        return ' -c:v h264_vaapi -qp 24 -bf 0 -g ' . $gopSize . $audioArgs;
    }

    if ($effectiveEncoder === 'nvenc') {
        // h264_nvenc : preset p4 (bon équilibre vitesse/qualité), cq = équivalent CRF NVENC
        return ' -c:v h264_nvenc -preset p4 -cq 24 -bf 0 -g ' . $gopSize . $audioArgs;
    }

    if ($effectiveEncoder === 'v4l2m2m') {
        // h264_v4l2m2m (Raspberry Pi) : ne supporte pas CRF/CQ, bitrate explicite requis
        return ' -c:v h264_v4l2m2m -b:v 3M -g ' . $gopSize . $audioArgs;
    }

    // Software fallback (libx264)
    $threads = $isHDR ? FFMPEG_HDR_THREADS : FFMPEG_THREADS;
    $crf = $isHDR ? FFMPEG_HDR_CRF : FFMPEG_CRF;
    $preset = $isHLS ? FFMPEG_PRESET_HLS : FFMPEG_PRESET;
    $args = ' -c:v libx264 -preset ' . $preset;
    /** @phpstan-ignore notIdentical.alwaysFalse */
    if (FFMPEG_TUNE !== '') {
        $args .= ' -tune ' . FFMPEG_TUNE;
    }
    $args .= ' -crf ' . $crf
        . ' -profile:v high -level 4.1';
    /** @phpstan-ignore greater.alwaysFalse */
    if (FFMPEG_BFRAMES > 0) {
        $args .= ' -bf ' . FFMPEG_BFRAMES;
    }
    /** @phpstan-ignore greater.alwaysFalse */
    if (FFMPEG_REFS > 0) {
        $args .= ' -refs ' . FFMPEG_REFS;
    }
    $args .= ' -g ' . $gopSize . ' -threads ' . $threads
        . ' -colorspace bt709 -color_primaries bt709 -color_trc bt709'
        . $audioArgs;
    return $args;
}

/**
 * Arguments muxer fMP4 (fragmented MP4 pour streaming progressif).
 */
function buildFmp4MuxerArgs(): string {
    return ' -avoid_negative_ts make_zero -start_at_zero'
        . ' -max_muxing_queue_size 4096 -min_frag_duration 2000000'
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
    if (!is_file($filePath)) return;
    if (filesize($filePath) < VMTOUCH_SIZE_LIMIT) {
        shell_exec('vmtouch -qt ' . escapeshellarg($filePath) . ' >/dev/null 2>&1 &');
    }
}

/**
 * Change le mot de passe d'un utilisateur.
 * Retourne ['ok' => true] ou ['error' => '...'].
 */
function change_password_for_user(PDO $db, string $username, string $currentPwd, string $newPwd, string $confirmPwd): array {
    if (strlen($newPwd) < 4) {
        return ['error' => 'Nouveau mot de passe : 4 caractères minimum'];
    }
    if ($newPwd !== $confirmPwd) {
        return ['error' => 'La confirmation ne correspond pas'];
    }
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($currentPwd, $user['password_hash'])) {
        return ['error' => 'Mot de passe actuel incorrect'];
    }
    $db->prepare("UPDATE users SET password_hash = ? WHERE username = ?")
       ->execute([password_hash($newPwd, PASSWORD_BCRYPT), $username]);
    return ['ok' => true];
}

/**
 * Supprime tous les liens expirés de la base.
 * Retourne le nombre de liens supprimés.
 */
function purge_expired_links(PDO $db): int {
    $stmt = $db->prepare("DELETE FROM links WHERE expires_at IS NOT NULL AND expires_at != '' AND expires_at < datetime('now')");
    $stmt->execute();
    return $stmt->rowCount();
}

/**
 * Enregistre un événement dans activity_logs.
 * Silencieux en cas d'erreur.
 */
function log_activity(string $event_type, ?string $username, ?string $ip, ?string $details): void
{
    try {
        $db = get_db();
        $db->prepare(
            "INSERT INTO activity_logs (event_type, username, ip, details) VALUES (?, ?, ?, ?)"
        )->execute([$event_type, $username, $ip, $details]);
        // Purge vieux logs max 1x/heure (évite un DELETE full-scan à chaque insert)
        $purgeFlag = sys_get_temp_dir() . '/sharebox_purge_actlogs';
        if (!file_exists($purgeFlag) || (time() - filemtime($purgeFlag)) > 3600) {
            $db->exec("DELETE FROM activity_logs WHERE created_at < datetime('now', '-90 days')");
            @touch($purgeFlag);
        }
    } catch (\Throwable $e) {
        // Silencieux — les logs ne doivent pas casser l'app
    }
}

/**
 * Serve a file — nginx (X-Accel-Redirect) or PHP direct with Range support.
 * Auto-detects based on XACCEL_PREFIX: non-empty → nginx, empty → PHP.
 */
function serve_file(string $path, string $contentType, string $disposition): void
{
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: ' . $disposition);

    // nginx: delegate to X-Accel-Redirect (zero-copy, supports resume natively)
    if (defined('XACCEL_PREFIX') && XACCEL_PREFIX !== '') {
        $encodedPath = XACCEL_PREFIX . str_replace('%2F', '/', rawurlencode($path));
        header('X-Accel-Redirect: ' . $encodedPath);
        exit;
    }

    // Apache / PHP direct: serve with Range support
    $size = filesize($path);
    set_time_limit(0);
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Accept-Ranges: bytes');
    $rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';

    if ($rangeHeader && preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $m)) {
        if ($m[1] === '' && $m[2] !== '') {
            // Suffix range: bytes=-N → last N bytes
            $start = max(0, $size - (int)$m[2]);
            $end   = $size - 1;
        } else {
            $start = $m[1] !== '' ? (int)$m[1] : 0;
            $end   = $m[2] !== '' ? (int)$m[2] : $size - 1;
        }
        $end = min($end, $size - 1);

        if ($start > $end || $start >= $size) {
            header('HTTP/1.1 416 Range Not Satisfiable');
            header('Content-Range: bytes */' . $size);
            exit;
        }

        $length = $end - $start + 1;
        header('HTTP/1.1 206 Partial Content');
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        header('Content-Length: ' . $length);

        $fh = fopen($path, 'rb');
        if ($fh === false) { http_response_code(500); exit; }
        fseek($fh, $start);
        $remaining = $length;
        ignore_user_abort(false);
        while ($remaining > 0 && !feof($fh) && !connection_aborted()) {
            $chunk = fread($fh, min(65536, $remaining));
            echo $chunk;
            flush();
            $remaining -= strlen($chunk);
        }
        fclose($fh);
    } else {
        header('Content-Length: ' . $size);
        readfile($path);
    }

    exit;
}
