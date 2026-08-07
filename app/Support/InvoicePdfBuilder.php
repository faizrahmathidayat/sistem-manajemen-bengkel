<?php

namespace App\Support;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

class InvoicePdfBuilder
{
    public static function build(Invoice $invoice): DomPdf
    {
        $invoice->loadMissing([
            'branch', 'customer', 'workOrder.vehicle.brand', 'workOrder.vehicle.type',
            'details', 'allocations.paymentReceipt',
        ]);

        return Pdf::loadView('invoices.print-pdf', ['invoice' => $invoice])->setPaper('a4', 'portrait');
    }

    public static function filename(Invoice $invoice): string
    {
        return 'invoice-' . $invoice->number . '.pdf';
    }
}
