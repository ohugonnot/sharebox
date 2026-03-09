<?php

use PHPUnit\Framework\TestCase;

class FormatAndMimeTest extends TestCase
{
    /**
     * @dataProvider formatTailleProvider
     */
    public function testFormatTaille(int $bytes, string $expected): void
    {
        $this->assertSame($expected, format_taille($bytes));
    }

    public static function formatTailleProvider(): array
    {
        return [
            'zero' => [0, '0 o'],
            'octets' => [1023, '1023 o'],
            'kilo exact' => [1024, '1 Ko'],
            'mega exact' => [1048576, '1 Mo'],
            'giga exact' => [1073741824, '1 Go'],
            '1.5 Go' => [1610612736, '1.5 Go'],
        ];
    }

    /**
     * @dataProvider streamMimeProvider
     */
    public function testGetStreamMime(string $ext, ?string $expected): void
    {
        $this->assertSame($expected, get_stream_mime($ext));
    }

    public static function streamMimeProvider(): array
    {
        return [
            'mp4' => ['mp4', 'video/mp4'],
            'mkv' => ['mkv', 'video/x-matroska'],
            'mp3' => ['mp3', 'audio/mpeg'],
            'flac' => ['flac', 'audio/flac'],
            'txt' => ['txt', null],
            'jpg' => ['jpg', null],
        ];
    }

    /**
     * @dataProvider mediaTypeProvider
     */
    public function testGetMediaType(string $filename, ?string $expected): void
    {
        $this->assertSame($expected, get_media_type($filename));
    }

    public static function mediaTypeProvider(): array
    {
        return [
            'video mkv' => ['movie.mkv', 'video'],
            'audio mp3' => ['song.mp3', 'audio'],
            'pdf null' => ['doc.pdf', null],
        ];
    }
}
