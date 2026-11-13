<?php

namespace Tests\Concerns;

use App\Models\Branch;
use App\Models\PurchaseRequest;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\Supplier;

trait MakesProcurementData
{
    private $procurementSeq = 0;

    protected function makeBranch(string $code = 'BKS', string $name = 'Cabang Bekasi'): Branch
    {
        return Branch::firstOrCreate(['code' => $code], ['name' => $name]);
    }

    protected function makeSparepartBranch(Branch $branch, ?string $code = null, float $price = 60000): SparepartBranch
    {
        $this->procurementSeq++;
        $code = $code ?: 'SP-' . $this->procurementSeq;
        $sparepart = Sparepart::firstOrCreate(['code' => $code], ['name' => 'Sparepart ' . $code]);

        return SparepartBranch::firstOrCreate(
            ['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id],
            ['selling_price' => $price]
        );
    }

    protected function makeSupplier(?string $code = null, array $overrides = []): Supplier
    {
        $this->procurementSeq++;
        $code = $code ?: 'SUP-' . $this->procurementSeq;

        return Supplier::create(array_merge(['code' => $code, 'name' => 'Supplier ' . $code], $overrides));
    }

    /**
     * @param array $lines each: ['sparepart_branch_id' => int, 'qty' => n, 'supplier_id' => ?int, 'unit_price' => ?n]
     */
    protected function makePurchaseRequest(Branch $branch, array $lines, array $overrides = []): PurchaseRequest
    {
        $this->procurementSeq++;
        $request = PurchaseRequest::create(array_merge([
            'number' => 'RQ/' . $branch->code . '/202611/' . str_pad((string) $this->procurementSeq, 5, '0', STR_PAD_LEFT),
            'branch_id' => $branch->id,
            'request_date' => '2026-11-13',
        ], $overrides));

        foreach (array_values($lines) as $index => $line) {
            $qty = (float) $line['qty'];
            $price = array_key_exists('unit_price', $line) ? $line['unit_price'] : null;
            $request->lines()->create([
                'sparepart_branch_id' => $line['sparepart_branch_id'],
                'qty' => $qty,
                'supplier_id' => $line['supplier_id'] ?? null,
                'unit_price' => $price,
                'line_total' => $price === null ? null : round($qty * (float) $price, 2),
                'sort_order' => $index,
            ]);
        }

        return $request->fresh('lines');
    }
}
