<?php

namespace Tests\Unit;

use App\Support\WarehouseIds;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class WarehouseIdsTest extends TestCase
{
    public function test_it_parses_and_deduplicates_a_warehouse_list(): void
    {
        $this->assertSame([10, 21], WarehouseIds::parse('10, 21,10,invalid'));
    }

    public function test_it_falls_back_to_the_legacy_single_warehouse_column(): void
    {
        $user = (object) ['warehouse_id' => 21];

        $this->assertSame([21], WarehouseIds::fromUser($user));
    }

    public function test_it_reads_multiple_warehouses_from_the_warehouse_id_column(): void
    {
        $user = (object) ['warehouse_id' => '10,21'];

        $this->assertSame([10, 21], WarehouseIds::fromUser($user));
    }

    public function test_it_accepts_stock_reported_by_any_configured_warehouse(): void
    {
        $stocks = collect([
            (object) ['warehouse_id' => 10, 'in_stock' => 0],
            (object) ['warehouse_id' => 21, 'in_stock' => 1],
        ]);

        $this->assertTrue(WarehouseIds::isAvailableInAnyWarehouse($stocks, [10, 21]));
    }

    public function test_a_missing_stock_row_preserves_existing_default_available_behavior(): void
    {
        $stocks = new Collection([
            (object) ['warehouse_id' => 10, 'in_stock' => 0],
        ]);

        $this->assertTrue(WarehouseIds::isAvailableInAnyWarehouse($stocks, [10, 21]));
    }
}
