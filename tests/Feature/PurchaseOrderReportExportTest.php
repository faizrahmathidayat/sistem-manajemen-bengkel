<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ExtractsPdfText;
use Tests\Concerns\GrantsPermissions;
use Tests\Concerns\MakesProcurementData;
use Tests\TestCase;

class PurchaseOrderReportExportTest extends TestCase
{
    use RefreshDatabase, GrantsPermissions, MakesProcurementData, ExtractsPdfText;

    protected function viewerWithData(): array
    {
        $order = $this->makeIssuedPurchaseOrder($this->makeBranch('BKS'));
        $user = User::factory()->create();
        $this->grantGlobal($user, 'report.purchase_order.view');

        return [User::find($user->id), $order];
    }

    public function test_excel_export_returns_xlsx_in_all_three_modes(): void
    {
        [$viewer] = $this->viewerWithData();

        foreach (['rekap', 'detail', 'supplier'] as $mode) {
            $this->actingAs($viewer)->get('/reports/purchase-orders/export-excel?mode=' . $mode)
                ->assertOk()
                ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
    }

    public function test_pdf_preview_and_download_use_the_expected_dispositions(): void
    {
        [$viewer] = $this->viewerWithData();

        $preview = $this->actingAs($viewer)->get('/reports/purchase-orders/pdf-preview');
        $preview->assertOk();
        $this->assertStringContainsString('inline', $preview->headers->get('content-disposition'));

        $download = $this->actingAs($viewer)->get('/reports/purchase-orders/pdf-download');
        $download->assertOk();
        $this->assertStringContainsString('attachment', $download->headers->get('content-disposition'));
    }

    public function test_pdf_text_contains_the_order_number_and_mode_columns(): void
    {
        [$viewer, $order] = $this->viewerWithData();

        $rekap = $this->extractPdfText($this->actingAs($viewer)->get('/reports/purchase-orders/pdf-preview')->getContent());
        $this->assertStringContainsString($order->number, $rekap);
        $this->assertStringContainsString('Outstanding', $rekap);

        $detail = $this->extractPdfText($this->actingAs($viewer)->get('/reports/purchase-orders/pdf-preview?mode=detail')->getContent());
        $this->assertStringContainsString('Sisa', $detail);

        $supplier = $this->extractPdfText($this->actingAs($viewer)->get('/reports/purchase-orders/pdf-preview?mode=supplier')->getContent());
        $this->assertStringContainsString($order->supplier->name, $supplier);
    }

    public function test_exports_are_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/reports/purchase-orders/export-excel')->assertForbidden();
        $this->actingAs($user)->get('/reports/purchase-orders/pdf-preview')->assertForbidden();
        $this->actingAs($user)->get('/reports/purchase-orders/pdf-download')->assertForbidden();
    }
}
