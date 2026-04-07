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
const FFMPEG_CRF              = 20;             // x264 quality (lower = better, 18-28 typical)
const FFMPEG_PRESET           = 'medium';       // x264 preset transcode progressif (temps réel 720p/1080p@24fps avec 12 threads)
const FFMPEG_PRESET_HLS       = 'slow';         // x264 preset HLS (async, pré-généré en arrière-plan)
const FFMPEG_THREADS          = 12;             // x264 thread count (4 streams × 12 = 48 cores)
const FFMPEG_AUDIO_BITRATE    = '192k';         // AAC audio bitrate
const FFMPEG_AUDIO_CHANNELS   = 2;              // stereo downmix
const FFMPEG_GOP_SIZE_DEFAULT = 250;            // keyframe interval transcode (10s@25fps)

// FFmpeg HDR tonemapping — CPU-intensif, nécessite plus de threads
const FFMPEG_HDR_THREADS      = 24;             // tonemapping float32 gourmand (marge pour OS et autres streams)
const FFMPEG_HDR_CRF          = 20;             // aligné avec SDR (le tonemapping domine le CPU, pas l'encodage)

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
    $clean = preg_replace('/[._()[\]{}]+/', ' ', $clean);
    // Retirer la numérotation en début (ex: "01 - ", "01. ", "95 - ")
    $clean = preg_replace('/^\s*\d{1,3}\s*[-–.]\s*/', '', $clean);
    // Chercher une année (4 chiffres entre 1950 et 2099)
    $year = null;
    if (preg_match('/\b((?:19|20)\d{2})\b/', $clean, $m)) {
        $year = (int)$m[1];
    }
    // Remove season/saison markers before cutting to tech tags (they pollute TMDB search)
    // Handles: "Saison 3", "Season 2", "34 Saisons", "4 Seasons"
    $clean = preg_replace('/\b\d*\s*(saisons?|seasons?)\s*\d*\b/i', ' ', $clean);
    // Remove common non-title words that confuse TMDB
    $clean = preg_replace('/\b(int[eé]grale|collection|custom|restored|remast(?:er)?ed|pack|films?\s+\d+\s+a\s+\d+|oav|mini\s+film)\b/i', ' ', $clean);
    // Remove site tags like "Torrent911.com"
    $clean = preg_replace('/\b\w+\.(com|org|net|eu|io)\b/i', '', $clean);
    // Remove "HD Remasted" pattern
    $clean = preg_replace('/\bHD\s+Remast\w*/i', '', $clean);
    // Couper au premier tag technique
    $title = preg_replace('/\b(multi|vff|vfq|truefrench|french|english|vostfr|subfrench|bluray|blu-ray|bdrip|brrip|webrip|web-?dl|hdtv|dvdrip|hdrip|x264|x265|h264|h265|hevc|avc|xvid|divx|avi|mpeg|mpg|10bit|remux|2160p|1080p|720p|480p|uhd|4k|hdr|hdr10|dts|truehd|atmos|aac|ac3|flac|ddp?\d|proper|repack|internal|extended|unrated|directors?-?cut|complete|s\d{2}e?\d{0,2}|e\d{2,4})\b.*/i', '', $clean);
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

// Aliases pour compatibilité et lisibilité
function stream_log(string $msg): void { sharebox_log($msg, 'stream'); }
function poster_log(string $msg): void { sharebox_log($msg, 'poster'); }
function app_log(string $msg): void { sharebox_log($msg, 'app'); }

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
function tmdb_fetch(string $url, $ctx, int $maxRetries = 2): ?array {
    for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
        $resp = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})/', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }
        if ($resp !== false && $status >= 200 && $status < 400) {
            return json_decode($resp, true);
        }
        if ($attempt < $maxRetries) {
            if ($status === 429) {
                // Rate limited — check Retry-After or wait 2s
                $wait = 2;
                foreach ($http_response_header as $h) {
                    if (stripos($h, 'retry-after:') === 0) {
                        $wait = min(5, max(1, (int)trim(substr($h, 12))));
                    }
                }
                usleep($wait * 1000000);
            } else {
                // Network error or 5xx — exponential backoff
                usleep(500000 * ($attempt + 1));
            }
        }
    }
    return null;
}

/**
 * Cherche un titre sur TMDB et retourne le premier résultat avec un poster.
 * @param string[] $endpoints Ex: ['multi', 'tv'] ou ['multi', 'tv', 'movie']
 * @return array{poster: string, id: int, title: string, overview: string}|null
 */
