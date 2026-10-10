<?php
// Pure: works on names already listed, so the listing pays no extra I/O.

const MEDIA_AUDIO_EXTS = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'oga', 'opus', 'aac', 'wav'];
const MEDIA_BOOK_EXTS  = ['pdf', 'epub'];
const MEDIA_COMIC_EXTS = ['cbz', 'cbr'];
const MEDIA_VIDEO_EXTS = ['mkv', 'mp4', 'avi', 'mov', 'webm', 'm4v', 'ts', 'wmv', 'mpg', 'mpeg', 'flv', '3gp', 'm2ts'];
const MEDIA_IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

// EPUB needs foliate-js, not shipped yet.
defined('READER_EPUB_ENABLED') || define('READER_EPUB_ENABLED', false);

function media_ext(string $name): string {
    return strtolower(pathinfo($name, PATHINFO_EXTENSION));
}

/**
 * Expects file names only: the caller drops folders, even ones named like media.
 *
 * @param string[] $names
 */
function folder_media_kind(array $names, bool $epub = READER_EPUB_ENABLED): ?string {
    foreach ($names as $name) {
        // A single video means a film or series folder: the video player owns it.
        if (in_array(media_ext($name), MEDIA_VIDEO_EXTS, true)) return null;
    }
    $audio = count(audio_names($names));
    // Only books the reader can open: unreadable epubs must not outvote the tracks.
    $books = count(readable_book_names($names, $epub));
    if ($audio >= 2 && $audio >= $books) return 'audiobook';
    if ($books >= 1) return 'library';
    return null;
}

/**
 * @param string[] $names
 * @return string[]
 */
function audio_names(array $names): array {
    $audio = array_values(array_filter($names, fn($n) => in_array(media_ext($n), MEDIA_AUDIO_EXTS, true)));
    usort($audio, 'strnatcasecmp');
    return $audio;
}

/**
 * @param string[] $names
 * @return string[]
 */
function readable_book_names(array $names, bool $epub = READER_EPUB_ENABLED): array {
    $books = array_values(array_filter($names, fn($n) => book_readable($n, $epub)));
    usort($books, 'strnatcasecmp');
    return $books;
}

/**
 * @param string[] $names
 */
function folder_cover(array $names): ?string {
    $images = array_values(array_filter($names, fn($n) => in_array(media_ext($n), MEDIA_IMAGE_EXTS, true)));
    if (!$images) return null;
    usort($images, 'strnatcasecmp');
    foreach (['cover', 'folder', 'front'] as $stem) {
        foreach ($images as $img) {
            if (strtolower(pathinfo($img, PATHINFO_FILENAME)) === $stem) return $img;
        }
    }
    return $images[0];
}

function reader_kind(string $name): ?string {
    $ext = media_ext($name);
    if (in_array($ext, MEDIA_COMIC_EXTS, true)) return 'comic';
    return in_array($ext, MEDIA_BOOK_EXTS, true) ? $ext : null;
}

function inline_mime(string $name): ?string {
    return match (media_ext($name)) {
        'pdf' => 'application/pdf',
        'epub' => 'application/epub+zip',
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        default => null,
    };
}

function share_entry_url(string $token, string $subPath, string $name, string $mode): string {
    $path = $subPath !== '' ? $subPath . '/' . $name : $name;
    return '/dl/' . $token . '?p=' . rawurlencode($path) . '&' . $mode . '=1';
}

/**
 * @param string[] $names
 * @return array{cover:?string, backUrl:string, storageToken:string}
 */
function media_page_common(array $names, string $token, string $subPath): array {
    $cover = folder_cover($names);
    return [
        'cover' => $cover !== null ? share_entry_url($token, $subPath, $cover, 'inline') : null,
        'backUrl' => '/dl/' . $token . ($subPath !== '' ? '?p=' . rawurlencode($subPath) : ''),
        // The portal may run on the same origin: 'sb:' keeps our localStorage keys apart.
        'storageToken' => 'sb:' . $token . ':' . $subPath,
    ];
}

/**
 * @param array<int, array{name:string, size:int, mtime:int}> $files
 * @return array{books:list<array{name:string,url:string,kind:string,size:int,mtime:int,download?:string}>, open:?string, libBase:string, cover:?string, backUrl:string, storageToken:string}
 */
function reader_page_data(array $files, string $token, string $subPath, ?string $book, bool $epub = false): array {
    $books = [];
    foreach ($files as $f) {
        // Same rule as readable_book_names(), which the listing counts with.
        if (!book_readable($f['name'], $epub)) continue;
        $entry = [
            'name' => $f['name'],
            'url' => share_entry_url($token, $subPath, $f['name'], 'inline'),
            'kind' => (string)reader_kind($f['name']),
            'size' => max(0, (int)$f['size']),
            'mtime' => (int)$f['mtime'],
        ];
        // A comic's inline URL only answers page requests: the Download link needs the plain one.
        if ($entry['kind'] === 'comic') {
            $entry['download'] = '/dl/' . $token . '?p=' . rawurlencode($subPath !== '' ? $subPath . '/' . $f['name'] : $f['name']);
        }
        $books[] = $entry;
    }
    usort($books, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    $open = $book !== null && in_array($book, array_column($books, 'name'), true) ? $book : null;
    return ['books' => $books, 'open' => $open, 'libBase' => '/share/reader/']
        + media_page_common(array_column($files, 'name'), $token, $subPath);
}

/**
 * @param string[] $names
 * @return array{tracks:list<array{name:string,url:string}>, cover:?string, backUrl:string, storageToken:string}
 */
function audiobook_page_data(array $names, string $token, string $subPath): array {
    $tracks = array_map(fn($n) => ['name' => $n, 'url' => share_entry_url($token, $subPath, $n, 'stream')], audio_names($names));
    return ['tracks' => $tracks] + media_page_common($names, $token, $subPath);
}

/**
 * JSON sûr dans un <script type="application/json"> (noms de fichiers hostiles)
 */
function page_json(mixed $data): string {
    $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    return $json === false ? '[]' : $json;
}

function book_readable(string $name, bool $epub = READER_EPUB_ENABLED): bool {
    $kind = reader_kind($name);
    return $kind === 'pdf' || $kind === 'comic' || ($kind === 'epub' && $epub);
}

/**
 * @param string[] $names
 * @return array{rowRead:bool, toolbar:?array{param:string, label:string}}
 */
function listing_actions(array $names, bool $epub = READER_EPUB_ENABLED): array {
    $kind = folder_media_kind($names, $epub);
    $nBooks = count(readable_book_names($names, $epub));
    $toolbar = null;
    if ($kind === 'audiobook') {
        $toolbar = ['param' => 'listen', 'label' => 'Écouter · ' . count(audio_names($names)) . ' pistes'];
    } elseif ($kind === 'library' && $nBooks >= 2) {
        $toolbar = ['param' => 'read', 'label' => 'Bibliothèque · ' . $nBooks . ' livres'];
    }
    // A null kind with books means a video folder: the video player owns it.
    return ['rowRead' => $kind !== null, 'toolbar' => $toolbar];
}
