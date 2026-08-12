<?php

namespace App\Support;

use App\Models\Invoice;

class WhatsAppInvoiceLinkBuilder
{
    public static function build(Invoice $invoice): ?string
    {
        if (! $invoice->hash_id || ! $invoice->pin) {
            return null;
        }

        $phone = static::formatPhone($invoice->customer->phone);
        if (! $phone) {
            return null;
        }

        return 'https://wa.me/' . $phone . '?text=' . urlencode(static::message($invoice));
    }

    public static function formatPhone(?string $rawPhone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $rawPhone);
        if ($digits === '') {
            return null;
        }
        if (substr($digits, 0, 1) === '0') {
            return '62' . substr($digits, 1);
        }
        if (substr($digits, 0, 2) === '62') {
            return $digits;
        }

        return '62' . $digits;
    }

    public static function message(Invoice $invoice): string
    {
        $customerName = $invoice->customer->name;
        $branchName = $invoice->branch->name;
        $total = number_format($invoice->grand_total, 0, ',', '.');
        $publicUrl = route('public-invoices.show', $invoice);
        $number = $invoice->number;
        $pin = $invoice->pin;

        return <<<TEXT
        Halo {$customerName},
        Berikut invoice dari {$branchName}.
        No. Invoice : {$number}
        Total : Rp {$total}
        Invoice dapat dilihat di:
        {$publicUrl}
        pin : {$pin}

        notes : pin hanya berlaku di invoice ini.
        Terima kasih.
        TEXT;
    }
}
