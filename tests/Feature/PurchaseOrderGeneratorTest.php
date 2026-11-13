<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Services\PurchaseOrderGenerator;
use App\Support\PurchaseOrderStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesProcurementData;
use Tests\TestCase;

class PurchaseOrderGeneratorTest extends TestCase
{
    use RefreshDatabase, MakesProcurementData;

    /** The spec's sample: 5 lines over 3 suppliers = 38.320.000. */
    protected function sampleRequest(): PurchaseRequest
    {
        $bks = $this->makeBranch('BKS');
        $oli = $this->makeSupplier('SUP-001');
        $mitra = $this->makeSupplier('SUP-002');
        $sinar = $this->makeSupplier('SUP-003');

        return $this->makePurchaseRequest($bks, [
            ['sparepart_branch_id' => $this->makeSparepartBranch($bks, 'OLI-001')->id, 'qty' => 120, 'supplier_id' => $oli->id, 'unit_price' => 85000],
            ['sparepart_branch_id' => $this->makeSparepartBranch($bks, 'FLT-014')->id, 'qty' => 60, 'supplier_id' => $oli->id, 'unit_price' => 45000],
            ['sparepart_branch_id' => $this->makeSparepartBranch($bks, 'BRK-203')->id, 'qty' => 40, 'supplier_id' => $mitra->id, 'unit_price' => 210000],
            ['sparepart_branch_id' => $this->makeSparepartBranch($bks, 'AKI-045')->id, 'qty' => 20, 'supplier_id' => $sinar->id, 'unit_price' => 725000],
            ['sparepart_branch_id' => $this->makeSparepartBranch($bks, 'BSI-077')->id, 'qty' => 90, 'supplier_id' => $mitra->id, 'unit_price' => 28000],
        ]);
    }

    public function test_generates_one_draft_purchase_order_per_supplier_with_correct_totals(): void
    {
        $request = $this->sampleRequest();

        $orders = (new PurchaseOrderGenerator())->generateFor($request);

        $this->assertCount(3, $orders);
        $byCode = $orders->keyBy(function ($order) {
            return $order->supplier->code;
        });
        $this->assertSame('12900000.00', (string) $byCode['SUP-001']->total_amount);
        $this->assertSame('10920000.00', (string) $byCode['SUP-002']->total_amount);
        $this->assertSame('14500000.00', (string) $byCode['SUP-003']->total_amount);
        $this->assertSame([2, 2, 1], [$byCode['SUP-001']->lines->count(), $byCode['SUP-002']->lines->count(), $byCode['SUP-003']->lines->count()]);
        $orders->each(function ($order) use ($request) {
            $this->assertSame(PurchaseOrderStatus::DRAFT, $order->status);
            $this->assertSame($request->id, (int) $order->purchase_request_id);
            $this->assertSame($request->branch_id, (int) $order->branch_id);
        });
        $this->assertSame(3, $request->purchaseOrders()->count());
    }

    public function test_order_lines_copy_quantities_prices_and_link_back_to_request_lines(): void
    {
        $request = $this->sampleRequest();

        $orders = (new PurchaseOrderGenerator())->generateFor($request);

        $line = $orders->first(function ($order) {
            return $order->supplier->code === 'SUP-001';
        })->lines->first();
        $source = $request->lines->first();
        $this->assertSame($source->id, (int) $line->purchase_request_line_id);
        $this->assertSame($source->sparepart_branch_id, (int) $line->sparepart_branch_id);
        $this->assertSame('120.000', (string) $line->qty_ordered);
        $this->assertSame('85000.00', (string) $line->unit_price);
        $this->assertSame('10200000.00', (string) $line->line_total);
        $this->assertSame('0.000', (string) $line->qty_received);
        $this->assertSame(120.0, $line->remainingQty());
    }

    public function test_numbers_are_unique_and_prefixed_with_branch(): void
    {
        $orders = (new PurchaseOrderGenerator())->generateFor($this->sampleRequest());

        $numbers = $orders->pluck('number');
        $this->assertSame($numbers->unique()->count(), $numbers->count());
        $numbers->each(function ($number) {
            $this->assertStringStartsWith('PO/BKS/', $number);
        });
    }

    public function test_refuses_when_a_line_has_no_supplier_and_creates_nothing(): void
    {
        $bks = $this->makeBranch('BKS');
        $request = $this->makePurchaseRequest($bks, [
            ['sparepart_branch_id' => $this->makeSparepartBranch($bks)->id, 'qty' => 1, 'supplier_id' => null, 'unit_price' => null],
        ]);

        try {
            (new PurchaseOrderGenerator())->generateFor($request);
            $this->fail('Expected LogicException');
        } catch (\LogicException $e) {
            $this->assertSame(0, PurchaseOrder::count());
        }
    }

    public function test_received_quantity_can_never_exceed_ordered_quantity(): void
    {
        $line = (new PurchaseOrderGenerator())->generateFor($this->sampleRequest())->first()->lines->first();

        $this->expectException(QueryException::class);
        $line->update(['qty_received' => $line->qty_ordered + 1]);
    }
}
