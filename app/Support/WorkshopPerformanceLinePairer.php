<?php

namespace App\Support;

use App\Models\Invoice;

class WorkshopPerformanceLinePairer
{
    public static function build(Invoice $invoice): array
    {
        $services = $invoice->details->where('item_type', InvoiceDetailItemType::SERVICE)->values();
        $spareparts = $invoice->details->where('item_type', InvoiceDetailItemType::SPAREPART)->values();
        $rowCount = max($services->count(), $spareparts->count());

        $rows = [];
        for ($i = 0; $i < $rowCount; $i++) {
            $service = $services->get($i);
            $sparepart = $spareparts->get($i);
            $jasaSubtotal = $service ? (float) $service->line_total : 0.0;
            $sparepartSubtotal = $sparepart ? (float) $sparepart->line_total : 0.0;

            $rows[] = [
                'jasa_desc' => $service ? $service->description : '-',
                'jasa_price' => $service ? (float) $service->unit_price : 0.0,
                'jasa_qty' => $service ? (float) $service->qty : 0.0,
                'jasa_discount_percent' => $service ? (float) $service->discount_percent : 0.0,
                'jasa_subtotal' => $jasaSubtotal,
                'sparepart_desc' => $sparepart ? $sparepart->description : '-',
                'sparepart_price' => $sparepart ? (float) $sparepart->unit_price : 0.0,
                'sparepart_qty' => $sparepart ? (float) $sparepart->qty : 0.0,
                'sparepart_discount_percent' => $sparepart ? (float) $sparepart->discount_percent : 0.0,
                'sparepart_subtotal' => $sparepartSubtotal,
                'subtotal_line' => $jasaSubtotal + $sparepartSubtotal,
            ];
        }

        return $rows;
    }
}
