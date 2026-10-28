<?php

/** Builds tiny CBZ/CBR archives on the fly for the comic tests. */
final class ComicFixtures
{
    public const RAR = '/usr/bin/rar';

    /** Solid-colour PNG: each colour gives different bytes, so a wrong page is detectable. */
    public static function png(int $r, int $g, int $b): string
    {
        $im = imagecreatetruecolor(8, 8);
        imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
        ob_start();
        imagepng($im);
        return (string)ob_get_clean();
    }

    /** @param array<string,string> $entries name => bytes; a name ending in '/' is a directory */
    public static function zip(string $path, array $entries): void
    {
        $z = new ZipArchive();
        if ($z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("cannot create $path");
        }
        foreach ($entries as $name => $bytes) {
            if (str_ends_with($name, '/')) $z->addEmptyDir(rtrim($name, '/'));
            else $z->addFromString($name, $bytes);
        }
        $z->close();
    }

    public static function rarAvailable(): bool
    {
        return is_executable(self::RAR) && is_executable('/usr/bin/unrar');
    }

    /**
     * @param array<string,string> $entries name => bytes
     * @param string[] $flags extra rar switches, e.g. ['-s'] (solid) or ['-ma4'] (RAR4)
     */
    public static function rar(string $path, array $entries, array $flags = []): bool
    {
        $tmp = sys_get_temp_dir() . '/sb_comicsrc_' . bin2hex(random_bytes(6));
        mkdir($tmp, 0755, true);
        try {
            foreach ($entries as $name => $bytes) {
                @mkdir(dirname("$tmp/$name"), 0755, true);
                file_put_contents("$tmp/$name", $bytes);
            }
            @unlink($path);
            // Names go through a list file: rar reads '-x' as a switch and '@x' as a list file.
            $list = $tmp . '.lst';
            file_put_contents($list, implode("\n", array_keys($entries)) . "\n");
            $cmd = array_merge([self::RAR, 'a', '-inul', '-idq'], $flags, ['--', $path, '@' . $list]);
            $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $tmp);
            $code = is_resource($p) ? proc_close($p) : -1;
            return $code === 0 && is_file($path);
        } finally {
            @unlink($tmp . '.lst');
            self::rmrf($tmp);
        }
    }

    public static function rmrf(string $dir): void
    {
        if (!preg_match('#/sb_[a-z]+_[0-9a-f]{12}#', $dir)) return;
        shell_exec('rm -rf -- ' . escapeshellarg($dir));
    }
}
