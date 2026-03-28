<?php

use PHPUnit\Framework\TestCase;

class ProbeSemaphoreTest extends TestCase
{
    protected function setUp(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $path = "/tmp/sharebox_probe_slot_{$i}.lock";
            $fp = @fopen($path, 'w');
            if ($fp === false) {
                $this->markTestSkipped("Cannot open $path for writing (owned by www-data?)");
            }
            fclose($fp);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob('/tmp/sharebox_probe_slot_*.lock') as $f) {
            @unlink($f);
        }
    }

    public function testAcquireOneProbeSlot(): void
    {
        $fp = acquireProbeSlot();
        $this->assertNotNull($fp);
        $this->assertIsResource($fp);
        releaseProbeSlot($fp);
    }

    public function testAcquireAllFiveProbeSlots(): void
    {
        $slots = [];
        for ($i = 0; $i < 5; $i++) {
            $fp = acquireProbeSlot();
            $this->assertNotNull($fp, "Slot $i should be acquired");
            $slots[] = $fp;
        }

        // 6th slot should fail (returns null)
        $extra = acquireProbeSlot();
        $this->assertNull($extra, 'All 5 slots occupied — should return null');

        foreach ($slots as $fp) {
            releaseProbeSlot($fp);
        }
    }

    public function testReleaseAndReacquireProbeSlot(): void
    {
        $fp1 = acquireProbeSlot();
        $this->assertNotNull($fp1);
        releaseProbeSlot($fp1);

        $fp2 = acquireProbeSlot();
        $this->assertNotNull($fp2);
        releaseProbeSlot($fp2);
    }

    public function testSixthSlotNullWhenAllOccupied(): void
    {
        $slots = [];
        for ($i = 0; $i < 5; $i++) {
            $slots[] = acquireProbeSlot();
        }

        $this->assertNull(acquireProbeSlot());

        // Release one and verify we can acquire again
        releaseProbeSlot($slots[2]);
        unset($slots[2]);

        $reacquired = acquireProbeSlot();
        $this->assertNotNull($reacquired);
        $slots[] = $reacquired;

        foreach ($slots as $fp) {
            releaseProbeSlot($fp);
        }
    }
}
