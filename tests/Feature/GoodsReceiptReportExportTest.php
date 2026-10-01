<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Permission;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use App\Support\GoodsReceiptStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ExtractsPdfText;
use Tests\TestCase;

class GoodsReceiptReportExportTest extends TestCase
{
    use RefreshDatabase;
    use ExtractsPdfText;

    protected function viewerWithData(): User
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        $permission = Permission::firstOrCreate(
            ['code' => 'report.goods_receipt.view'],
            ['resource' => 'report', 'action' => 'goods_receipt.view', 'description' => 'x']
        );
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $sparepart = Sparepart::create(['code' => 'OLI-001', 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create([
            'sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 1000, 'minimum_stock' => 0,
        ]);
        $receipt = GoodsReceipt::create([
            'number' => 'GR-777', 'branch_id' => $branch->id, 'receipt_date' => '2026-09-01',
            'status' => GoodsReceiptStatus::POSTED,
        ]);
        GoodsReceiptLine::create([
            'goods_receipt_id' => $receipt->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'qty' => 3, 'purchase_price' => 500, 'line_total' => 1500, 'sort_order' => 0,
        ]);

        return $user;
    }

    public function test_export_excel_returns_xlsx_in_both_modes(): void
    {
        $viewer = $this->viewerWithData();

        foreach (['rekap', 'detail'] as $mode) {
            $response = $this->actingAs($viewer)->get('/reports/goods-receipts/export-excel?mode=' . $mode);

            $response->assertOk();
            $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
    }

    public function test_pdf_preview_and_download_use_expected_dispositions(): void
    {
        $viewer = $this->viewerWithData();

        $preview = $this->actingAs($viewer)->get('/reports/goods-receipts/pdf-preview');
        $preview->assertOk();
        $this->assertStringContainsString('inline', $preview->headers->get('content-disposition'));

        $download = $this->actingAs($viewer)->get('/reports/goods-receipts/pdf-download');
        $download->assertOk();
        $this->assertStringContainsString('attachment', $download->headers->get('content-disposition'));
    }

    public function test_pdf_detail_mode_contains_line_columns(): void
    {
        $viewer = $this->viewerWithData();

        $response = $this->actingAs($viewer)->get('/reports/goods-receipts/pdf-preview?mode=detail');

        $text = $this->extractPdfText($response->getContent());
        $this->assertStringContainsString('GR-777', $text);
        $this->assertStringContainsString('OLI-001', $text);
    }

    public function test_exports_are_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/reports/goods-receipts/export-excel')->assertForbidden();
        $this->actingAs($user)->get('/reports/goods-receipts/pdf-preview')->assertForbidden();
        $this->actingAs($user)->get('/reports/goods-receipts/pdf-download')->assertForbidden();
    }
}
