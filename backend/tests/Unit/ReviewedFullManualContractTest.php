<?php

namespace Tests\Unit;

use App\Services\OfficialKnowledgeIngestion\ReviewedFullManualContract;
use Tests\TestCase;

class ReviewedFullManualContractTest extends TestCase
{
    public function test_metadata_only_reviewed_inventory_is_complete_and_self_consistent(): void
    {
        $contract = new ReviewedFullManualContract;
        $first = $contract->reviewedInventory();
        $second = $contract->reviewedInventory();
        $this->assertSame($first, $second);
        $this->assertCount(628, $first);
        $eligible = array_values(array_filter($first, fn (array $entry): bool => $entry['outcome'] === 'PASS'));
        $this->assertCount(625, $eligible);
        $this->assertCount(614, array_unique(array_column($eligible, 'code')));
        $this->assertCount(625, array_unique(array_map(fn (array $entry): string => $entry['code']."\0".$entry['variant_key'], $eligible)));
        $this->assertSame(636, array_sum(array_column($eligible, 'applicabilities')));
        $this->assertSame(2011, array_sum(array_column($eligible, 'parts')));
        $this->assertSame(3877, array_sum(array_column($eligible, 'steps')));
        $this->assertSame(1538, array_sum(array_column($eligible, 'references')));
        $this->assertSame(53, count(array_filter($eligible, fn (array $entry): bool => $entry['warning_bearing'])));
        $this->assertSame(242, count(array_filter($eligible, fn (array $entry): bool => $entry['dipsw_bearing'])));
        $this->assertSame(204, count(array_filter($eligible, fn (array $entry): bool => $entry['detached_control_bearing'])));
        $this->assertSame(
            [['C-1547', 'WARN'], ['C-C131', 'FAIL'], ['C-D0F8', 'FAIL']],
            array_map(fn (array $entry): array => [$entry['code'], $entry['outcome']], array_values(array_filter($first, fn (array $entry): bool => $entry['outcome'] !== 'PASS'))),
        );
    }
}
