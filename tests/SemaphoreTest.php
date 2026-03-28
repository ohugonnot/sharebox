<?php

use PHPUnit\Framework\TestCase;

class SemaphoreTest extends TestCase
{
    protected function setUp(): void
    {
        // Les lockfiles de prod appartiennent à www-data — vérifier qu'on peut les ouvrir en écriture
        for ($i = 1; $i <= (defined('STREAM_MAX_CONCURRENT') ? STREAM_MAX_CONCURRENT : 3); $i++) {
            $path = "/tmp/sharebox_stream_slot_{$i}.lock";
            $fp = @fopen($path, 'w');
            if ($fp === false) {
                $this->markTestSkipped("Cannot open $path for writing (owned by www-data?)");
            }
            fclose($fp);
        }
    }

    protected function tearDown(): void
    {
        // Cleanup lock files
        foreach (glob('/tmp/sharebox_stream_slot_*.lock') as $f) {
            @unlink($f);
        }
    }

    public function testAcquireOneSlot(): void
    {
        [$fp, $waited] = acquireStreamSlot();
        $this->assertIsResource($fp);
        $this->assertFalse($waited);
        releaseStreamSlot($fp);
    }

    public function testAcquireAllSlots(): void
    {
        if (!defined('STREAM_MAX_CONCURRENT')) {
            define('STREAM_MAX_CONCURRENT', 3);
        }
        $max = STREAM_MAX_CONCURRENT;

        $slots = [];
        for ($i = 0; $i < $max; $i++) {
            [$fp, $waited] = acquireStreamSlot();
            $this->assertFalse($waited, "Slot $i should not wait");
            $slots[] = $fp;
        }

        // Release all
        foreach ($slots as $fp) {
            releaseStreamSlot($fp);
        }
    }

    public function testReleaseAndReacquire(): void
    {
        [$fp1, $waited1] = acquireStreamSlot();
        $this->assertFalse($waited1);
        releaseStreamSlot($fp1);

        [$fp2, $waited2] = acquireStreamSlot();
        $this->assertFalse($waited2);
        releaseStreamSlot($fp2);
    }
}
