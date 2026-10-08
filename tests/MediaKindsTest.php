<?php

use PHPUnit\Framework\TestCase;

class MediaKindsTest extends TestCase
{
    /**
     * @dataProvider folderKindProvider
     * @param string[] $names
     */
    public function testFolderMediaKind(array $names, ?string $expected): void
    {
        $this->assertSame($expected, folder_media_kind($names));
    }

    /** @return string[] */
    private static function numbered(int $n, string $ext): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = sprintf('%02d.%s', $i, $ext);
        }
        return $out;
    }

    public static function folderKindProvider(): array
    {
        return [
            'vide' => [[], null],
            'nfo + cover seuls' => [['x.nfo', 'cover.jpg'], null],
            'texte seul' => [['a.txt'], null],
            'une seule piste' => [['a.mp3'], null],
            'deux pistes' => [['a.mp3', 'b.mp3'], 'audiobook'],
            '20 pistes + annexes' => [
                array_merge(self::numbered(20, 'mp3'), ['cover.jpg', 'x.nfo', 'a.m3u', 'a.cue', 'rip.log', 'a.sfv']),
                'audiobook',
            ],
            'casse mixte + m4b' => [['A.MP3', 'b.M4B'], 'audiobook'],
            '12 pistes + livret pdf' => [array_merge(self::numbered(12, 'mp3'), ['livret.pdf']), 'audiobook'],
            'égalité 2 pistes 2 pdf' => [['a.mp3', 'b.mp3', 'a.pdf', 'b.pdf'], 'audiobook'],
            '1 piste + 1 pdf' => [['a.mp3', 'a.pdf'], 'library'],
            'un pdf' => [['a.pdf'], 'library'],
            'un epub' => [['a.epub'], 'library'],
            'pdf + epub + cover' => [['a.pdf', 'b.epub', 'cover.jpg'], 'library'],
            'une vidéo bloque l\'audio' => [array_merge(self::numbered(10, 'mp3'), ['clip.mp4']), null],
            'une vidéo bloque les livres' => [['a.pdf', 'b.pdf', 'film.mkv'], null],
            'un ts bloque' => [['a.pdf', 'ep.ts'], null],
            'mp3 dans le nom seulement' => [['mp3.txt'], null],
            'sans point' => [['filemp3'], null],
            'double extension' => [['a.pdf.txt'], null],
            'nom pdf sans extension' => [['pdf'], null],
            '2 pistes + 30 zip' => [array_merge(['a.mp3', 'b.mp3'], self::numbered(30, 'zip')), 'audiobook'],
            'parent de CD1/CD2 sans fichier' => [[], null],
        ];
    }

    /**
     * @dataProvider coverProvider
     * @param string[] $names
     */
    public function testFolderCover(array $names, ?string $expected): void
    {
        $this->assertSame($expected, folder_cover($names));
    }

    public static function coverProvider(): array
    {
        return [
            'cover avant une autre image' => [['01.mp3', 'scan.jpg', 'cover.jpg'], 'cover.jpg'],
            'Folder en majuscules' => [['Folder.JPG'], 'Folder.JPG'],
            'aucune image' => [['a.mp3'], null],
        ];
    }

    /**
     * @dataProvider readerKindProvider
     */
    public function testReaderKind(string $name, ?string $expected): void
    {
        $this->assertSame($expected, reader_kind($name));
    }

    public static function readerKindProvider(): array
    {
        return [
            'pdf' => ['a.pdf', 'pdf'],
            'epub en majuscules' => ['A.EPUB', 'epub'],
            'audio' => ['a.mp3', null],
            'double extension' => ['a.pdf.txt', null],
            'nom pdf sans extension' => ['pdf', null],
        ];
    }
}
