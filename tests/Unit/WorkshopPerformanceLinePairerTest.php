<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Support\InvoiceDetailItemType;
use App\Support\WorkshopPerformanceLinePairer;
use Tests\TestCase;

class WorkshopPerformanceLinePairerTest extends TestCase
{
    protected function detail(string $itemType, string $description, float $price, float $qty, float $discountPercent): InvoiceDetail
    {
        $gross = $qty * $price;
        $discountAmount = $gross * ($discountPercent / 100);

        return new InvoiceDetail([
            'item_type' => $itemType,
            'description' => $description,
            'qty' => $qty,
            'unit_price' => $price,
            'discount_percent' => $discountPercent,
            'discount_amount' => $discountAmount,
            'line_total' => $gross - $discountAmount,
        ]);
    }

    protected function invoiceWithDetails(array $details): Invoice
    {
        $invoice = new Invoice();
        $invoice->setRelation('details', collect($details));

        return $invoice;
    }

    public function test_pairs_jasa_and_sparepart_lines_with_equal_counts(): void
    {
        $invoice = $this->invoiceWithDetails([
            $this->detail(InvoiceDetailItemType::SERVICE, 'Ganti Oli', 100000, 1, 10),
            $this->detail(InvoiceDetailItemType::SERVICE, 'Servis Rem', 50000, 1, 0),
            $this->detail(InvoiceDetailItemType::SPAREPART, 'Oli Mesin', 90000, 1, 20),
            $this->detail(InvoiceDetailItemType::SPAREPART, 'Kampas Rem', 40000, 1, 10),
        ]);

        $rows = WorkshopPerformanceLinePairer::build($invoice);

        $this->assertCount(2, $rows);
        $this->assertSame('Ganti Oli', $rows[0]['jasa_desc']);
        $this->assertSame('Oli Mesin', $rows[0]['sparepart_desc']);
        $this->assertEqualsWithDelta(90000.0, $rows[0]['jasa_subtotal'], 0.01);
        $this->assertEqualsWithDelta(72000.0, $rows[0]['sparepart_subtotal'], 0.01);
        $this->assertEqualsWithDelta(162000.0, $rows[0]['subtotal_line'], 0.01);
        $this->assertSame('Servis Rem', $rows[1]['jasa_desc']);
        $this->assertSame('Kampas Rem', $rows[1]['sparepart_desc']);
    }

    public function test_pads_sparepart_side_when_jasa_has_more_lines(): void
    {
        $invoice = $this->invoiceWithDetails([
            $this->detail(InvoiceDetailItemType::SERVICE, 'Ganti Oli', 100000, 1, 0),
            $this->detail(InvoiceDetailItemType::SERVICE, 'Servis Rem', 50000, 1, 0),
            $this->detail(InvoiceDetailItemType::SPAREPART, 'Oli Mesin', 90000, 1, 0),
        ]);

        $rows = WorkshopPerformanceLinePairer::build($invoice);

        $this->assertCount(2, $rows);
        $this->assertSame('Servis Rem', $rows[1]['jasa_desc']);
        $this->assertSame('-', $rows[1]['sparepart_desc']);
        $this->assertSame(0.0, $rows[1]['sparepart_price']);
        $this->assertSame(0.0, $rows[1]['sparepart_subtotal']);
        $this->assertEqualsWithDelta(50000.0, $rows[1]['subtotal_line'], 0.01);
    }

    public function test_pads_jasa_side_when_sparepart_has_more_lines(): void
    {
        $invoice = $this->invoiceWithDetails([
            $this->detail(InvoiceDetailItemType::SPAREPART, 'Oli Mesin', 90000, 1, 0),
            $this->detail(InvoiceDetailItemType::SPAREPART, 'Kampas Rem', 40000, 1, 0),
        ]);

        $rows = WorkshopPerformanceLinePairer::build($invoice);

        $this->assertCount(2, $rows);
        $this->assertSame('-', $rows[0]['jasa_desc']);
        $this->assertSame(0.0, $rows[0]['jasa_subtotal']);
        $this->assertSame('Oli Mesin', $rows[0]['sparepart_desc']);
    }

    public function test_returns_empty_array_when_invoice_has_no_details(): void
    {
        $invoice = $this->invoiceWithDetails([]);

        $rows = WorkshopPerformanceLinePairer::build($invoice);

        $this->assertSame([], $rows);
    }
}
