<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Support\InvoicePdfBuilder;
use Illuminate\Mail\Mailable;

class InvoicePostedMail extends Mailable
{
    public Invoice $invoice;

    public function __construct(Invoice $invoice)
    {
        $this->invoice = $invoice;
    }

    public function build()
    {
        $pdf = InvoicePdfBuilder::build($this->invoice);

        return $this->subject("Invoice {$this->invoice->number} — {$this->invoice->branch->name}")
            ->view('emails.invoice-posted')
            ->with(['invoice' => $this->invoice])
            ->attachData($pdf->output(), InvoicePdfBuilder::filename($this->invoice), [
                'mime' => 'application/pdf',
            ]);
    }
}
