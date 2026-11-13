<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ExtractsPdfText;
use Tests\Concerns\GrantsPermissions;
use Tests\Concerns\MakesProcurementData;
use Tests\TestCase;

class PurchaseRequestReportExportTest extends TestCase
{
    use RefreshDatabase, GrantsPermissions, MakesProcurementData, ExtractsPdfText;

    protected function viewerWithData(): User
    {
        $bks = $this->makeBranch('BKS');
        $this->makeAssignedRequest($bks, 4000000, ['number' => 'RQ/BKS/202611/00777', 'request_date' => '2026-11-01']);
        $user = User::factory()->create();
        $this->grantGlobal($user, 'report.purchase_request.view');

        return User::find($user->id);
    }

    public function test_excel_export_returns_xlsx_in_both_modes(): void
    {
        $viewer = $this->viewerWithData();

        foreach (['rekap', 'detail'] as $mode) {
            $this->actingAs($viewer)->get('/reports/purchase-requests/export-excel?mode=' . $mode)
                ->assertOk()
                ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
    }

    public function test_pdf_preview_and_download_use_the_expected_dispositions(): void
    {
        $viewer = $this->viewerWithData();

        $preview = $this->actingAs($viewer)->get('/reports/purchase-requests/pdf-preview');
        $preview->assertOk();
        $this->assertStringContainsString('inline', $preview->headers->get('content-disposition'));

        $download = $this->actingAs($viewer)->get('/reports/purchase-requests/pdf-download');
        $download->assertOk();
        $this->assertStringContainsString('attachment', $download->headers->get('content-disposition'));
    }

    public function test_pdf_contains_document_and_line_columns(): void
    {
        $viewer = $this->viewerWithData();

        $rekap = $this->extractPdfText($this->actingAs($viewer)->get('/reports/purchase-requests/pdf-preview')->getContent());
        $this->assertStringContainsString('RQ/BKS/202611/00777', $rekap);

        $detail = $this->extractPdfText($this->actingAs($viewer)->get('/reports/purchase-requests/pdf-preview?mode=detail')->getContent());
        $this->assertStringContainsString('RQ/BKS/202611/00777', $detail);
        $this->assertStringContainsString('Supplier', $detail);
    }

    public function test_exports_are_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/reports/purchase-requests/export-excel')->assertForbidden();
        $this->actingAs($user)->get('/reports/purchase-requests/pdf-preview')->assertForbidden();
        $this->actingAs($user)->get('/reports/purchase-requests/pdf-download')->assertForbidden();
    }
}
