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
use Tests\TestCase;

class GoodsReceiptReportControllerTest extends TestCase
{
    use RefreshDatabase;

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

    protected function makeSparepartBranch(Branch $branch, string $code, string $name): SparepartBranch
    {
        $sparepart = Sparepart::firstOrCreate(['code' => $code], ['name' => $name]);

        return SparepartBranch::create([
            'sparepart_id' => $sparepart->id,
            'branch_id' => $branch->id,
            'selling_price' => 100000,
            'minimum_stock' => 0,
        ]);
    }

    protected function makeReceipt(Branch $branch, string $number, string $date, string $status, array $lines, ?string $reference = null): GoodsReceipt
    {
        $receipt = GoodsReceipt::create([
            'number' => $number,
            'branch_id' => $branch->id,
            'receipt_date' => $date,
            'reference_number' => $reference,
            'status' => $status,
        ]);

        foreach ($lines as $i => [$sparepartBranch, $qty, $price]) {
            GoodsReceiptLine::create([
                'goods_receipt_id' => $receipt->id,
                'sparepart_branch_id' => $sparepartBranch->id,
                'qty' => $qty,
                'purchase_price' => $price,
                'line_total' => $qty * $price,
                'sort_order' => $i,
            ]);
        }

        return $receipt;
    }

    protected function viewer(Branch $branch): User
    {
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.goods_receipt.view');

        return $user;
    }

    public function test_index_shows_no_access_view_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/reports/goods-receipts');

