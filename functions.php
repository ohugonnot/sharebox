<?php
/**
 * Fonctions pures réutilisables — extraites de download.php et ctrl.php
 */

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
    $deadline = microtime(true) + 15; // max 15s d'attente
    while (microtime(true) < $deadline) {
        if (flock($fp, LOCK_EX | LOCK_NB)) return [$fp, true];
        if (connection_aborted()) { fclose($fp); exit; }
        usleep(100000); // 100ms entre chaque essai
    }
    fclose($fp);
    http_response_code(503);
    exit;
}

function releaseStreamSlot($fp): void {
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
 * Retourne 'video', 'audio' ou null selon l'extension du fichier
 */
function get_media_type(string $filename): ?string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mime = get_stream_mime($ext);
    if (!$mime) return null;
    return str_starts_with($mime, 'video/') ? 'video' : 'audio';
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