function tmdb_search(string $title, ?int $year, string $apiKey, $ctx, array $endpoints = ['multi', 'tv']): ?array {
    $queries = tmdb_build_queries($title);
    if ($year) $queries[] = $title . ' ' . $year;
    foreach ($queries as $q) {
        $encoded = urlencode($q);
        foreach ($endpoints as $ep) {
            $url = "https://api.themoviedb.org/3/search/{$ep}?api_key={$apiKey}&query={$encoded}&language=fr&page=1";
            $data = tmdb_fetch($url, $ctx);
            if ($data && !empty($data['results'])) {
                foreach ($data['results'] as $r) {
                    if (!empty($r['poster_path'])) {
                        return [
                            'poster' => 'https://image.tmdb.org/t/p/w300' . $r['poster_path'],
                            'id' => $r['id'] ?? null,
                            'title' => $r['title'] ?? $r['name'] ?? null,
                            'overview' => $r['overview'] ?? null,
                            'year' => substr($r['release_date'] ?? $r['first_air_date'] ?? '', 0, 4),
                            'type' => $r['media_type'] ?? ($r['first_air_date'] ?? false ? 'tv' : 'movie'),
                        ];
                    }
                }
            }
        }
    }
    return null;
}

/**
 * Cherche un titre sur TMDB et retourne TOUS les candidats (pour le pick IA).
 * @return array[] Array of {id, title, year, type, overview, poster}
 */
