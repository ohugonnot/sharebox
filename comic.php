<?php
// CBZ/CBR reader backend: lists the pages and sends one page at a time, never the whole archive.

const COMIC_PAGE_EXTS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];
const COMIC_MAX_PAGES = 10000;
// Pages are buffered whole (FPM memory_limit is 128M).
const COMIC_MAX_PAGE_BYTES = 32 * 1024 * 1024;
const COMIC_UNRAR = '/usr/bin/unrar';
// A solid RAR decompresses every page before page N: the last pages of a big tome are slow.
// Nothing is sent before unrar ends, so an abandoned request is only stopped by this timeout.
const COMIC_UNRAR_TIMEOUT = 30;
const COMIC_LIST_TIMEOUT = 30;

function comic_format(string $path): ?string {
    if (!is_file($path)) return null;
    $fh = @fopen($path, 'rb');
    if ($fh === false) return null;
    $head = (string)fread($fh, 8);
    fclose($fh);
    if (str_starts_with($head, "PK\x03\x04")) return 'zip';
    if (str_starts_with($head, "Rar!\x1a\x07\x00") || str_starts_with($head, "Rar!\x1a\x07\x01\x00")) return 'rar';
    return null;
}

function comic_is_page(string $name): bool {
    // '*' and '?' would be masks for unrar; control chars break the line-based listing.
    if ($name === '' || str_ends_with($name, '/') || preg_match('/[*?\x00-\x1f\x7f]/', $name)) return false;
    $segments = explode('/', $name);
    if (in_array('__MACOSX', $segments, true)) return false;
    if (str_starts_with((string)end($segments), '.')) return false;
    return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), COMIC_PAGE_EXTS, true);
}

/**
 * Sorted pages with what is needed to read them back: the zip index, or null for rar.
 *
 * @return ?list<array{name:string, size:int, zip:?int}>
 */
function comic_page_entries(string $path): ?array {
    $entries = match (comic_format($path)) {
        'zip' => comic_zip_entries($path),
        'rar' => comic_rar_entries($path),
        default => null,
    };
    if ($entries === null) return null;
    $entries = array_values(array_filter($entries, fn($e) => comic_is_page($e['name'])));
    usort($entries, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return array_slice($entries, 0, COMIC_MAX_PAGES);
}

/**
 * @return ?list<string>
 */
function comic_pages(string $path): ?array {
    $entries = comic_page_entries($path);
    return $entries === null ? null : array_column($entries, 'name');
}

function comic_page_index(mixed $raw, int $count): ?int {
    if (!is_string($raw) || !preg_match('/^[1-9][0-9]{0,5}$/D', $raw)) return null;
    $n = (int)$raw;
    return $n <= $count ? $n - 1 : null;
}

function comic_page_mime(string $entry): string {
    return match (strtolower(pathinfo($entry, PATHINFO_EXTENSION))) {
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'avif' => 'image/avif',
        default => 'image/jpeg',
    };
}

/**
 * @return ?list<array{name:string, size:int, zip:?int}>
 */
function comic_zip_entries(string $path): ?array {
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) return null;
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $st = $zip->statIndex($i, ZipArchive::FL_ENC_RAW);
        if ($st === false) {
            $zip->close();
            return null;
        }
        $entries[] = ['name' => (string)$st['name'], 'size' => (int)$st['size'], 'zip' => $i];
    }
    $zip->close();
    return $entries;
}

/**
 * @return ?list<array{name:string, size:int, zip:?int}>
 */
function comic_rar_entries(string $path): ?array {
    $out = '';
    // -c- hides the archive comment, which could fake "Name:" lines.
    $rc = comic_unrar(['lt', '-c-', '-cfg-', '-p-', '--', $path], null, function (string $chunk) use (&$out): bool {
        $out .= $chunk;
        return strlen($out) <= 32 * 1024 * 1024;
    }, COMIC_LIST_TIMEOUT);
    if ($rc !== 0) return null;
    $entries = [];
    $block = [];
    // One blank-line separated block per entry; names keep their exact bytes (no trim).
    foreach (explode("\n", $out . "\n") as $line) {
        if (trim($line) === '') {
            if (isset($block['name']) && trim($block['type'] ?? '') === 'File') {
                $entries[] = ['name' => $block['name'], 'size' => (int)($block['size'] ?? 0), 'zip' => null];
            }
            $block = [];
        } elseif (preg_match('/^\s+(Name|Type|Size): (.*)$/', $line, $m)) {
            $block[strtolower($m[1])] ??= $m[2];
        }
    }
    return $entries;
}

