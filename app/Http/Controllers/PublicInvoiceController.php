<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Support\InvoicePdfBuilder;
use App\Support\InvoiceStatus;
use Illuminate\Http\Request;

class PublicInvoiceController extends Controller
{
    protected function isPublicallyAccessible(Invoice $invoice): bool
    {
        return $invoice->hash_id
            && $invoice->pin
            && in_array($invoice->status, [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::PAID], true);
    }

    protected function sessionKey(Invoice $invoice): string
    {
        return "public_invoice_verified.{$invoice->id}";
    }

    public function showPinForm(Request $request, Invoice $invoice)
    {
        abort_unless($this->isPublicallyAccessible($invoice), 404);

        if ($request->session()->get($this->sessionKey($invoice))) {
            return redirect()->route('public-invoices.pdf', $invoice);
        }

        return view('public.invoice-pin-form', compact('invoice'));
    }

    public function verifyPin(Request $request, Invoice $invoice)
    {
        abort_unless($this->isPublicallyAccessible($invoice), 404);

        $request->validate(['pin' => ['required', 'digits:6']]);

        if (! hash_equals((string) $invoice->pin, (string) $request->input('pin'))) {
            return redirect()->route('public-invoices.show', $invoice)->with('error', 'PIN salah.');
        }

        $request->session()->put($this->sessionKey($invoice), true);

        return redirect()->route('public-invoices.pdf', $invoice);
    }

    public function showPdf(Request $request, Invoice $invoice)
    {
        abort_unless($this->isPublicallyAccessible($invoice), 404);

        if (! $request->session()->get($this->sessionKey($invoice))) {
            return redirect()->route('public-invoices.show', $invoice);
        }

        return InvoicePdfBuilder::build($invoice)->stream(InvoicePdfBuilder::filename($invoice));
    }
}
