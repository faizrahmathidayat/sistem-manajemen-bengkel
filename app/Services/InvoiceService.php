<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\InventoryMovement;
use App\Models\SparepartBranch;
use App\Models\SparepartBranchStock;
use App\Models\WorkOrder;
use App\Models\WorkOrderSparepartLine;
use App\Support\InventoryMovementType;
use App\Support\InvoiceDetailItemType;
use App\Support\InvoiceStatus;
use App\Support\WorkOrderStatus;
use DomainException;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function createFromWorkOrder(WorkOrder $workOrder): Invoice
    {
        return DB::transaction(function () use ($workOrder) {
            $fresh = WorkOrder::whereKey($workOrder->id)->lockForUpdate()->first();

            if ($fresh->status !== WorkOrderStatus::COMPLETED) {
                throw new DomainException('PKB harus berstatus selesai (completed) sebelum dapat dibuatkan invoice.');
            }

            if ($fresh->invoice()->exists()) {
                throw new DomainException('PKB ini sudah memiliki invoice.');
            }

            $serviceLines = $fresh->serviceLines()->reorder()->orderBy('sort_order')->get();
            $sparepartLines = $fresh->sparepartLines()->reorder()->orderBy('sort_order')->get();

            $subtotalService = round((float) $serviceLines->sum('line_total'), 2);
            $subtotalSparepart = round((float) $sparepartLines->sum('line_total'), 2);

            $invoice = Invoice::create([
                'number' => (new DocumentNumberGenerator())->next($fresh->branch, 'INV'),
                'work_order_id' => $fresh->id,
                'branch_id' => $fresh->branch_id,
                'customer_id' => $fresh->customer_id,
                'invoice_date' => now()->toDateString(),
                'status' => InvoiceStatus::DRAFT,
                'subtotal_service' => $subtotalService,
                'subtotal_sparepart' => $subtotalSparepart,
                // Diskon/PPN header dimulai dari 0 pada draft — nilainya diisi/diubah lewat alur
                // edit invoice (belum ada di scope ini) sebelum invoice diposting.
                'discount_percent' => 0,
                'discount_amount' => 0,
                'tax_percent' => 0,
                'tax_amount' => 0,
                'grand_total' => round($subtotalService + $subtotalSparepart, 2),
            ]);

            $sortOrder = 0;

            foreach ($serviceLines as $line) {
                InvoiceDetail::create([
                    'invoice_id' => $invoice->id,
                    'item_type' => InvoiceDetailItemType::SERVICE,
                    'work_order_service_line_id' => $line->id,
                    'work_order_sparepart_line_id' => null,
                    'item_code_snapshot' => null,
                    'description' => $line->description,
                    'qty' => $line->qty,
                    'unit_price' => $line->unit_price,
                    'line_total' => $line->line_total,
                    'sort_order' => $sortOrder++,
                ]);
            }

            foreach ($sparepartLines as $line) {
                InvoiceDetail::create([
                    'invoice_id' => $invoice->id,
                    'item_type' => InvoiceDetailItemType::SPAREPART,
                    'work_order_service_line_id' => null,
                    'work_order_sparepart_line_id' => $line->id,
                    'sparepart_branch_id' => $line->sparepart_branch_id,
                    'item_code_snapshot' => $line->item_code_snapshot,
                    'description' => $line->item_name_snapshot,
                    'qty' => $line->qty,
                    'unit_price' => $line->unit_price,
                    'line_total' => $line->line_total,
                    'sort_order' => $sortOrder++,
                ]);
            }

            return $invoice->fresh('details');
        });
    }

    public function updateInvoice(Invoice $invoice, array $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $data) {
            $fresh = Invoice::whereKey($invoice->id)->lockForUpdate()->first();

            if ($fresh->status !== InvoiceStatus::DRAFT) {
                throw new DomainException('Invoice sudah tidak berstatus draft, tidak bisa diubah lagi.');
            }

            $beforeLineIds = $fresh->details()
                ->whereNotNull('work_order_sparepart_line_id')
                ->pluck('work_order_sparepart_line_id');

            $fresh->details()->delete();

            $sortOrder = 0;

            foreach ($data['services'] ?? [] as $line) {
                $qty = (float) $line['qty'];
                $unitPrice = (float) $line['unit_price'];
                InvoiceDetail::create([
                    'invoice_id' => $fresh->id,
                    'item_type' => InvoiceDetailItemType::SERVICE,
                    'work_order_service_line_id' => $line['work_order_service_line_id'] ?? null,
                    'work_order_sparepart_line_id' => null,
                    'sparepart_branch_id' => null,
                    'item_code_snapshot' => null,
                    'description' => $line['description'],
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'line_total' => round($qty * $unitPrice, 2),
                    'sort_order' => $sortOrder++,
                ]);
            }

            foreach ($data['spareparts'] ?? [] as $line) {
                $sparepartBranch = SparepartBranch::with('sparepart')->findOrFail($line['sparepart_branch_id']);
                $qty = (float) $line['qty'];
                $unitPrice = (float) $line['unit_price'];
                InvoiceDetail::create([
                    'invoice_id' => $fresh->id,
                    'item_type' => InvoiceDetailItemType::SPAREPART,
                    'work_order_service_line_id' => null,
                    'work_order_sparepart_line_id' => $line['work_order_sparepart_line_id'] ?? null,
                    'sparepart_branch_id' => $sparepartBranch->id,
                    'item_code_snapshot' => $sparepartBranch->sparepart->code,
                    'description' => $sparepartBranch->sparepart->name,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'line_total' => round($qty * $unitPrice, 2),
                    'sort_order' => $sortOrder++,
                ]);
            }

            $afterLineIds = collect($data['spareparts'] ?? [])->pluck('work_order_sparepart_line_id')->filter()->values();
            $droppedLineIds = $beforeLineIds->diff($afterLineIds)->values();
            $this->releaseReservationsForLines($droppedLineIds);

            $subtotalService = round((float) $fresh->details()->where('item_type', InvoiceDetailItemType::SERVICE)->sum('line_total'), 2);
            $subtotalSparepart = round((float) $fresh->details()->where('item_type', InvoiceDetailItemType::SPAREPART)->sum('line_total'), 2);
            $subtotal = $subtotalService + $subtotalSparepart;
            $discountPercent = (float) $data['discount_percent'];
            $taxPercent = (float) $data['tax_percent'];
            $discountAmount = round($subtotal * $discountPercent / 100, 2);
            $taxableBase = $subtotal - $discountAmount;
            $taxAmount = round($taxableBase * $taxPercent / 100, 2);

            $fresh->update([
                'subtotal_service' => $subtotalService,
                'subtotal_sparepart' => $subtotalSparepart,
                'discount_percent' => $discountPercent,
                'discount_amount' => $discountAmount,
                'tax_percent' => $taxPercent,
                'tax_amount' => $taxAmount,
                'grand_total' => round($taxableBase + $taxAmount, 2),
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            return $fresh->fresh('details');
        });
    }

    public function cancelInvoice(Invoice $invoice, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason) {
            $fresh = Invoice::whereKey($invoice->id)->lockForUpdate()->first();

            if ($fresh->status !== InvoiceStatus::DRAFT) {
                throw new DomainException('Invoice sudah tidak berstatus draft, tidak bisa dibatalkan.');
            }

            $lineIds = $fresh->details()->whereNotNull('work_order_sparepart_line_id')->pluck('work_order_sparepart_line_id');
            $this->releaseReservationsForLines($lineIds);

            $fresh->update([
                'status' => InvoiceStatus::CANCELLED,
                'cancel_reason' => $reason,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
            ]);

            return $fresh;
        });
    }

    // Shared by updateInvoice() (dropped lines) and cancelInvoice() (all remaining lines).
    // Locks sparepart_branch_stocks rows in ascending id order, matching every other
    // reservation-touching path in this codebase, to avoid AB-BA deadlocks. Safe to call with
    // an empty collection.
    protected function releaseReservationsForLines($workOrderSparepartLineIds): void
    {
        $ids = collect($workOrderSparepartLineIds)->values();

        if ($ids->isEmpty()) {
            return;
        }

        $lines = WorkOrderSparepartLine::whereIn('id', $ids)
            ->with(['reservations' => fn ($q) => $q->where('status', 'active')])
            ->get();

        $bySparepart = $lines->groupBy('sparepart_branch_id')->sortKeys();

        foreach ($bySparepart as $sparepartBranchId => $linesForSparepart) {
            $stock = SparepartBranchStock::where('sparepart_branch_id', $sparepartBranchId)
                ->lockForUpdate()
                ->firstOrFail();

            foreach ($linesForSparepart as $line) {
                foreach ($line->reservations as $reservation) {
                    $stock->reserved_qty -= $reservation->qty;
                    $reservation->status = 'released';
                    $reservation->save();
                }
            }

            $stock->save();
        }
    }

    public function postInvoice(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $fresh = Invoice::whereKey($invoice->id)->lockForUpdate()->first();

            if ($fresh->status !== InvoiceStatus::DRAFT) {
                throw new DomainException('Invoice sudah tidak berstatus draft.');
            }

            $sparepartDetails = $fresh->details()
                ->where('item_type', InvoiceDetailItemType::SPAREPART)
                ->with(['sparepartLine' => function ($query) {
                    $query->with(['reservations' => fn ($rq) => $rq->where('status', 'active')]);
                }])
                ->get();

            // Group by sparepart_branch_id (the column, not the PKB line — a free-form line has
            // no PKB line to derive it from) and lock/validate in ascending id order — same
            // convention WorkOrderController::confirm()/cancel() and the other post() actions
            // use to avoid AB-BA deadlocks against each other on a shared sparepart. Grouping
            // also makes this correct even if a PKB somehow lists the same sparepart_branch_id
            // on more than one line (not blocked at the DB level).
            $bySparepart = $sparepartDetails
                ->groupBy(fn (InvoiceDetail $detail) => $detail->sparepart_branch_id)
                ->sortKeys();

            // Pass 1: lock every affected stock row and validate the deduction won't violate
            // ck_stock_nonnegative (on_hand_qty >= 0, reserved_qty <= on_hand_qty) before
            // mutating anything, so a shortfall on one sparepart can't leave another already
            // posted (all-or-nothing).
            $lockedStocks = [];
            $violations = [];

            foreach ($bySparepart as $sparepartBranchId => $detailsForSparepart) {
                $stock = SparepartBranchStock::where('sparepart_branch_id', $sparepartBranchId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $lockedStocks[$sparepartBranchId] = $stock;

                $totalQtyOut = (float) $detailsForSparepart->sum('qty');
                // Free-form lines (no work_order_sparepart_line_id) have no PKB reservation to
                // release — sparepartLine is null for those, hence the null-safe check.
                $totalReservedToRelease = (float) $detailsForSparepart->sum(
                    fn (InvoiceDetail $d) => $d->sparepartLine ? $d->sparepartLine->reservations->sum('qty') : 0
                );

                $projectedOnHand = (float) $stock->on_hand_qty - $totalQtyOut;
                $projectedReserved = (float) $stock->reserved_qty - $totalReservedToRelease;
                $label = $detailsForSparepart->first()->item_code_snapshot ?? "#{$sparepartBranchId}";

                if ($projectedOnHand < -0.0005) {
                    $violations[] = sprintf(
                        'Stok %s tidak mencukupi untuk diposting (kurang %s).',
                        $label,
                        number_format(abs($projectedOnHand), 3)
                    );
                }

                if ($projectedReserved < -0.0005) {
                    $violations[] = sprintf('Reservasi %s tidak konsisten saat posting invoice.', $label);
                }
            }

            if (! empty($violations)) {
                throw new DomainException(implode(' ', $violations));
            }

            // Pass 2: mutate stock, release reservations, and record kartu stok.
            foreach ($bySparepart as $sparepartBranchId => $detailsForSparepart) {
                $stock = $lockedStocks[$sparepartBranchId];

                foreach ($detailsForSparepart as $detail) {
                    if ($detail->sparepartLine) {
                        foreach ($detail->sparepartLine->reservations as $reservation) {
                            $stock->reserved_qty -= $reservation->qty;
                            $reservation->status = 'released';
                            $reservation->save();
                        }
                    }

                    $stock->on_hand_qty -= $detail->qty;
                    $stock->save();

                    InventoryMovement::create([
                        'movement_at' => now(),
                        'branch_id' => $fresh->branch_id,
                        'sparepart_branch_id' => $sparepartBranchId,
                        'movement_type' => InventoryMovementType::USAGE_OUT,
                        'qty_in' => 0,
                        'qty_out' => $detail->qty,
                        'balance_after' => $stock->on_hand_qty,
                        'reference_type' => 'invoice_detail',
                        'reference_id' => $detail->id,
                        'created_by' => auth()->id(),
                    ]);
                }
            }

            $fresh->status = InvoiceStatus::POSTED;
            $fresh->save();

            return $fresh;
        });
    }
}