        $response->assertOk();
        $response->assertSee('belum memiliki akses', false);
    }

    public function test_rekap_lists_one_row_per_receipt_with_totals(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $a = $this->makeSparepartBranch($branch, 'SP-A', 'Oli Mesin');
        $b = $this->makeSparepartBranch($branch, 'SP-B', 'Kampas Rem');
        $this->makeReceipt($branch, 'GR-001', '2026-09-01', GoodsReceiptStatus::POSTED, [[$a, 4, 50000], [$b, 2, 100000]], 'SJ-77');

        $response = $this->actingAs($this->viewer($branch))->get('/reports/goods-receipts');

        $response->assertOk();
        $response->assertSee('GR-001');
        $response->assertSee('SJ-77');
        $response->assertDontSee('Oli Mesin');
        $this->assertEquals(6.0, (float) $response->viewData('summary')->total_qty);
        $this->assertEquals(400000.0, (float) $response->viewData('summary')->total_nilai);
        $this->assertSame(1, (int) $response->viewData('summary')->total_dokumen);
    }

    public function test_cancelled_receipts_are_listed_but_excluded_from_summary(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $a = $this->makeSparepartBranch($branch, 'SP-A', 'Oli Mesin');
        $this->makeReceipt($branch, 'GR-OK', '2026-09-01', GoodsReceiptStatus::POSTED, [[$a, 1, 10000]]);
        $this->makeReceipt($branch, 'GR-DRAFT', '2026-09-02', GoodsReceiptStatus::DRAFT, [[$a, 2, 10000]]);
        $this->makeReceipt($branch, 'GR-CANCEL', '2026-09-03', GoodsReceiptStatus::CANCELLED, [[$a, 100, 10000]]);

        $response = $this->actingAs($this->viewer($branch))->get('/reports/goods-receipts');

        $response->assertSee('GR-CANCEL');
        $this->assertSame(2, (int) $response->viewData('summary')->total_dokumen);
        $this->assertEquals(3.0, (float) $response->viewData('summary')->total_qty);
        $this->assertEquals(30000.0, (float) $response->viewData('summary')->total_nilai);
    }

    public function test_detail_mode_lists_one_row_per_line(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $a = $this->makeSparepartBranch($branch, 'SP-A', 'Oli Mesin');
        $b = $this->makeSparepartBranch($branch, 'SP-B', 'Kampas Rem');
        $this->makeReceipt($branch, 'GR-001', '2026-09-01', GoodsReceiptStatus::POSTED, [[$a, 4, 50000], [$b, 2, 100000]]);

        $response = $this->actingAs($this->viewer($branch))->get('/reports/goods-receipts?mode=detail');

        $response->assertOk();
        $response->assertSee('Oli Mesin');
        $response->assertSee('Kampas Rem');
        $this->assertCount(2, $response->viewData('rows'));
    }

    public function test_sparepart_filter_applies_in_detail_mode_only(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $a = $this->makeSparepartBranch($branch, 'SP-A', 'Oli Mesin');
        $b = $this->makeSparepartBranch($branch, 'SP-B', 'Kampas Rem');
        $this->makeReceipt($branch, 'GR-001', '2026-09-01', GoodsReceiptStatus::POSTED, [[$a, 4, 50000], [$b, 2, 100000]]);
        $user = $this->viewer($branch);

        $detail = $this->actingAs($user)->get('/reports/goods-receipts?mode=detail&sparepart_id=' . $a->sparepart_id);
        $detail->assertSee('Oli Mesin');
        $detail->assertDontSee('Kampas Rem');
        $this->assertCount(1, $detail->viewData('rows'));
        $this->assertEquals(200000.0, (float) $detail->viewData('summary')->total_nilai);

        $rekap = $this->actingAs($user)->get('/reports/goods-receipts?mode=rekap&sparepart_id=' . $a->sparepart_id);
        $this->assertEquals(400000.0, (float) $rekap->viewData('summary')->total_nilai);
    }

    public function test_filters_by_date_range_and_status(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $a = $this->makeSparepartBranch($branch, 'SP-A', 'Oli Mesin');
        $this->makeReceipt($branch, 'GR-OLD', '2026-08-01', GoodsReceiptStatus::POSTED, [[$a, 1, 1000]]);
        $this->makeReceipt($branch, 'GR-NEW', '2026-09-10', GoodsReceiptStatus::POSTED, [[$a, 1, 1000]]);
        $this->makeReceipt($branch, 'GR-DRF', '2026-09-11', GoodsReceiptStatus::DRAFT, [[$a, 1, 1000]]);
        $user = $this->viewer($branch);

        $range = $this->actingAs($user)->get('/reports/goods-receipts?date_from=2026-09-01&date_to=2026-09-30');
        $range->assertSee('GR-NEW');
        $range->assertDontSee('GR-OLD');

        $status = $this->actingAs($user)->get('/reports/goods-receipts?status=draft');
        $status->assertSee('GR-DRF');
        $status->assertDontSee('GR-NEW');
    }

    public function test_only_permitted_branches_are_shown_and_branch_filter_is_clamped(): void
    {
        $jkt = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $bdg = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $a = $this->makeSparepartBranch($jkt, 'SP-A', 'Oli Mesin');
        $b = $this->makeSparepartBranch($bdg, 'SP-A', 'Oli Mesin');
        $this->makeReceipt($jkt, 'GR-JKT', '2026-09-01', GoodsReceiptStatus::POSTED, [[$a, 1, 1000]]);
        $this->makeReceipt($bdg, 'GR-BDG', '2026-09-01', GoodsReceiptStatus::POSTED, [[$b, 1, 1000]]);

        $response = $this->actingAs($this->viewer($jkt))->get('/reports/goods-receipts?branch_ids[]=' . $bdg->id);

        $response->assertSee('GR-JKT');
        $response->assertDontSee('GR-BDG');
    }

    public function test_lookup_returns_only_spareparts_in_permitted_branches(): void
    {
        $jkt = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $bdg = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $mine = $this->makeSparepartBranch($jkt, 'SP-AAA', 'Oli Mesin');
        $this->makeSparepartBranch($bdg, 'SP-BBB', 'Oli Gardan');
        $user = $this->viewer($jkt);

        $response = $this->actingAs($user)->getJson('/lookup/report-spareparts?q=Oli');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['id' => $mine->sparepart_id, 'text' => 'SP-AAA — Oli Mesin']);

        $byId = $this->actingAs($user)->getJson('/lookup/report-spareparts?ids[]=' . $mine->sparepart_id);
        $byId->assertJsonCount(1);
    }

    public function test_lookup_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/lookup/report-spareparts?q=Oli')->assertForbidden();
    }
}
