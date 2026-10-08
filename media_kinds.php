<?php
// Pure: works on names already listed, so the listing pays no extra I/O.

const MEDIA_AUDIO_EXTS = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'oga', 'opus', 'aac', 'wav'];
const MEDIA_BOOK_EXTS  = ['pdf', 'epub'];
const MEDIA_VIDEO_EXTS = ['mkv', 'mp4', 'avi', 'mov', 'webm', 'm4v', 'ts', 'wmv', 'mpg', 'mpeg', 'flv', '3gp', 'm2ts'];
const MEDIA_IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

function media_ext(string $name): string {
    return strtolower(pathinfo($name, PATHINFO_EXTENSION));
}

/**
 * 'audiobook', 'library' ou null pour les fichiers (non-dossiers) d'un dossier
 *
 * @param string[] $names
 */
function folder_media_kind(array $names): ?string {
    $audio = 0;
    $books = 0;
    foreach ($names as $name) {
        $ext = media_ext($name);
        // A single video means a film or series folder: the video player owns it.
        if (in_array($ext, MEDIA_VIDEO_EXTS, true)) return null;
        if (in_array($ext, MEDIA_AUDIO_EXTS, true)) $audio++;
        elseif (in_array($ext, MEDIA_BOOK_EXTS, true)) $books++;
    }
    if ($audio >= 2 && $audio >= $books) return 'audiobook';
    if ($books >= 1) return 'library';
    return null;
}

/**
 * Image de couverture : cover.*, folder.*, front.*, sinon la première image
 *
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

/**
 * 'pdf', 'epub' ou null selon l'extension
 */
function reader_kind(string $name): ?string {
    $ext = media_ext($name);
    return in_array($ext, MEDIA_BOOK_EXTS, true) ? $ext : null;
}

/**
 * Mime servi par ?inline=1 ; null = refusé (404)
 */
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
