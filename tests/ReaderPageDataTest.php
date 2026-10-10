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

    /**
     * @dataProvider listingActionsProvider
     * @param string[] $names
     * @param array{param:string,label:string}|null $toolbar
     */
    public function testListingActions(array $names, ?array $toolbar): void
    {
        $this->assertSame(['toolbar' => $toolbar], listing_actions($names, false));
    }

    public static function listingActionsProvider(): array
    {
        $tracks = array_map(fn($i) => sprintf('%02d.mp3', $i), range(1, 12));
        return [
            'livre audio + livret' => [array_merge($tracks, ['livret.pdf']), ['param' => 'listen', 'label' => 'Écouter · 12 pistes']],
            'bibliothèque' => [['a.pdf', 'b.pdf', 'c.pdf', 'x.nfo'], ['param' => 'read', 'label' => 'Bibliothèque · 3 livres']],
            'un seul livre : pas de bibliothèque' => [['a.pdf', 'a.mp3'], null],
            'epub seuls, désactivés' => [['a.epub', 'b.epub'], null],
            'epub désactivés ne cachent pas les pistes' => [['a.mp3', 'b.mp3', 'x.epub', 'y.epub', 'z.epub'], ['param' => 'listen', 'label' => 'Écouter · 2 pistes']],
            'dossier vidéo' => [['a.pdf', 'b.pdf', 'clip.mkv'], null],
            'rien' => [['a.mp3'], null],
        ];
    }

    public function testListingCountsMatchWhatThePagesList(): void
    {
        $names = ['b.pdf', 'a.PDF', 'x.epub', 'cover.jpg', 'notes.txt', 'c.pdf', 'z.epub'];
        $files = array_map(fn($n) => ['name' => $n, 'size' => 1, 'mtime' => 1], $names);
        foreach ([false, true] as $epub) {
            $books = count(reader_page_data($files, 'tok', '', null, $epub)['books']);
            $this->assertSame('Bibliothèque · ' . $books . ' livres', listing_actions($names, $epub)['toolbar']['label'] ?? null);
        }
        $names = ['2.mp3', '1.M4B', '10.flac', 'a.pdf', 'cover.jpg', 'b.ogg.txt'];
        $tracks = count(audiobook_page_data($names, 'tok', '')['tracks']);
        $this->assertSame('Écouter · ' . $tracks . ' pistes', listing_actions($names, false)['toolbar']['label'] ?? null);
    }

    public function testBookReadableFollowsTheEpubFlag(): void
    {
        $this->assertTrue(book_readable('a.PDF'));
        $this->assertSame(READER_EPUB_ENABLED, book_readable('a.epub'));
        $this->assertTrue(book_readable('a.epub', true));
        $this->assertFalse(book_readable('a.epub', false));
        $this->assertTrue(book_readable('a.pdf', false));
        $this->assertFalse(book_readable('a.mp3', true));
    }

    public function testComicsAreBooksWithTheSameInlineUrl(): void
    {
        $d = reader_page_data(self::files('Tome 2.CBR', 'Tome 10.cbz', 'Tome 1.cbz'), 'tok', 'BD', null);
        $this->assertSame(['Tome 1.cbz', 'Tome 2.CBR', 'Tome 10.cbz'], array_column($d['books'], 'name'));
        $this->assertSame(['comic', 'comic', 'comic'], array_column($d['books'], 'kind'));
        $this->assertSame('/dl/tok?p=BD%2FTome%201.cbz&inline=1', $d['books'][0]['url']);
        $this->assertSame('Tome 2.CBR', reader_page_data(self::files('Tome 2.CBR'), 'tok', 'BD', 'Tome 2.CBR')['open']);
    }

    public function testComicIsReadableWhateverTheEpubFlag(): void
    {
        $this->assertTrue(book_readable('a.cbz', false));
        $this->assertTrue(book_readable('a.CBR', false));
        $this->assertTrue(book_readable('a.cbz', true));
        $this->assertFalse(book_readable('a.cb7', true));
    }

    public function testComicFolderGetsTheLibraryAction(): void
    {
        $a = listing_actions(['a.cbz', 'b.cbr'], false);
        $this->assertNotNull($a['toolbar']);
        $this->assertSame('read', $a['toolbar']['param']);
    }
}
