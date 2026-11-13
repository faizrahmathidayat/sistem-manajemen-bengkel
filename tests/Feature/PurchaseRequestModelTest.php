<?php

namespace Tests\Feature;

use App\Models\PurchaseRequest;
use App\Support\PurchaseRequestStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesProcurementData;
use Tests\TestCase;

class PurchaseRequestModelTest extends TestCase
{
    use RefreshDatabase, MakesProcurementData;

    public function test_new_request_defaults_to_waiting_supplier(): void
    {
        $branch = $this->makeBranch();
        $sp = $this->makeSparepartBranch($branch);

        $request = $this->makePurchaseRequest($branch, [['sparepart_branch_id' => $sp->id, 'qty' => 5]]);

        $this->assertSame(PurchaseRequestStatus::WAITING_SUPPLIER, $request->status);
        $this->assertSame('waiting_supplier', $request->status);
        $this->assertCount(1, $request->lines);
    }

    public function test_line_qty_must_be_positive(): void
    {
        $branch = $this->makeBranch();
        $sp = $this->makeSparepartBranch($branch);

        $this->expectException(QueryException::class);
        $this->makePurchaseRequest($branch, [['sparepart_branch_id' => $sp->id, 'qty' => 0]]);
    }

    public function test_same_sparepart_cannot_appear_twice_in_one_request(): void
    {
        $branch = $this->makeBranch();
        $sp = $this->makeSparepartBranch($branch);

        $this->expectException(QueryException::class);
        $this->makePurchaseRequest($branch, [
            ['sparepart_branch_id' => $sp->id, 'qty' => 1],
            ['sparepart_branch_id' => $sp->id, 'qty' => 2],
        ]);
    }

    public function test_is_assignment_complete_requires_supplier_and_positive_price_on_every_line(): void
    {
        $branch = $this->makeBranch();
        $a = $this->makeSparepartBranch($branch);
        $b = $this->makeSparepartBranch($branch);
        $supplier = $this->makeSupplier();

        $incomplete = $this->makePurchaseRequest($branch, [
            ['sparepart_branch_id' => $a->id, 'qty' => 2, 'supplier_id' => $supplier->id, 'unit_price' => 1000],
            ['sparepart_branch_id' => $b->id, 'qty' => 3],
        ]);
        $this->assertFalse($incomplete->isAssignmentComplete());

        $zeroPrice = $this->makePurchaseRequest($branch, [
            ['sparepart_branch_id' => $a->id, 'qty' => 2, 'supplier_id' => $supplier->id, 'unit_price' => 0],
        ]);
        $this->assertFalse($zeroPrice->isAssignmentComplete());

        $complete = $this->makePurchaseRequest($branch, [
            ['sparepart_branch_id' => $a->id, 'qty' => 2, 'supplier_id' => $supplier->id, 'unit_price' => 1000],
            ['sparepart_branch_id' => $b->id, 'qty' => 3, 'supplier_id' => $supplier->id, 'unit_price' => 500],
        ]);
        $this->assertTrue($complete->isAssignmentComplete());
    }

    public function test_compute_total_sums_rounded_line_totals(): void
    {
        $branch = $this->makeBranch();
        $a = $this->makeSparepartBranch($branch);
        $b = $this->makeSparepartBranch($branch);
        $supplier = $this->makeSupplier();

        $request = $this->makePurchaseRequest($branch, [
            ['sparepart_branch_id' => $a->id, 'qty' => 120, 'supplier_id' => $supplier->id, 'unit_price' => 85000],
            ['sparepart_branch_id' => $b->id, 'qty' => 60, 'supplier_id' => $supplier->id, 'unit_price' => 45000],
        ]);

        $this->assertSame(12900000.0, $request->computeTotal());
    }
}
