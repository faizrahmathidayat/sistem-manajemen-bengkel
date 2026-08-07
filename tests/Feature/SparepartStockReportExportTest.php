<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ExtractsPdfText;
use Tests\TestCase;

class SparepartStockReportExportTest extends TestCase
{
    use RefreshDatabase;
    use ExtractsPdfText;

    protected function grantBranchPermission(User $user, Branch $branch, string $code): void
    {
        (new UserBranchService())->assign($user, $branch);
        [$resource, $action] = explode('.', $code, 2);
        $permission = Permission::firstOrCreate(
            ['code' => $code],
            ['resource' => $resource, 'action' => $action, 'description' => $code]
        );
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);
    }

    protected function makeSparepartBranch(Branch $branch, string $code, string $name, float $onHand, float $reserved, float $minimumStock, float $sellingPrice): SparepartBranch
    {
        $sparepart = Sparepart::create(['code' => $code, 'name' => $name]);
        $sparepartBranch = SparepartBranch::create([
            'sparepart_id' => $sparepart->id, 'branch_id' => $branch->id,
            'selling_price' => $sellingPrice, 'minimum_stock' => $minimumStock,
        ]);
        DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update([
            'on_hand_qty' => $onHand, 'reserved_qty' => $reserved,
        ]);

        return $sparepartBranch->fresh();
    }

    public function test_export_excel_returns_xlsx_with_correct_headers(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 10, 0, 5, 50000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock/export-excel');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_export_excel_is_forbidden_without_permission(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/reports/sparepart-stock/export-excel');

        $response->assertForbidden();
    }

    public function test_pdf_preview_returns_inline_disposition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 10, 0, 5, 50000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock/pdf-preview');

        $response->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
    }

    public function test_pdf_download_returns_attachment_disposition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 10, 0, 5, 50000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock/pdf-download');

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    public function test_pdf_actions_are_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/reports/sparepart-stock/pdf-preview')->assertForbidden();
        $this->actingAs($user)->get('/reports/sparepart-stock/pdf-download')->assertForbidden();
    }

    public function test_pdf_preview_respects_stock_status_filter(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'HABIS-1', 'Item Habis', 0, 0, 5, 10000);
        $this->makeSparepartBranch($branch, 'TERSEDIA-1', 'Item Tersedia', 10, 0, 5, 10000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock/pdf-preview?stock_status=habis');

        $response->assertOk();
        $content = $this->extractPdfText($response->getContent());
        $this->assertStringContainsString('HABIS-1', $content);
        $this->assertStringNotContainsString('TERSEDIA-1', $content);
    }

    public function test_pdf_preview_detail_mode_shows_expanded_columns(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 847, 212, 5, 17000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock/pdf-preview?mode=detail');

        $response->assertOk();
        $content = $this->extractPdfText($response->getContent());
        $this->assertStringContainsString('635', $content);
        $this->assertStringContainsString('14.399.000', $content);
    }

    public function test_export_buttons_render_on_the_report_page_with_filters_forwarded(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?stock_status=kritis');

        $response->assertOk();
        $response->assertSee('/reports/sparepart-stock/export-excel?stock_status=kritis', false);
        $response->assertSee('/reports/sparepart-stock/pdf-preview?stock_status=kritis', false);
        $response->assertSee('/reports/sparepart-stock/pdf-download?stock_status=kritis', false);
    }
}
