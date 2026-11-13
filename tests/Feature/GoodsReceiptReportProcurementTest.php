<?php

namespace Tests\Feature;

use App\Exports\GoodsReceiptReportExport;
use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\GoodsReceiptStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ExtractsPdfText;
use Tests\Concerns\GrantsPermissions;
use Tests\Concerns\MakesProcurementData;
use Tests\TestCase;

class GoodsReceiptReportProcurementTest extends TestCase
{
    use RefreshDatabase, GrantsPermissions, MakesProcurementData, ExtractsPdfText;

    protected function viewer(Branch $branch): User
    {
        $user = User::factory()->create();
        $this->grantBranch($user, $branch, 'report.goods_receipt.view');

        return User::find($user->id);
    }

    /**
     * One receipt from a PO of supplier S1 (qty 40 of 120 ordered, @85.000 = 3.400.000) and one
     * legacy receipt without a PO (5 @ 40.000 = 200.000).
     */
    protected function scenario(): array
    {
        $bks = $this->makeBranch('BKS');
        $supplier = $this->makeSupplier('SUP-001', ['name' => 'PT Sumber Oli Jaya']);
        $order = $this->makeIssuedPurchaseOrder($bks, 'issued', $supplier);
        $fromPo = GoodsReceipt::create([
            'number' => 'PB/BKS/202611/00011', 'branch_id' => $bks->id, 'purchase_order_id' => $order->id,
            'receipt_date' => '2026-11-18', 'status' => GoodsReceiptStatus::POSTED, 'reference_number' => 'SJ-8841',
        ]);
        $fromPo->lines()->create([
            'sparepart_branch_id' => $order->lines[0]->sparepart_branch_id, 'purchase_order_line_id' => $order->lines[0]->id,
            'qty' => 40, 'purchase_price' => 85000, 'line_total' => 3400000, 'sort_order' => 0,
        ]);

        $legacy = GoodsReceipt::create([
            'number' => 'PB/BKS/202608/00003', 'branch_id' => $bks->id, 'receipt_date' => '2026-08-14',
            'status' => GoodsReceiptStatus::POSTED, 'reference_number' => 'NB-2210',
        ]);
        $legacy->lines()->create([
            'sparepart_branch_id' => $this->makeSparepartBranch($bks)->id,
            'qty' => 5, 'purchase_price' => 40000, 'line_total' => 200000, 'sort_order' => 0,
        ]);

        return compact('bks', 'order', 'supplier', 'fromPo', 'legacy');
    }

    public function test_rekap_shows_supplier_and_purchase_order_number_and_dashes_for_legacy(): void
    {
        $d = $this->scenario();

        $response = $this->actingAs($this->viewer($d['bks']))->get('/reports/goods-receipts');

        $response->assertOk()
            ->assertSee('PT Sumber Oli Jaya')->assertSee($d['order']->number)
            ->assertSee('PB/BKS/202608/00003')->assertSee('Supplier')->assertSee('No. Pembelian');
        $summary = $response->viewData('summary');
        $this->assertSame(2, (int) $summary->total_dokumen);
        $this->assertSame(3600000.0, (float) $summary->total_nilai); // legacy receipts keep counting
    }

    public function test_detail_shows_ordered_quantity_from_the_purchase_order(): void
    {
        $d = $this->scenario();

        $this->actingAs($this->viewer($d['bks']))->get('/reports/goods-receipts?mode=detail')
            ->assertOk()->assertSee('Qty Dipesan')->assertSee('PT Sumber Oli Jaya')->assertSee('120');
    }

    public function test_supplier_filter_returns_only_receipts_of_that_supplier_and_excludes_legacy(): void
    {
        $d = $this->scenario();
        $viewer = $this->viewer($d['bks']);

        $filtered = $this->actingAs($viewer)->get('/reports/goods-receipts?supplier_id=' . $d['supplier']->id);
        $filtered->assertOk()->assertSee('PB/BKS/202611/00011')->assertDontSee('PB/BKS/202608/00003');
        $this->assertSame(1, (int) $filtered->viewData('summary')->total_dokumen);

        $other = $this->makeSupplier('SUP-009');
        $this->actingAs($viewer)->get('/reports/goods-receipts?supplier_id=' . $other->id)->assertOk()
            ->assertDontSee('PB/BKS/202611/00011')->assertDontSee('PB/BKS/202608/00003');

        $this->actingAs($viewer)->get('/reports/goods-receipts?supplier_id[]=1')->assertOk();
    }

    public function test_supplier_lookup_is_open_to_goods_receipt_report_viewers(): void
    {
        $d = $this->scenario();

        $this->actingAs($this->viewer($d['bks']))->getJson('/lookup/suppliers?q=Sumber')->assertOk();
    }

    public function test_export_headings_include_the_new_columns_and_pdf_shows_values(): void
    {
        $d = $this->scenario();
        $viewer = $this->viewer($d['bks']);

        $rekapHeadings = (new GoodsReceiptReportExport(GoodsReceipt::query(), 'rekap', 's'))->headings();
        $detailHeadings = (new GoodsReceiptReportExport(GoodsReceipt::query(), 'detail', 's'))->headings();
        $this->assertContains('Supplier', $rekapHeadings);
        $this->assertContains('No. Pembelian', $rekapHeadings);
        $this->assertContains('Qty Dipesan', $detailHeadings);

        $this->actingAs($viewer)->get('/reports/goods-receipts/export-excel?mode=detail')->assertOk();
        $text = $this->extractPdfText($this->actingAs($viewer)->get('/reports/goods-receipts/pdf-preview')->getContent());
        $this->assertStringContainsString('PT Sumber Oli Jaya', $text);
        $this->assertStringContainsString($d['order']->number, $text);
    }
}
