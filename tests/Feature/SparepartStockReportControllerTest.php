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
use Tests\TestCase;

class SparepartStockReportControllerTest extends TestCase
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

    protected function makeSparepartBranch(
        Branch $branch,
        string $code,
        string $name,
        float $onHand,
        float $reserved,
        float $minimumStock,
        float $sellingPrice
    ): SparepartBranch {
        $sparepart = Sparepart::create(['code' => $code, 'name' => $name]);
        $sparepartBranch = SparepartBranch::create([
            'sparepart_id' => $sparepart->id,
            'branch_id' => $branch->id,
            'selling_price' => $sellingPrice,
            'minimum_stock' => $minimumStock,
        ]);
        DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update([
            'on_hand_qty' => $onHand,
            'reserved_qty' => $reserved,
        ]);

        return $sparepartBranch->fresh();
    }

    public function test_index_shows_no_access_view_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/reports/sparepart-stock');

        $response->assertOk();
        $response->assertSee('belum memiliki akses', false);
    }

    public function test_index_lists_stock_rows_for_permitted_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 10, 2, 5, 50000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock');

        $response->assertOk();
        $response->assertSee('OLI-001');
        $response->assertSee('Oli Mesin');
    }

    public function test_index_is_scoped_to_permitted_branches_only(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->makeSparepartBranch($branchA, 'OLI-A', 'Oli A', 10, 0, 5, 50000);
        $this->makeSparepartBranch($branchB, 'OLI-B', 'Oli B', 10, 0, 5, 50000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branchA, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock');

        $response->assertOk();
        $response->assertSee('OLI-A');
        $response->assertDontSee('OLI-B');
    }

    public function test_index_filters_by_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->makeSparepartBranch($branchA, 'OLI-A', 'Oli A', 10, 0, 5, 50000);
        $this->makeSparepartBranch($branchB, 'OLI-B', 'Oli B', 10, 0, 5, 50000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branchA, 'report.sparepart.view');
        $this->grantBranchPermission($viewer, $branchB, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get("/reports/sparepart-stock?branch_ids[]={$branchA->id}");

        $response->assertOk();
        $response->assertSee('OLI-A');
        $response->assertDontSee('OLI-B');
    }

    public function test_index_filters_by_search_matching_code(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 10, 0, 5, 50000);
        $this->makeSparepartBranch($branch, 'FIL-002', 'Filter Udara', 10, 0, 5, 30000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?search=OLI-001');

        $response->assertOk();
        $response->assertSee('OLI-001');
        $response->assertDontSee('FIL-002');
    }

    public function test_index_filters_by_search_matching_name(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 10, 0, 5, 50000);
        $this->makeSparepartBranch($branch, 'FIL-002', 'Filter Udara', 10, 0, 5, 30000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?search=' . urlencode('Filter Udara'));

        $response->assertOk();
        $response->assertSee('FIL-002');
        $response->assertDontSee('OLI-001');
    }

    public function test_index_stock_status_default_semua_shows_all(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'HABIS-1', 'Item Habis', 0, 0, 5, 10000);
        $this->makeSparepartBranch($branch, 'KRITIS-1', 'Item Kritis', 2, 0, 5, 10000);
        $this->makeSparepartBranch($branch, 'TERSEDIA-1', 'Item Tersedia', 10, 0, 5, 10000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock');

        $response->assertOk();
        $response->assertSee('HABIS-1');
        $response->assertSee('KRITIS-1');
        $response->assertSee('TERSEDIA-1');
    }

    public function test_index_stock_status_habis(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'HABIS-1', 'Item Habis', 0, 0, 5, 10000);
        $this->makeSparepartBranch($branch, 'KRITIS-1', 'Item Kritis', 2, 0, 5, 10000);
        $this->makeSparepartBranch($branch, 'TERSEDIA-1', 'Item Tersedia', 10, 0, 5, 10000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?stock_status=habis');

        $response->assertOk();
        $response->assertSee('HABIS-1');
        $response->assertDontSee('KRITIS-1');
        $response->assertDontSee('TERSEDIA-1');
    }

    public function test_index_stock_status_kritis(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'HABIS-1', 'Item Habis', 0, 0, 5, 10000);
        $this->makeSparepartBranch($branch, 'KRITIS-1', 'Item Kritis', 2, 0, 5, 10000);
        $this->makeSparepartBranch($branch, 'TERSEDIA-1', 'Item Tersedia', 10, 0, 5, 10000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?stock_status=kritis');

        $response->assertOk();
        $response->assertDontSee('HABIS-1');
        $response->assertSee('KRITIS-1');
        $response->assertDontSee('TERSEDIA-1');
    }

    public function test_index_stock_status_tersedia(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'HABIS-1', 'Item Habis', 0, 0, 5, 10000);
        $this->makeSparepartBranch($branch, 'KRITIS-1', 'Item Kritis', 2, 0, 5, 10000);
        $this->makeSparepartBranch($branch, 'TERSEDIA-1', 'Item Tersedia', 10, 0, 5, 10000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?stock_status=tersedia');

        $response->assertOk();
        $response->assertDontSee('HABIS-1');
        $response->assertDontSee('KRITIS-1');
        $response->assertSee('TERSEDIA-1');
    }

    public function test_index_invalid_stock_status_value_falls_back_to_semua(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'HABIS-1', 'Item Habis', 0, 0, 5, 10000);
        $this->makeSparepartBranch($branch, 'TERSEDIA-1', 'Item Tersedia', 10, 0, 5, 10000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?stock_status=bogus');

        $response->assertOk();
        $response->assertViewHas('stockStatus', 'semua');
        $response->assertSee('HABIS-1');
        $response->assertSee('TERSEDIA-1');
    }

    public function test_index_computes_summary_cards_correctly(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        // Habis: on_hand=0, min=5, price=50000 -> counts as kritis too (available 0 < 5).
        $this->makeSparepartBranch($branch, 'HABIS-1', 'Item Habis', 0, 0, 5, 50000);
        // Kritis: on_hand=2, reserved=0, min=5, price=30000 -> available 2 < 5.
        $this->makeSparepartBranch($branch, 'KRITIS-1', 'Item Kritis', 2, 0, 5, 30000);
        // Tersedia: on_hand=10, reserved=0, min=5, price=20000 -> available 10 >= 5.
        $this->makeSparepartBranch($branch, 'TERSEDIA-1', 'Item Tersedia', 10, 0, 5, 20000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?stock_status=semua');

        $response->assertOk();
        $response->assertViewHas('summary', function ($summary) {
            return (int) $summary->total_jenis_item === 3
                && (float) $summary->total_qty_on_hand === 12.0
                && (int) $summary->total_item_kritis === 2
                && (float) $summary->total_nilai_inventaris === 260000.0;
        });
    }

    public function test_index_summary_cards_stay_independent_of_the_active_stock_status_filter(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'HABIS-1', 'Item Habis', 0, 0, 5, 50000);
        $this->makeSparepartBranch($branch, 'KRITIS-1', 'Item Kritis', 2, 0, 5, 30000);
        $this->makeSparepartBranch($branch, 'TERSEDIA-1', 'Item Tersedia', 10, 0, 5, 20000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        // Even though the row listing is narrowed to only "tersedia" rows, the summary cards must
        // still reflect the FULL branch/search-filtered set (all 3 items, 2 of them kritis) — this
        // guards against summary computed from an already stock_status-filtered query, which would
        // wrongly zero out "Total Item Kritis" whenever a specific (non-"semua") status is active.
        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?stock_status=tersedia');

        $response->assertOk();
        $response->assertViewHas('summary', function ($summary) {
            return (int) $summary->total_jenis_item === 3
                && (float) $summary->total_qty_on_hand === 12.0
                && (int) $summary->total_item_kritis === 2
                && (float) $summary->total_nilai_inventaris === 260000.0;
        });
    }

    public function test_index_defaults_to_rekap_mode(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 10, 0, 5, 50000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock');

        $response->assertOk();
        $response->assertViewHas('mode', 'rekap');
    }

    public function test_index_invalid_mode_value_falls_back_to_rekap(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 10, 0, 5, 50000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?mode=bogus');

        $response->assertOk();
        $response->assertViewHas('mode', 'rekap');
    }

    public function test_index_detail_mode_exposes_reserved_and_on_hand_data(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 10, 3, 5, 20000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?mode=detail');

        $response->assertOk();
        $response->assertViewHas('mode', 'detail');
        $response->assertViewHas('sparepartBranches', function ($rows) {
            $row = $rows->first();

            return (float) $row->on_hand_qty === 10.0 && (float) $row->reserved_qty === 3.0;
        });
    }

    public function test_index_renders_filter_form_and_summary_cards(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 10, 0, 5, 50000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock');

        $response->assertOk();
        $response->assertSee('Total Jenis Item');
        $response->assertSee('Total Qty On-Hand');
        $response->assertSee('Total Item Kritis');
        $response->assertSee('Total Nilai Inventaris');
        $response->assertSee('name="search"', false);
        $response->assertSee('<option value="semua" selected>Semua</option>', false);
        $response->assertSee('<option value="rekap" selected>Rekap</option>', false);
    }

    public function test_index_rekap_mode_shows_columns_and_status_badges(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'HABIS-1', 'Item Habis', 0, 0, 5, 10000);
        $this->makeSparepartBranch($branch, 'KRITIS-1', 'Item Kritis', 2, 0, 5, 10000);
        $this->makeSparepartBranch($branch, 'TERSEDIA-1', 'Item Tersedia', 10, 0, 5, 10000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?stock_status=semua');

        $response->assertOk();
        $response->assertSee('Stok Min');
        $response->assertSee('Nilai Inventaris');
        $response->assertSee('>Habis<', false);
        $response->assertSee('>Kritis<', false);
        $response->assertSee('>Tersedia<', false);
    }

    public function test_index_detail_mode_shows_expanded_columns(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        // Distinctive multi-digit amounts — a bare single-digit assertSee (e.g. "7") is unsafe,
        // it can false-match unrelated digits elsewhere on the page (established project lesson,
        // see Kartu Stok's own test-fragility fix in bengkel_foundation_decisions memory).
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 847, 212, 5, 17000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock?mode=detail');

        $response->assertOk();
        $response->assertSee('Reserved');
        $response->assertSee('Available');
        $response->assertSee('Harga Satuan');
        $response->assertSee('Nilai Total');
        // available = 847 - 212 = 635; nilai total = 847 * 17000 = 14.399.000
        $response->assertSee('635');
        $response->assertSee('14.399.000');
    }

    public function test_index_rekap_mode_does_not_show_detail_columns(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makeSparepartBranch($branch, 'OLI-001', 'Oli Mesin', 10, 0, 5, 50000);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock');

        $response->assertOk();
        $response->assertDontSee('Reserved');
        $response->assertDontSee('Available');
        $response->assertSee('Nilai Inventaris');
    }

    public function test_index_shows_empty_state_when_no_results_match_filter(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.sparepart.view');

        $response = $this->actingAs($viewer)->get('/reports/sparepart-stock');

        $response->assertOk();
        $response->assertSee('Tidak ada sparepart yang cocok dengan filter saat ini.');
    }
}
