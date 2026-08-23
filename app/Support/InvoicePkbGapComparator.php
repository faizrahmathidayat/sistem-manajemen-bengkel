<?php

namespace App\Support;

use App\Models\Invoice;

class InvoicePkbGapComparator
{
    public static function build(Invoice $invoice): array
    {
        $workOrder = $invoice->workOrder;
        $detailsByServiceLineId = $invoice->details->whereNotNull('work_order_service_line_id')->keyBy('work_order_service_line_id');
        $detailsBySparepartLineId = $invoice->details->whereNotNull('work_order_sparepart_line_id')->keyBy('work_order_sparepart_line_id');

        $rows = [];

        foreach ($workOrder->serviceLines as $line) {
            $rows[] = static::compareLine('Jasa', $line->description, $line, $detailsByServiceLineId->get($line->id));
        }

        foreach ($workOrder->sparepartLines as $line) {
            $rows[] = static::compareLine('Sparepart', $line->item_name_snapshot, $line, $detailsBySparepartLineId->get($line->id));
        }

        $addedDetails = $invoice->details
            ->whereNull('work_order_service_line_id')
            ->whereNull('work_order_sparepart_line_id');

        foreach ($addedDetails as $detail) {
            $rows[] = [
                'item_type' => $detail->item_type === InvoiceDetailItemType::SERVICE ? 'Jasa' : 'Sparepart',
                'item_name' => $detail->description,
                'pkb_qty' => null,
                'pkb_price' => null,
                'invoice_qty' => (float) $detail->qty,
                'invoice_price' => (float) $detail->unit_price,
                'invoice_discount_percent' => (float) $detail->discount_percent,
                'invoice_discount_amount' => (float) $detail->discount_amount,
                'category' => 'added',
            ];
        }

        return $rows;
    }

    protected static function compareLine(string $itemType, string $itemName, $pkbLine, $detail): array
    {
        if (! $detail) {
            return [
                'item_type' => $itemType,
                'item_name' => $itemName,
                'pkb_qty' => (float) $pkbLine->qty,
                'pkb_price' => (float) $pkbLine->unit_price,
                'invoice_qty' => null,
                'invoice_price' => null,
                'invoice_discount_percent' => null,
                'invoice_discount_amount' => null,
                'category' => 'removed',
            ];
        }

        $qtyAndPriceUnchanged = (float) $pkbLine->qty === (float) $detail->qty
            && (float) $pkbLine->unit_price === (float) $detail->unit_price;

        // PKB (work_order_service_lines/work_order_sparepart_lines) has no discount concept at
        // all — discount is only introduced once a line becomes an invoice_details row. So a line
        // whose qty/harga satuan match the PKB exactly, but carries a nonzero invoice discount,
        // is NOT "sesuai": its net value genuinely diverges from what the PKB planned, even though
        // qty/price look identical at a glance.
        $category = 'changed';
        if ($qtyAndPriceUnchanged) {
            $category = (float) $detail->discount_amount > 0 ? 'discounted' : 'sesuai';
        }

        return [
            'item_type' => $itemType,
            'item_name' => $itemName,
            'pkb_qty' => (float) $pkbLine->qty,
            'pkb_price' => (float) $pkbLine->unit_price,
            'invoice_qty' => (float) $detail->qty,
            'invoice_price' => (float) $detail->unit_price,
            'invoice_discount_percent' => (float) $detail->discount_percent,
            'invoice_discount_amount' => (float) $detail->discount_amount,
            'category' => $category,
        ];
    }
}
