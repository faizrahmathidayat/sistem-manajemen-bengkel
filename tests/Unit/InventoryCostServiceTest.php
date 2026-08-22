<?php

namespace Tests\Unit;

use App\Services\InventoryCostService;
use Tests\TestCase;

class InventoryCostServiceTest extends TestCase
{
    protected InventoryCostService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InventoryCostService();
    }

    public function test_first_receipt_into_empty_stock_uses_the_receipt_price_as_average(): void
    {
        $result = $this->service->recalculateOnReceipt(0.0, 0.0, 10.0, 40000.0);

        $this->assertEqualsWithDelta(40000.0, $result, 0.01);
    }

    public function test_second_receipt_blends_with_existing_average(): void
    {
        // 10 unit @ 40000 sudah ada, masuk lagi 5 unit @ 55000
        // (10*40000 + 5*55000) / 15 = (400000 + 275000) / 15 = 45000
        $result = $this->service->recalculateOnReceipt(10.0, 40000.0, 5.0, 55000.0);

        $this->assertEqualsWithDelta(45000.0, $result, 0.01);
    }

    public function test_receipt_with_decimal_qty_computes_precisely(): void
    {
        // 2.5 unit @ 100000 sudah ada, masuk lagi 2.5 unit @ 120000
        // (2.5*100000 + 2.5*120000) / 5 = (250000 + 300000) / 5 = 110000
        $result = $this->service->recalculateOnReceipt(2.5, 100000.0, 2.5, 120000.0);

        $this->assertEqualsWithDelta(110000.0, $result, 0.01);
    }

    public function test_zero_qty_after_is_guarded_and_returns_the_incoming_unit_cost(): void
    {
        // Defensive edge case yang seharusnya tidak terjadi di alur normal (qtyIn selalu > 0
        // untuk RECEIPT), tapi dijaga supaya tidak divide-by-zero.
        $result = $this->service->recalculateOnReceipt(0.0, 0.0, 0.0, 40000.0);

        $this->assertEqualsWithDelta(40000.0, $result, 0.01);
    }
}
