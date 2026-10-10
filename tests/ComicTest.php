<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../comic.php';
require_once __DIR__ . '/ComicFixtures.php';

class ComicTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sb_comic_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        ComicFixtures::rmrf($this->dir);
    }

    private function p(string $name): string
    {
        return "{$this->dir}/$name";
    }

    private function needRar(): void
    {
        if (!ComicFixtures::rarAvailable()) $this->markTestSkipped('rar/unrar not installed');
    }

    // ---- comic_format

    public function testFormatZip(): void
    {
        ComicFixtures::zip($this->p('a.cbz'), ['1.png' => ComicFixtures::png(1, 2, 3)]);
        $this->assertSame('zip', comic_format($this->p('a.cbz')));
    }

    public function testFormatRar4AndRar5(): void
    {
        $this->needRar();
        $e = ['1.png' => ComicFixtures::png(1, 2, 3)];
        if (ComicFixtures::rar($this->p('v4.cbr'), $e, ['-ma4'])) {
            $this->assertSame("Rar!\x1a\x07\x00", substr((string)file_get_contents($this->p('v4.cbr')), 0, 7));
            $this->assertSame('rar', comic_format($this->p('v4.cbr')));
        }
        $this->assertTrue(ComicFixtures::rar($this->p('v5.cbr'), $e, ['-ma5']));
        $this->assertSame('rar', comic_format($this->p('v5.cbr')));
    }

    public function testFormatFollowsTheBytesNotTheExtension(): void
    {
        $this->needRar();
        $e = ['1.png' => ComicFixtures::png(1, 2, 3)];
        ComicFixtures::zip($this->p('zip.cbr'), $e);
        ComicFixtures::rar($this->p('rar.cbz'), $e);
        $this->assertSame('zip', comic_format($this->p('zip.cbr')));
        $this->assertSame('rar', comic_format($this->p('rar.cbz')));
    }

    public function testFormatRejectsEverythingElse(): void
    {
        file_put_contents($this->p('t.cbz'), "just text\n");
        file_put_contents($this->p('e.cbz'), '');
        file_put_contents($this->p('short.cbz'), 'PK');
        file_put_contents($this->p('almost.cbr'), "Rar!\x1a\x07\x02");
        mkdir($this->p('d.cbz'));
        $this->assertNull(comic_format($this->p('t.cbz')));
        $this->assertNull(comic_format($this->p('e.cbz')));
        $this->assertNull(comic_format($this->p('short.cbz')));
        $this->assertNull(comic_format($this->p('almost.cbr')));
        $this->assertNull(comic_format($this->p('d.cbz')));
        $this->assertNull(comic_format($this->p('missing.cbz')));
    }

    // ---- comic_pages

    public function testPagesFilterAndNaturalOrder(): void
    {
        $png = ComicFixtures::png(9, 9, 9);
        ComicFixtures::zip($this->p('a.cbz'), [
            'p10.png' => $png, 'p2.png' => $png, 'p1.png' => $png, 'P3.PNG' => $png,
            'Sub/p1.webp' => $png, 'Sub/a.gif' => $png, 'x.jpeg' => $png, 'y.AVIF' => $png, 'z.jpg' => $png,
            'Dir/' => '', 'notes.txt' => 'x', 'ComicInfo.xml' => '<x/>', 'thumbs.db' => 'x', 'noext' => $png,
            'img.png.txt' => $png, 'img.svg' => $png,
            '__MACOSX/p1.png' => $png, 'a/__MACOSX/p2.png' => $png, '__MACOSX' => $png,
            '.hidden.png' => $png, 'Sub/.p9.png' => $png,
            'star*.png' => $png, 'q?.png' => $png, "ctl\x01.png" => $png, "tab\t.png" => $png,
        ]);
        $this->assertSame(
            ['p1.png', 'p2.png', 'P3.PNG', 'p10.png', 'Sub/a.gif', 'Sub/p1.webp', 'x.jpeg', 'y.AVIF', 'z.jpg'],
            comic_pages($this->p('a.cbz'))
        );
    }

    public function testPagesNaturalOrderAcrossFolders(): void
    {
        $png = ComicFixtures::png(9, 9, 9);
        ComicFixtures::zip($this->p('a.cbz'), ['Ch2/p1.png' => $png, 'ch1/p10.png' => $png, 'Ch1/p9.png' => $png]);
        $this->assertSame(['Ch1/p9.png', 'ch1/p10.png', 'Ch2/p1.png'], comic_pages($this->p('a.cbz')));
    }

    public function testArchiveWithoutImageHasNoPages(): void
    {
        ComicFixtures::zip($this->p('e.cbz'), ['readme.txt' => 'x', 'Dir/' => '']);
        $this->assertSame([], comic_pages($this->p('e.cbz')));
    }

    public function testPagesAreCappedAtTenThousand(): void
    {
        $png = ComicFixtures::png(0, 0, 0);
        $entries = [];
        for ($i = 10005; $i >= 1; $i--) $entries[sprintf('p%05d.png', $i)] = $png;
        ComicFixtures::zip($this->p('big.cbz'), $entries);
        $pages = comic_pages($this->p('big.cbz'));
        $this->assertCount(10000, $pages);
        $this->assertSame('p00001.png', $pages[0]);
        $this->assertSame('p10000.png', $pages[9999]);
    }

    public function testUnreadableInputsGiveNull(): void
    {
        file_put_contents($this->p('t.cbz'), 'not an archive');
        file_put_contents($this->p('e.cbz'), '');
        file_put_contents($this->p('junk.cbz'), "PK\x03\x04" . random_bytes(300));
        file_put_contents($this->p('junk.cbr'), "Rar!\x1a\x07\x00" . random_bytes(300));
        $this->assertNull(comic_pages($this->p('t.cbz')));
        $this->assertNull(comic_pages($this->p('e.cbz')));
        $this->assertNull(comic_pages($this->p('junk.cbz')));
        $this->assertNull(comic_pages($this->p('junk.cbr')));
        $this->assertNull(comic_pages($this->p('missing.cbz')));
    }

    public function testTruncatedZipGivesNull(): void
    {
        $png = ComicFixtures::png(1, 1, 1);
        ComicFixtures::zip($this->p('ok.cbz'), ['1.png' => $png, '2.png' => $png, '3.png' => $png]);
        $bytes = (string)file_get_contents($this->p('ok.cbz'));
        file_put_contents($this->p('cut.cbz'), substr($bytes, 0, (int)(strlen($bytes) / 2)));
        $this->assertNull(comic_pages($this->p('cut.cbz')));
    }

    public function testRarPagesLikeZip(): void
    {
        $this->needRar();
        $png = ComicFixtures::png(5, 5, 5);
        $e = ['p10.png' => $png, 'p2.png' => $png, 'notes.txt' => 'x', 'Sub/p1.jpg' => $png, '.hid.png' => $png];
        $this->assertTrue(ComicFixtures::rar($this->p('a.cbr'), $e));
        $this->assertSame(['p2.png', 'p10.png', 'Sub/p1.jpg'], comic_pages($this->p('a.cbr')));
    }

    public function testSolidRarIsListed(): void
    {
        $this->needRar();
        $png = ComicFixtures::png(5, 5, 5);
        $this->assertTrue(ComicFixtures::rar($this->p('s.cbr'), ['2.png' => $png, '1.png' => $png], ['-s']));
        $this->assertSame(['1.png', '2.png'], comic_pages($this->p('s.cbr')));
    }

    /** @return array{0:bool, 1:string} */
    private function sendPage(string $path, int $index): array
    {
        $entries = comic_page_entries($path);
        $this->assertNotNull($entries);
        ob_start();
        $ok = @comic_send_page($path, $entries[$index]);
        return [$ok, (string)ob_get_clean()];
    }

    // '@x' is a list file for unrar even after '--', '-x' looks like a switch: both must come back as their own bytes.
    public function testRarEntriesNamedLikeSwitchesOrListFilesAreServedVerbatim(): void
    {
        $this->needRar();
        $at = ComicFixtures::png(1, 2, 3);
        $dash = ComicFixtures::png(4, 5, 6);
        $plain = ComicFixtures::png(7, 8, 9);
        $this->assertTrue(ComicFixtures::rar($this->p('h.cbr'), ['@1.png' => $at, '-2.png' => $dash, '3.png' => $plain]));
        $pages = comic_pages($this->p('h.cbr'));
        $this->assertSame(['-2.png', '3.png', '@1.png'], $pages);
        $bytes = ['-2.png' => $dash, '3.png' => $plain, '@1.png' => $at];
        foreach ($pages as $i => $name) {
            [$ok, $out] = $this->sendPage($this->p('h.cbr'), $i);
            $this->assertTrue($ok, $name);
            $this->assertSame($bytes[$name], $out, $name);
        }
    }

    // Regression: a CRC error used to stream a truncated image with a 200.
    public function testCorruptRarPageSendsNothing(): void
    {
        $this->needRar();
        $big = '';
        for ($i = 0; $i < 40; $i++) $big .= ComicFixtures::png($i, 255 - $i, 7);
        $this->assertTrue(ComicFixtures::rar($this->p('c.cbr'), ['1.png' => $big]));
        $raw = (string)file_get_contents($this->p('c.cbr'));
        $mid = intdiv(strlen($raw), 2);
        $raw[$mid] = chr(ord($raw[$mid]) ^ 0xff);
        file_put_contents($this->p('c.cbr'), $raw);
        $entries = comic_page_entries($this->p('c.cbr'));
        if ($entries === null) {
            $this->assertNull($entries);
            return;
        }
        [$ok, $out] = $this->sendPage($this->p('c.cbr'), 0);
        $this->assertFalse($ok);
        $this->assertSame('', $out);
    }

    public function testRarWithoutImageAndRarNamedCbzAndZipNamedCbr(): void
    {
        $this->needRar();
        $png = ComicFixtures::png(5, 5, 5);
        ComicFixtures::rar($this->p('none.cbr'), ['a.txt' => 'x']);
        ComicFixtures::rar($this->p('r.cbz'), ['1.png' => $png]);
        ComicFixtures::zip($this->p('z.cbr'), ['1.png' => $png]);
        $this->assertSame([], comic_pages($this->p('none.cbr')));
        $this->assertSame(['1.png'], comic_pages($this->p('r.cbz')));
        $this->assertSame(['1.png'], comic_pages($this->p('z.cbr')));
    }

    // ---- comic_page_index

    /** @dataProvider indexProvider */
    public function testPageIndex(mixed $raw, int $count, ?int $expected): void
    {
        $this->assertSame($expected, comic_page_index($raw, $count));
    }

    public static function indexProvider(): array
    {
        return [
            'first' => ['1', 3, 0],
            'middle' => ['2', 3, 1],
            'last' => ['3', 3, 2],
            'past the end' => ['4', 3, null],
            'zero' => ['0', 3, null],
            'negative' => ['-1', 3, null],
            'leading zero' => ['01', 3, null],
            'decimal' => ['1.5', 3, null],
            'plus sign' => ['+1', 3, null],
            'exponent' => ['1e0', 3, null],
            'hex' => ['0x1', 3, null],
            'empty' => ['', 3, null],
            'leading space' => [' 1', 3, null],
            'trailing space' => ['1 ', 3, null],
            // PCRE '$' also matches before a final newline.
            'trailing newline' => ["1\n", 3, null],
            'letters' => ['abc', 3, null],
            'non-ASCII digit' => ["\u{0663}", 3, null],
            'array' => [['1'], 3, null],
            'null' => [null, 3, null],
            'huge' => ['99999999999999999999', 3, null],
            'seven digits' => ['1000000', 3, null],
            'no pages' => ['1', 0, null],
        ];
    }

    // ---- comic_page_mime

    /** @dataProvider mimeProvider */
    public function testPageMime(string $entry, string $expected): void
    {
        $this->assertSame($expected, comic_page_mime($entry));
    }

    public static function mimeProvider(): array
    {
        return [
            'jpg' => ['a/b.jpg', 'image/jpeg'],
            'jpeg upper' => ['B.JPEG', 'image/jpeg'],
            'png' => ['p.png', 'image/png'],
            'webp' => ['p.WebP', 'image/webp'],
            'gif' => ['p.gif', 'image/gif'],
            'avif' => ['p.avif', 'image/avif'],
        ];
    }
}
