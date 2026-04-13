<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests pour la logique de parsing des Range headers de serve_file() (functions.php).
 *
 * serve_file() appelle header() et exit — elle ne peut pas être testée bout-en-bout
 * sans infrastructure HTTP. On teste à la place le calcul des bornes start/end/length
 * en réimplémentant la même arithmétique (miroir exact du code source) et en vérifiant
 * les invariants : valeurs correctes, 416 sur dépassement de borne.
 */
class ServeFileRangeTest extends TestCase
{
    /**
     * Applique la même logique de parsing que serve_file() et retourne un tableau
     * ['start', 'end', 'length', 'status'] où status vaut 206 ou 416.
     *
     * @param string $rangeHeader Valeur de HTTP_RANGE (ex: "bytes=0-499")
     * @param int    $size        Taille totale du fichier
     * @return array{start:int,end:int,length:int,status:int}
     */
    private function parseRange(string $rangeHeader, int $size): array
    {
        if (!preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $m)) {
            return ['start' => 0, 'end' => $size - 1, 'length' => $size, 'status' => 200];
        }

        if ($m[1] === '' && $m[2] !== '') {
            // Suffix range: bytes=-N → last N bytes
            $start = max(0, $size - (int)$m[2]);
            $end   = $size - 1;
        } else {
            $start = $m[1] !== '' ? (int)$m[1] : 0;
            $end   = $m[2] !== '' ? (int)$m[2] : $size - 1;
        }

        $end = min($end, $size - 1);

        if ($start > $end || $start >= $size) {
            return ['start' => $start, 'end' => $end, 'length' => 0, 'status' => 416];
        }

        $length = $end - $start + 1;
        return ['start' => $start, 'end' => $end, 'length' => $length, 'status' => 206];
    }

    // ── Range standard : bytes=0-499 ──────────────────────────────────────────

    public function testStandardRangeFirstBytes(): void
    {
        $r = $this->parseRange('bytes=0-499', 1000);
        $this->assertSame(206,  $r['status']);
        $this->assertSame(0,    $r['start']);
        $this->assertSame(499,  $r['end']);
        $this->assertSame(500,  $r['length']);
    }

    // ── Range milieu de fichier : bytes=200-399 ───────────────────────────────

    public function testStandardRangeMiddle(): void
    {
        $r = $this->parseRange('bytes=200-399', 1000);
        $this->assertSame(206,  $r['status']);
        $this->assertSame(200,  $r['start']);
        $this->assertSame(399,  $r['end']);
        $this->assertSame(200,  $r['length']);
    }

    // ── Range suffix : bytes=-500 → 500 derniers octets ──────────────────────

    public function testSuffixRangeLast500(): void
    {
        $r = $this->parseRange('bytes=-500', 1000);
        $this->assertSame(206,  $r['status']);
        $this->assertSame(500,  $r['start']);
        $this->assertSame(999,  $r['end']);
        $this->assertSame(500,  $r['length']);
    }

    // ── Range suffix : bytes=-1500 sur fichier de 1000 → clamp à 0 ───────────

    public function testSuffixRangeLargerThanFile(): void
    {
        $r = $this->parseRange('bytes=-1500', 1000);
        $this->assertSame(206,  $r['status']);
        $this->assertSame(0,    $r['start'], 'max(0, 1000-1500) doit valoir 0');
        $this->assertSame(999,  $r['end']);
        $this->assertSame(1000, $r['length']);
    }

    // ── Range ouvert : bytes=500- → du byte 500 à la fin ─────────────────────

    public function testOpenEndedRange(): void
    {
        $r = $this->parseRange('bytes=500-', 1000);
        $this->assertSame(206,  $r['status']);
        $this->assertSame(500,  $r['start']);
        $this->assertSame(999,  $r['end']);
        $this->assertSame(500,  $r['length']);
    }

    // ── Range ouvert depuis le début : bytes=0- → fichier entier ─────────────

    public function testOpenEndedRangeFromZero(): void
    {
        $r = $this->parseRange('bytes=0-', 1000);
        $this->assertSame(206,   $r['status']);
        $this->assertSame(0,     $r['start']);
        $this->assertSame(999,   $r['end']);
        $this->assertSame(1000,  $r['length']);
    }

    // ── Dernier byte exact : bytes=999-999 ───────────────────────────────────

    public function testSingleLastByte(): void
    {
        $r = $this->parseRange('bytes=999-999', 1000);
        $this->assertSame(206, $r['status']);
        $this->assertSame(999, $r['start']);
        $this->assertSame(999, $r['end']);
        $this->assertSame(1,   $r['length']);
    }

    // ── 416 : start > end ────────────────────────────────────────────────────

    public function testInvertedRangeReturns416(): void
    {
        $r = $this->parseRange('bytes=700-200', 1000);
        // end est clampé à min(200, 999) = 200, start=700 > end=200 → 416
        $this->assertSame(416, $r['status']);
    }

    // ── 416 : start >= size ───────────────────────────────────────────────────

    public function testStartBeyondFileSizeReturns416(): void
    {
        $r = $this->parseRange('bytes=1000-1099', 1000);
        // start=1000 >= size=1000 → 416
        $this->assertSame(416, $r['status']);
    }

    // ── 416 : start très au-delà ──────────────────────────────────────────────

    public function testStartFarBeyondFileSizeReturns416(): void
    {
        $r = $this->parseRange('bytes=9999-', 1000);
        $this->assertSame(416, $r['status']);
    }

    // ── Clamp : end dépasse la taille → tronqué à size-1 ─────────────────────

    public function testEndClampedToFileSize(): void
    {
        $r = $this->parseRange('bytes=900-1500', 1000);
        $this->assertSame(206,  $r['status']);
        $this->assertSame(900,  $r['start']);
        $this->assertSame(999,  $r['end'], 'end doit être clampé à size-1');
        $this->assertSame(100,  $r['length']);
    }

    // ── length = end - start + 1 toujours vrai ───────────────────────────────

    /**
     * @dataProvider validRangeProvider
     */
    public function testLengthEqualsEndMinusStartPlusOne(string $range, int $size): void
    {
        $r = $this->parseRange($range, $size);
        if ($r['status'] === 206) {
            $this->assertSame($r['end'] - $r['start'] + 1, $r['length'], 'length = end - start + 1');
        } else {
            $this->assertSame(416, $r['status']);
        }
    }

    public static function validRangeProvider(): array
    {
        return [
            'bytes=0-0 sur fichier 1 octet'  => ['bytes=0-0',    1],
            'bytes=0-999 sur fichier 1000'   => ['bytes=0-999',  1000],
            'bytes=500- sur fichier 1000'    => ['bytes=500-',   1000],
            'bytes=-1 sur fichier 5000'      => ['bytes=-1',     5000],
            'bytes=0- sur fichier 1 octet'   => ['bytes=0-',     1],
        ];
    }
}
