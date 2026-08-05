<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceiptLine;
use App\Models\InventoryMovement;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\StockAdjustmentLine;
use App\Models\StockTransferLine;
use App\Support\InventoryMovementType;

class StockCardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $allowedBranches = $user->branchesWithPermission('sparepart.view');

        if ($allowedBranches->isEmpty()) {
            return view('stock-card.no-access');
        }

        $requestedBranchId = request('branch_id');
        if ($requestedBranchId && $allowedBranches->firstWhere('id', (int) $requestedBranchId)) {
            session(['current_sparepart_branch_id' => (int) $requestedBranchId]);
        }

        $currentBranch = $allowedBranches->firstWhere('id', session('current_sparepart_branch_id'))
            ?? $allowedBranches->first();
        session(['current_sparepart_branch_id' => $currentBranch->id]);

        $spareparts = Sparepart::where('is_active', true)
            ->whereHas('sparepartBranches', function ($query) use ($currentBranch) {
                $query->where('branch_id', $currentBranch->id)->where('is_active', true);
            })
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $requestedSparepartId = filter_var(request('sparepart_id'), FILTER_VALIDATE_INT) ?: null;
        $selectedSparepart = $requestedSparepartId
            ? $spareparts->firstWhere('id', $requestedSparepartId)
            : null;
        $selectedSparepart = $selectedSparepart ?? $spareparts->first();

        $sparepartBranch = $selectedSparepart
            ? SparepartBranch::where('sparepart_id', $selectedSparepart->id)->where('branch_id', $currentBranch->id)->first()
            : null;

        $stat = ['onHand' => 0.0, 'reserved' => 0.0, 'available' => 0.0];
        $movements = collect();

        if ($sparepartBranch) {
            $stock = $sparepartBranch->stock;
            $onHand = (float) $stock->on_hand_qty;
            $reserved = (float) $stock->reserved_qty;
            $stat = ['onHand' => $onHand, 'reserved' => $reserved, 'available' => $onHand - $reserved];

            $movements = InventoryMovement::where('sparepart_branch_id', $sparepartBranch->id)
                ->orderBy('movement_at')
                ->orderBy('id')
                ->simplePaginate(20)
                ->withQueryString();

            $movements->getCollection()->transform(function (InventoryMovement $movement) {
                return $this->decorateMovement($movement);
            });
        }

        return view('stock-card.index', [
            'allowedBranches' => $allowedBranches,
            'currentBranch' => $currentBranch,
            'spareparts' => $spareparts,
            'selectedSparepart' => $selectedSparepart,
            'stat' => $stat,
            'movements' => $movements,
        ]);
    }

    protected function decorateMovement(InventoryMovement $movement): array
    {
        $typeLabels = [
            InventoryMovementType::RECEIPT => 'Penerimaan',
            InventoryMovementType::ADJUSTMENT_IN => 'Penyesuaian Masuk',
            InventoryMovementType::ADJUSTMENT_OUT => 'Penyesuaian Keluar',
            InventoryMovementType::TRANSFER_IN => 'Transfer Masuk',
            InventoryMovementType::TRANSFER_OUT => 'Transfer Keluar',
        ];

        return [
            'movement_at' => $movement->movement_at,
            'type_label' => $typeLabels[$movement->movement_type] ?? $movement->movement_type,
            'reference' => $this->resolveReference($movement->reference_type, $movement->reference_id),
            'qty_in' => (float) $movement->qty_in,
            'qty_out' => (float) $movement->qty_out,
            'balance_after' => (float) $movement->balance_after,
        ];
    }

    protected function resolveReference(string $referenceType, int $referenceId): array
    {
        switch ($referenceType) {
            case 'goods_receipt_line':
                $line = GoodsReceiptLine::with('goodsReceipt')->find($referenceId);
                if ($line && $line->goodsReceipt) {
                    return ['number' => $line->goodsReceipt->number, 'route' => route('goods-receipts.show', $line->goodsReceipt)];
                }
                break;
            case 'stock_adjustment_line':
                $line = StockAdjustmentLine::with('stockAdjustment')->find($referenceId);
                if ($line && $line->stockAdjustment) {
                    return ['number' => $line->stockAdjustment->number, 'route' => route('stock-adjustments.show', $line->stockAdjustment)];
                }
                break;
            case 'stock_transfer_line':
                $line = StockTransferLine::with('stockTransfer')->find($referenceId);
                if ($line && $line->stockTransfer) {
                    return ['number' => $line->stockTransfer->number, 'route' => route('stock-transfers.show', $line->stockTransfer)];
                }
                break;
        }

        return ['number' => "{$referenceType} #{$referenceId}", 'route' => null];
    }
}