function tmdb_search_candidates(string $title, ?int $year, string $apiKey, $ctx, int $limit = 15): array {
    $candidates = [];
    $seenIds = [];
    $encoded = urlencode($title);
    $urls = [
        "https://api.themoviedb.org/3/search/multi?api_key={$apiKey}&query={$encoded}&language=fr&page=1",
        "https://api.themoviedb.org/3/search/tv?api_key={$apiKey}&query={$encoded}&language=fr&page=1",
        "https://api.themoviedb.org/3/search/movie?api_key={$apiKey}&query={$encoded}&language=fr&page=1",
    ];
    if ($year) {
        $urls[] = "https://api.themoviedb.org/3/search/multi?api_key={$apiKey}&query=" . urlencode($title . ' ' . $year) . "&language=fr&page=1";
    }
    foreach ($urls as $searchUrl) {
        $data = tmdb_fetch($searchUrl, $ctx);
        if (!$data || empty($data['results'])) continue;
        foreach ($data['results'] as $r) {
            if (empty($r['poster_path']) || isset($seenIds[$r['id']])) continue;
            $seenIds[$r['id']] = true;
            $candidates[] = [
                'id' => $r['id'],
                'title' => $r['title'] ?? $r['name'] ?? '?',
                'year' => substr($r['release_date'] ?? $r['first_air_date'] ?? '', 0, 4),
                'type' => $r['media_type'] ?? ($r['first_air_date'] ?? false ? 'tv' : 'movie'),
                'overview' => substr($r['overview'] ?? '', 0, 150),
                'poster' => 'https://image.tmdb.org/t/p/w300' . $r['poster_path'],
                'rating' => round((float)($r['vote_average'] ?? 0), 1),
                'vote_count' => (int)($r['vote_count'] ?? 0),
            ];
            if (count($candidates) >= $limit) break 2;
        }
        usleep(50000);
    }
    return $candidates;
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
        $s = @iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s;
        $s = preg_replace('/[^a-z0-9 ]/', '', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    };
    $a = $norm($extractedTitle);
    $b = $norm($candidate['title'] ?? '');
    if ($a === '' || $b === '') return 0;

    similar_text($a, $b, $pct);
    $score += (int)round($pct * 0.65);

    // Bonus: one title contains the other entirely (handles "Naruto" vs "Naruto Shippuden")
    if ($a !== $b && (str_contains($b, $a) || str_contains($a, $b))) {
        $score = max($score, 40); // floor at 40 if substring match
    }

    // ── Year (0-15 points) ──
    $cYear = (int)($candidate['year'] ?? 0);
    if ($extractedYear && $cYear) {
        if ($cYear === $extractedYear) {
            $score += 15;
        } elseif (abs($cYear - $extractedYear) <= 1) {
            $score += 10;
        }
    } elseif (!$extractedYear && $cYear) {
        // No year in filename — slight bonus (not penalised)
        $score += 5;
    }

    // ── Type coherence (0-10 points) ──
    $cType = $candidate['type'] ?? '';
    if ($preferTv && $cType === 'tv') {
        $score += 10;
    } elseif (!$preferTv && $cType === 'movie') {
        $score += 10;
    } elseif ($cType === 'tv' || $cType === 'movie') {
        $score += 3; // wrong preference but still valid media
    }

    // ── Popularity (0-10 points) — avoid obscure homonyms ──
    $voteCount = (int)($candidate['vote_count'] ?? 0);
    if ($voteCount > 500) {
        $score += 10;
    } elseif ($voteCount > 100) {
        $score += 7;
    } elseif ($voteCount > 10) {
        $score += 3;
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

// ── FFmpeg helpers ──────────────────────────────────────────────────────────

const ALLOWED_QUALITIES = [480, 576, 720, 1080];
const ALLOWED_FILTER_MODES = ['none', 'anime', 'detail', 'night', 'deinterlace', 'hdr'];

function validateQuality(int $quality): int {
    return in_array($quality, ALLOWED_QUALITIES, true) ? $quality : 720;
}

function validateFilterMode(string $mode): string {
    return in_array($mode, ALLOWED_FILTER_MODES, true) ? $mode : 'none';
}

function validateBurnSub(int $burnSub): int {
    return ($burnSub >= 0 && $burnSub < 50) ? $burnSub : -1;
}

/**
 * Détecte automatiquement si un fichier nécessite le filtre HDR→SDR.
 * @param PDO $db Database connection
 * @param string $path Chemin absolu du fichier
 * @return bool True si le fichier est en HDR (smpte2084, arib-std-b67, smpte428)
 */
function isHDRFile(PDO $db, string $path): bool {
    try {
        $stmt = $db->prepare("SELECT result FROM probe_cache WHERE path = :p");
        $stmt->execute([':p' => $path]);
        if ($row = $stmt->fetch()) {
            $data = json_decode($row['result'], true);
            $ct = $data['colorTransfer'] ?? '';
            return in_array($ct, ['smpte2084', 'arib-std-b67', 'smpte428'], true);
        }
    } catch (PDOException $e) { /* ignore */ }
    return false;
}

/**
 * Construit le filter_complex ffmpeg pour transcode (avec ou sans burn-in sous-titre).
 */
function buildFilterGraph(int $quality, int $audioTrack, int $burnSub = -1, string $filterMode = 'none'): string {
    // Construit la chaîne de filtres vidéo selon le mode
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

    if ($burnSub >= 0) {
        return '"[0:s:' . $burnSub . '][0:v]scale2ref[ss][sv];[sv][ss]overlay=eof_action=pass[ov];'
            . '[ov]' . $videoFilters . '[v];'
            . '[0:a:' . $audioTrack . ']aresample=async=3000[a]"';
    }
    return '"[0:v:0]' . $videoFilters . '[v];'
        . '[0:a:' . $audioTrack . ']aresample=async=3000[a]"';
}

/**
 * Construit les arguments d'entrée ffmpeg communs.
 */
function buildFfmpegInputArgs(string $filePath, string $seekBefore = ''): string {
    return 'timeout 14400 ionice -c 2 -n 0 nice -n 5 ffmpeg -nostdin' . $seekBefore . ' -thread_queue_size 512 -fflags +genpts+discardcorrupt -i ' . escapeshellarg($filePath);
}

/**
 * Construit les arguments encodeur x264+AAC communs.
 */
function buildFfmpegCodecArgs(int $gopSize = FFMPEG_GOP_SIZE_DEFAULT, bool $isHDR = false, bool $isHLS = false): string {
    $threads = $isHDR ? FFMPEG_HDR_THREADS : FFMPEG_THREADS;
    $crf = $isHDR ? FFMPEG_HDR_CRF : FFMPEG_CRF;
    $preset = $isHLS ? FFMPEG_PRESET_HLS : FFMPEG_PRESET;
    $args = ' -c:v libx264 -preset ' . $preset . ' -tune film -crf ' . $crf
        . ' -profile:v high -level 4.1'
        . ' -bf 3 -refs 4'
        . ' -g ' . $gopSize . ' -threads ' . $threads
        . ' -colorspace bt709 -color_primaries bt709 -color_trc bt709'
        . ' -c:a aac -ac ' . FFMPEG_AUDIO_CHANNELS . ' -b:a ' . FFMPEG_AUDIO_BITRATE . ' -shortest';
    if ($isHLS) {
        $args .= ' -force_key_frames "expr:gte(t,n_forced*4)"';
    }
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
        $db->exec("DELETE FROM activity_logs WHERE created_at < datetime('now', '-90 days')");
    } catch (\Throwable $e) {
        // Silencieux — les logs ne doivent pas casser l'app
    }
}
