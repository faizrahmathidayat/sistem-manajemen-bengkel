<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\PaymentReceipt;
use App\Support\AuditEvent;
use App\Support\InvoiceStatus;
use App\Support\PaymentReceiptStatus;
use DomainException;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function createPaymentReceipt(array $data): PaymentReceipt
    {
        return DB::transaction(function () use ($data) {
            $allocations = collect($data['allocations'])->sortBy('invoice_id')->values();

            $lockedInvoices = [];

            foreach ($allocations as $allocation) {
                $invoice = Invoice::whereKey($allocation['invoice_id'])->lockForUpdate()->first();

                if (! $invoice) {
                    throw new DomainException("Invoice #{$allocation['invoice_id']} tidak ditemukan.");
                }

                if ((int) $invoice->branch_id !== (int) $data['branch_id'] || (int) $invoice->customer_id !== (int) $data['customer_id']) {
                    throw new DomainException("Invoice {$invoice->number} bukan milik cabang/customer pembayaran ini.");
                }

                if (! in_array($invoice->status, [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID], true)) {
                    throw new DomainException("Invoice {$invoice->number} tidak dapat menerima pembayaran (status saat ini: {$invoice->status}).");
                }

                $outstanding = round((float) $invoice->grand_total - (float) $invoice->paid_amount, 2);
                $allocatedAmount = (float) $allocation['allocated_amount'];

                if ($allocatedAmount > $outstanding + 0.0005) {
                    throw new DomainException(sprintf(
                        'Alokasi untuk invoice %s (%s) melebihi sisa piutang (%s).',
                        $invoice->number,
                        number_format($allocatedAmount, 0, ',', '.'),
                        number_format($outstanding, 0, ',', '.')
                    ));
                }

                $lockedInvoices[$allocation['invoice_id']] = $invoice;
            }

            $branch = Branch::findOrFail($data['branch_id']);

            $receipt = PaymentReceipt::create([
                'number' => (new DocumentNumberGenerator())->next($branch, 'PAY'),
                'branch_id' => $data['branch_id'],
                'customer_id' => $data['customer_id'],
                'payment_date' => $data['payment_date'],
                'payment_method' => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'amount' => $data['amount'],
                'status' => PaymentReceiptStatus::POSTED,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($allocations as $allocation) {
                $invoice = $lockedInvoices[$allocation['invoice_id']];
                $allocatedAmount = (float) $allocation['allocated_amount'];

                PaymentAllocation::create([
                    'payment_receipt_id' => $receipt->id,
                    'invoice_id' => $invoice->id,
                    'allocated_amount' => $allocatedAmount,
                ]);

                $invoice->paid_amount = round((float) $invoice->paid_amount + $allocatedAmount, 2);
                $invoice->status = $this->recomputeInvoiceStatus($invoice);
                $invoice->save();
            }

            (new AuditLogger())->log(
                AuditEvent::PAYMENT_RECEIPT_CREATED,
                (int) $data['branch_id'],
                $receipt,
                [],
                ['amount' => (float) $data['amount'], 'allocations_count' => $allocations->count()]
            );

            return $receipt->fresh('allocations');
        });
    }

    public function voidPaymentReceipt(PaymentReceipt $receipt, string $reason): PaymentReceipt
    {
        return DB::transaction(function () use ($receipt, $reason) {
            $fresh = PaymentReceipt::whereKey($receipt->id)->lockForUpdate()->first();

            if ($fresh->status !== PaymentReceiptStatus::POSTED) {
                throw new DomainException('Payment receipt ini sudah tidak berstatus posted, tidak bisa di-void.');
            }

            $allocations = $fresh->allocations()->orderBy('invoice_id')->get();

            foreach ($allocations as $allocation) {
                $invoice = Invoice::whereKey($allocation->invoice_id)->lockForUpdate()->first();

                $invoice->paid_amount = max(0, round((float) $invoice->paid_amount - (float) $allocation->allocated_amount, 2));
                $invoice->status = $this->recomputeInvoiceStatus($invoice);
                $invoice->save();
            }

            $fresh->update([
                'status' => PaymentReceiptStatus::VOID,
                'void_reason' => $reason,
                'voided_by' => auth()->id(),
                'voided_at' => now(),
            ]);

            (new AuditLogger())->log(
                AuditEvent::PAYMENT_RECEIPT_VOIDED,
                $fresh->branch_id,
                $fresh,
                ['status' => PaymentReceiptStatus::POSTED],
                ['status' => PaymentReceiptStatus::VOID, 'reason' => $reason]
            );

            return $fresh;
        });
    }

    protected function recomputeInvoiceStatus(Invoice $invoice): string
    {
        $paid = (float) $invoice->paid_amount;
        $grandTotal = (float) $invoice->grand_total;

        if ($paid >= $grandTotal - 0.0005) {
            return InvoiceStatus::PAID;
        }

        if ($paid > 0.0005) {
            return InvoiceStatus::PARTIALLY_PAID;
        }

        return InvoiceStatus::POSTED;
    }
}
