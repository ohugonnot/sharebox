<?php

use PHPUnit\Framework\TestCase;

class ReaderPageDataTest extends TestCase
{
    /** @return array<int, array{name:string,size:int,mtime:int}> */
    private static function files(string ...$names): array
    {
        return array_map(fn($n) => ['name' => $n, 'size' => 1000, 'mtime' => 1700000000], $names);
    }

    public function testBooksAreNaturallySortedWithInlineUrls(): void
    {
        $d = reader_page_data(self::files('Tome 10.pdf', 'tome 2.PDF', 'notes.txt', 'Tome 1.pdf'), 'tok', 'Livres/BD', null);

        $this->assertSame(['Tome 1.pdf', 'tome 2.PDF', 'Tome 10.pdf'], array_column($d['books'], 'name'));
        $this->assertSame('/dl/tok?p=Livres%2FBD%2FTome%201.pdf&inline=1', $d['books'][0]['url']);
        $this->assertSame('pdf', $d['books'][1]['kind']);
        $this->assertSame(1000, $d['books'][0]['size']);
        $this->assertSame(1700000000, $d['books'][0]['mtime']);
    }

    public function testRootFolderUrlsAndBackUrl(): void
    {
        $d = reader_page_data(self::files('a.pdf'), 'tok', '', null);
        $this->assertSame('/dl/tok?p=a.pdf&inline=1', $d['books'][0]['url']);
        $this->assertSame('/dl/tok', $d['backUrl']);
        $this->assertSame('sb:tok:', $d['storageToken']);
    }

    public function testBackUrlAndStorageTokenOfSubFolder(): void
    {
        $d = reader_page_data(self::files('a.pdf'), 'tok', 'Livres/BD', null);
        $this->assertSame('/dl/tok?p=Livres%2FBD', $d['backUrl']);
        $this->assertSame('sb:tok:Livres/BD', $d['storageToken']);
        $this->assertSame('/share/reader/', $d['libBase']);
    }

    public function testKnownBookIsOpenedUnknownIsIgnored(): void
    {
        $files = self::files('a.pdf', 'b.pdf', 'c.txt');
        $this->assertSame('b.pdf', reader_page_data($files, 'tok', '', 'b.pdf')['open']);
        $this->assertNull(reader_page_data($files, 'tok', '', 'zzz.pdf')['open']);
        $this->assertNull(reader_page_data($files, 'tok', '', 'c.txt')['open']);
        $this->assertNull(reader_page_data($files, 'tok', '', '../a.pdf')['open']);
        $this->assertNull(reader_page_data($files, 'tok', '', null)['open']);
    }

    public function testEpubStaysOutUnlessEnabled(): void
    {
        $files = self::files('a.pdf', 'b.epub');
        $this->assertSame(['a.pdf'], array_column(reader_page_data($files, 'tok', '', null)['books'], 'name'));
        $this->assertNull(reader_page_data($files, 'tok', '', 'b.epub')['open']);
        $this->assertSame(['a.pdf', 'b.epub'], array_column(reader_page_data($files, 'tok', '', null, true)['books'], 'name'));
    }

    public function testCoverUsesFolderCoverAsInlineUrl(): void
    {
        $d = reader_page_data(self::files('a.pdf', 'scan.jpg', 'cover.jpg'), 'tok', 'L', null);
        $this->assertSame('/dl/tok?p=L%2Fcover.jpg&inline=1', $d['cover']);
        $this->assertNull(reader_page_data(self::files('a.pdf'), 'tok', '', null)['cover']);
    }

    public function testAudiobookTracksStreamInNaturalOrder(): void
    {
        $d = audiobook_page_data(['10 - fin.mp3', '2 - suite.M4B', 'cover.jpg', 'livret.pdf', '1 - debut.mp3'], 'tok', 'Audio/Livre');

        $this->assertSame(['1 - debut.mp3', '2 - suite.M4B', '10 - fin.mp3'], array_column($d['tracks'], 'name'));
        $this->assertSame('/dl/tok?p=Audio%2FLivre%2F1%20-%20debut.mp3&stream=1', $d['tracks'][0]['url']);
        $this->assertSame('/dl/tok?p=Audio%2FLivre%2Fcover.jpg&inline=1', $d['cover']);
        $this->assertSame('/dl/tok?p=Audio%2FLivre', $d['backUrl']);
        $this->assertSame('sb:tok:Audio/Livre', $d['storageToken']);
    }

    public function testHostileNamesAreSafeInJson(): void
    {
        $evil = '"><script>alert(1)</script> l\'été 100% &.pdf';
        $d = reader_page_data(self::files($evil, "bad\xC3\x28.pdf"), 'tok', '', null);
        $json = page_json($d['books']);

        $this->assertStringNotContainsString('<', $json);
        $this->assertStringNotContainsString('>', $json);
        $this->assertStringNotContainsString("'", $json);
        $this->assertStringNotContainsString('&', $json);
        $this->assertStringNotContainsString('"><', $json);
        // A broken UTF-8 name must not blank the whole list.
        $back = json_decode($json, true);
        $this->assertCount(2, $back);
        $this->assertContains($evil, array_column($back, 'name'));
        $this->assertStringContainsString('100%25', $back[0]['url'] . $back[1]['url']);
    }

    public function testPageJsonNeverReturnsFalse(): void
    {
        $this->assertSame('[]', page_json([NAN]));
    }
}