/**
 * Runs unrar without a shell. $onChunk gets stdout as it comes and returns false to stop.
 * Returns the exit code, or null when the run was cut (limit, timeout, client gone).
 *
 * @param list<string> $args
 * @param callable(string):bool $onChunk
 */
function comic_unrar(array $args, ?string $stdin, callable $onChunk, int $timeout): ?int {
    // ionice/nice exec unrar, so the pid PHP holds is unrar itself. Best effort class: no starvation behind rtorrent.
    $cmd = array_merge(['/usr/bin/ionice', '-c', '2', '-n', '6', '/usr/bin/nice', '-n', '10', COMIC_UNRAR], $args);
    $spec = [
        0 => $stdin === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ];
    // Without a UTF-8 locale unrar prints non-ASCII names as '?'.
    $proc = proc_open($cmd, $spec, $pipes, null, ['LC_ALL' => 'C.UTF-8', 'PATH' => '/usr/bin:/bin']);
    if (!is_resource($proc)) return null;
    if ($stdin !== null) {
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
    }
    $out = $pipes[1];
    stream_set_blocking($out, false);
    $deadline = microtime(true) + $timeout;
    $cut = false;
    while (!feof($out)) {
        if (microtime(true) > $deadline || connection_aborted()) { $cut = true; break; }
        $r = [$out];
        $w = $e = null;
        $ready = @stream_select($r, $w, $e, 1);
        if ($ready === false) { $cut = true; break; }
        if ($ready === 0) continue;
        $chunk = fread($out, 65536);
        if ($chunk === false) { $cut = true; break; }
        if ($chunk !== '' && !$onChunk($chunk)) { $cut = true; break; }
    }
    if ($cut) proc_terminate($proc, 9);
    fclose($out);
    // proc_close waits for the child: no zombie left behind.
    $rc = proc_close($proc);
    return $cut ? null : $rc;
}

function comic_page_headers(string $entry): void {
    header_remove('Pragma');
    header_remove('Expires');
    header('Content-Type: ' . comic_page_mime($entry));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=86400');
}

/**
 * Sends one page with its headers. Returns false when nothing was sent, so the caller can still answer an error.
 *
 * @param array{name:string, size:int, zip:?int} $entry
 */
function comic_send_page(string $path, array $entry): bool {
    if ($entry['size'] > COMIC_MAX_PAGE_BYTES) return false;
    set_time_limit(COMIC_UNRAR_TIMEOUT + 30);
    if ($entry['zip'] !== null) return comic_send_zip_page($path, $entry);

    // An entry name starting with '@' is a list file for unrar even after '--': pass it through a stdin list file.
    $byList = str_starts_with($entry['name'], '@');
    $args = ['p', '-inul', '-cfg-', '-p-', '--', $path, $byList ? '@/dev/stdin' : $entry['name']];
    // Buffered: a CRC error or a cut run must give an error, not a truncated image with a 200.
    $data = '';
    $rc = comic_unrar($args, $byList ? $entry['name'] . "\n" : null, function (string $chunk) use (&$data): bool {
        $data .= $chunk;
        return strlen($data) <= COMIC_MAX_PAGE_BYTES;
    }, COMIC_UNRAR_TIMEOUT);
    if ($rc !== 0 || $data === '') return false;
    comic_page_headers($entry['name']);
    header('Content-Length: ' . strlen($data));
    echo $data;
    return true;
}

/**
 * @param array{name:string, size:int, zip:?int} $entry
 */
function comic_send_zip_page(string $path, array $entry): bool {
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) return false;
    $in = $zip->getStreamIndex((int)$entry['zip']);
    if ($in === false) {
        $zip->close();
        return false;
    }
    // The declared size can lie (zip bomb): count what really comes out.
    $data = '';
    while (!feof($in) && strlen($data) <= COMIC_MAX_PAGE_BYTES) {
        $chunk = fread($in, 65536);
        if ($chunk === false || $chunk === '') break;
        $data .= $chunk;
    }
    $ok = feof($in) && strlen($data) <= COMIC_MAX_PAGE_BYTES && $data !== '';
    fclose($in);
    $zip->close();
    if (!$ok) return false;
    comic_page_headers($entry['name']);
    header('Content-Length: ' . strlen($data));
    echo $data;
    return true;
}
