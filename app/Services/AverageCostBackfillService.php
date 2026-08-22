<?php

namespace App\Services;

use App\Models\GoodsReceiptLine;
use App\Models\InventoryMovement;
use App\Models\SparepartBranchStock;
use App\Support\InventoryMovementType;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AverageCostBackfillService
{
    protected InventoryCostService $costService;

    public function __construct(InventoryCostService $costService)
    {
        $this->costService = $costService;
    }

    /**
     * Replay seluruh inventory_movements (kronologis) untuk satu sparepart_branch_id
     * untuk merekonstruksi weighted average cost yang seharusnya, sesuai
     * docs/superpowers/specs/2026-08-22-gross-profit-report-design.md §3.4. Hanya
     * movement RECEIPT yang mengubah rata-rata (satu-satunya yang punya harga sumber
     * lewat goods_receipt_lines.purchase_price); movement lain hanya lewat sebagai qty.
     */
    public function replayForSparepartBranch(int $sparepartBranchId): float
    {
        $movements = InventoryMovement::where('sparepart_branch_id', $sparepartBranchId)
            ->orderBy('movement_at')
            ->orderBy('id')
            ->get();

        if ($movements->isEmpty()) {
            return 0.0;
        }

        $receiptLineIds = $movements
            ->where('movement_type', InventoryMovementType::RECEIPT)
            ->pluck('reference_id');

        $purchasePrices = GoodsReceiptLine::whereIn('id', $receiptLineIds)->pluck('purchase_price', 'id');

        $avgCost = 0.0;

        foreach ($movements as $movement) {
            if ($movement->movement_type !== InventoryMovementType::RECEIPT) {
                continue;
            }

            if (! isset($purchasePrices[$movement->reference_id])) {
                throw new RuntimeException(
                    "GoodsReceiptLine #{$movement->reference_id} yang direferensikan InventoryMovement #{$movement->id} tidak ditemukan."
                );
            }

            $qtyIn = (float) $movement->qty_in;
            $qtyBefore = (float) $movement->balance_after - $qtyIn;
            $unitCost = (float) $purchasePrices[$movement->reference_id];

            $avgCost = $this->costService->recalculateOnReceipt($qtyBefore, $avgCost, $qtyIn, $unitCost);
        }

        return round($avgCost, 2);
    }

    /**
     * Menjalankan replay untuk setiap sparepart_branch yang punya minimal 1 movement,
     * menyimpan hasilnya ke sparepart_branch_stocks.average_cost. Dipanggil sekali dari
     * migrasi backfill. Idempotent — aman dipanggil ulang (selalu menghitung ulang dari
     * histori penuh), meski tidak didesain untuk dijalankan rutin.
     */
    public function run(): int
    {
        $sparepartBranchIds = InventoryMovement::query()->distinct()->pluck('sparepart_branch_id');
        $updated = 0;

        foreach ($sparepartBranchIds->chunk(100) as $chunk) {
            DB::transaction(function () use ($chunk, &$updated) {
                foreach ($chunk as $sparepartBranchId) {
                    $avgCost = $this->replayForSparepartBranch($sparepartBranchId);

                    SparepartBranchStock::where('sparepart_branch_id', $sparepartBranchId)
                        ->update(['average_cost' => $avgCost]);

                    $updated++;
                }
            });
        }

        return $updated;
    }
}
