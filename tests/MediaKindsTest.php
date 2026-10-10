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
        $this->assertSame($expected, folder_media_kind($names, true));
    }

    public function testEpubCountsOnlyWhenTheReaderCanOpenIt(): void
    {
        // Regression: 3 unreadable epubs outvoted 2 tracks, and the folder got no button at all.
        $names = ['a.mp3', 'b.mp3', 'x.epub', 'y.epub', 'z.epub'];
        $this->assertSame('audiobook', folder_media_kind($names, false));
        $this->assertSame('library', folder_media_kind($names, true));
        $this->assertNull(folder_media_kind(['a.epub'], false));
        $this->assertSame('library', folder_media_kind(['a.epub', 'b.pdf'], false));
    }

    public function testAudioNamesAreNaturallySorted(): void
    {
        $this->assertSame(['1.mp3', '2.M4B', '10.flac'], audio_names(['10.flac', 'cover.jpg', '2.M4B', 'a.pdf', '1.mp3', 'clip.mp4']));
    }

    public function testReadableBookNamesFollowTheEpubFlag(): void
    {
        $names = ['b.epub', 'a.PDF', 'c.mp3', 'd.pdf.txt'];
        $this->assertSame(['a.PDF'], readable_book_names($names, false));
        $this->assertSame(['a.PDF', 'b.epub'], readable_book_names($names, true));
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
            // Regression: more books than tracks used to fall through to null.
            '2 pistes + 3 pdf' => [['a.mp3', 'b.mp3', 'a.pdf', 'b.pdf', 'c.pdf'], 'library'],
            '3 pistes + 3 pdf' => [['a.mp3', 'b.mp3', 'c.mp3', 'a.pdf', 'b.pdf', 'c.pdf'], 'audiobook'],
            '1 piste seule' => [['01.mp3'], null],
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
            'cover avant la première image' => [['a.jpg', 'cover.png'], 'cover.png'],
            'folder avant front' => [['a.jpg', 'front.png', 'folder.png'], 'folder.png'],
            'sinon la première en tri naturel' => [['b.jpg', 'a.jpg'], 'a.jpg'],
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
            'cbz' => ['a.cbz', 'comic'],
            'cbr en majuscules' => ['X.CBR', 'comic'],
            'cbz mixed case' => ['Tome 1.CbZ', 'comic'],
            'cb7 non géré' => ['a.cb7', null],
            'cbt non géré' => ['a.cbt', null],
            'double extension cbz' => ['a.cbz.txt', null],
            'nom cbz sans extension' => ['cbz', null],
            'audio' => ['a.mp3', null],
            'double extension' => ['a.pdf.txt', null],
            'nom pdf sans extension' => ['pdf', null],
        ];
    }

    /**
     * @dataProvider inlineMimeProvider
     */
    public function testInlineMimeWhitelist(string $name, ?string $expected): void
    {
        $this->assertSame($expected, inline_mime($name));
    }

    public static function inlineMimeProvider(): array
    {
        return [
            'pdf' => ['a.PDF', 'application/pdf'],
            'epub' => ['a.epub', 'application/epub+zip'],
            'jpg' => ['a.jpg', 'image/jpeg'],
            'jpeg' => ['a.jpeg', 'image/jpeg'],
            'png' => ['a.png', 'image/png'],
            'webp' => ['a.webp', 'image/webp'],
            'gif' => ['a.gif', 'image/gif'],
            // ?inline=1 alone must never hand out the whole archive.
            'cbz' => ['a.cbz', null],
            'cbr' => ['a.CBR', null],
            'html' => ['a.html', null],
            'svg' => ['a.svg', null],
            'mp3' => ['a.mp3', null],
            'double extension' => ['a.pdf.php', null],
            'no extension' => ['pdf', null],
        ];
    }

    public function testComicsMakeALibraryWhateverTheEpubFlag(): void
    {
        $this->assertSame('library', folder_media_kind(['a.cbz', 'b.cbr'], false));
        $this->assertSame('library', folder_media_kind(['a.cbz'], true));
        $this->assertSame('library', folder_media_kind(['01.cbz', 'cover.jpg'], false));
    }

    public function testMediaPageNamesSkipFolders(): void
    {
        require_once __DIR__ . '/../views/media_layout.php';
        $dir = sys_get_temp_dir() . '/sb_names_' . bin2hex(random_bytes(6));
        mkdir($dir);
        try {
            foreach (['01.mp3', '02.mp3', '.hidden.mp3'] as $f) touch("$dir/$f");
            mkdir("$dir/x.mp3");
            mkdir("$dir/y.pdf");
            symlink("$dir/x.mp3", "$dir/z.mp3");
            $names = media_page_names($dir);
            $this->assertSame(['01.mp3', '02.mp3'], $names);
            $this->assertSame('audiobook', folder_media_kind($names, false));
        } finally {
            shell_exec('rm -rf -- ' . escapeshellarg($dir));
        }
    }

    public function testPdfIsNotAStreamMime(): void
    {
        // get_media_type() would then call a PDF "audio" and open the video player.
        $this->assertNull(get_stream_mime('pdf'));
        $this->assertNull(get_media_type('a.pdf'));
    }
}
