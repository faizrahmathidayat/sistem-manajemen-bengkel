<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Support\PurchaseOrderStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PurchaseOrderGenerator
{
    /**
     * One draft purchase order per supplier on the (fully approved) request, copying quantities
     * and prices from the request lines. Runs inside the caller's transaction (final approval);
     * it opens its own only as a safety net, which nests harmlessly.
     *
     * @return Collection<int, PurchaseOrder>
     */
    public function generateFor(PurchaseRequest $purchaseRequest): Collection
    {
        $purchaseRequest->load(['lines', 'branch']);
        $selectedLines = $purchaseRequest->lines->filter(function ($line) {
            return (bool) $line->is_selected;
        })->values();

        if ($selectedLines->isEmpty()) {
            throw new \LogicException('Tidak ada item yang dipilih untuk dibeli.');
        }

        if ($selectedLines->contains(function ($line) {
            return $line->supplier_id === null || $line->unit_price === null;
        })) {
            throw new \LogicException('Semua baris permintaan harus memiliki supplier dan harga sebelum pembelian dibuat.');
        }

        $orderDate = now()->timezone(config('app.business_timezone', 'Asia/Jakarta'))->toDateString();

        return DB::transaction(function () use ($purchaseRequest, $orderDate, $selectedLines) {
            $orders = new Collection();

            foreach ($selectedLines->groupBy('supplier_id')->sortKeys() as $supplierId => $lines) {
                $order = PurchaseOrder::create([
                    'number' => (new DocumentNumberGenerator())->next($purchaseRequest->branch, 'PO'),
                    'purchase_request_id' => $purchaseRequest->id,
                    'supplier_id' => $supplierId,
                    'supplier_account_number' => optional($lines->first(function ($line) {
                        return $line->supplier_account_number !== null && $line->supplier_account_number !== '';
                    }))->supplier_account_number,
                    'branch_id' => $purchaseRequest->branch_id,
                    'order_date' => $orderDate,
                    'status' => PurchaseOrderStatus::DRAFT,
                    'total_amount' => 0,
                ]);

                $total = 0.0;
                foreach ($lines->values() as $index => $line) {
                    $lineTotal = round((float) $line->qty * (float) $line->unit_price, 2);
                    $total += $lineTotal;
                    $order->lines()->create([
                        'purchase_request_line_id' => $line->id,
                        'sparepart_branch_id' => $line->sparepart_branch_id,
                        'qty_ordered' => $line->qty,
                        'unit_price' => $line->unit_price,
                        'line_total' => $lineTotal,
                        'qty_received' => 0,
                        'sort_order' => $index,
                    ]);
                }

                $order->update(['total_amount' => round($total, 2)]);
                $orders->push($order->load(['supplier', 'lines']));
            }

            return $orders;
        });
    }
}
