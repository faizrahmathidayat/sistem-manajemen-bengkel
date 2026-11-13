<?php

namespace Tests\Feature;

use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\PurchaseRequestService;
use App\Support\PurchaseRequestStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsPermissions;
use Tests\Concerns\MakesProcurementData;
use Tests\TestCase;

class PurchaseRequestReportControllerTest extends TestCase
{
    use RefreshDatabase, GrantsPermissions, MakesProcurementData;

    protected function viewer(): User
    {
        $user = User::factory()->create();
        $this->grantGlobal($user, 'report.purchase_request.view');

        return User::find($user->id);
    }

    protected function gudang(): User
    {
        $user = User::factory()->create();
        $this->grantGlobal($user, 'purchase_request.assign_supplier');

        return User::find($user->id);
    }

    /**
     * Four requests across two branches in every interesting state:
     *  A waiting_supplier (BKS, 4 jt), B waiting_approval at Direktur 2/2 (BKS, 38.320.000),
     *  C approved (UTM, 6 jt), D rejected by Manager (UTM, 52,8 jt -> 3 level), E cancelled (BKS).
     */
    protected function seedScenario(): array
    {
        [$manager, $director] = $this->makeStandardApprovalConfig();
        $bks = $this->makeBranch('BKS');
        $utm = $this->makeBranch('UTM', 'Cabang Utama');
        $service = app(PurchaseRequestService::class);
        $gudang = $this->gudang();

        $a = $this->makeAssignedRequest($bks, 4000000, ['number' => 'RQ/BKS/202611/00001', 'request_date' => '2026-11-01']);
        $b = $this->makeAssignedRequest($bks, 38320000, ['number' => 'RQ/BKS/202611/00002', 'request_date' => '2026-11-05']);
        $service->submit($b, $gudang);
        $service->approve($b->fresh(), $manager->users->first());

        $c = $this->makeAssignedRequest($utm, 6000000, ['number' => 'RQ/UTM/202611/00003', 'request_date' => '2026-11-07']);
        $service->submit($c, $gudang);
        $service->approve($c->fresh(), $manager->users->first());

        $d = $this->makeAssignedRequest($utm, 52800000, ['number' => 'RQ/UTM/202611/00004', 'request_date' => '2026-11-09']);
        $service->submit($d, $gudang);
        $service->reject($d->fresh(), $manager->users->first(), 'Harga di atas pasar');

        $e = $this->makeAssignedRequest($bks, 9000000, ['number' => 'RQ/BKS/202611/00005', 'request_date' => '2026-11-10', 'status' => PurchaseRequestStatus::CANCELLED]);

        return compact('a', 'b', 'c', 'd', 'e');
    }

    public function test_report_requires_the_global_permission(): void
    {
        $this->actingAs(User::factory()->create())->get('/reports/purchase-requests')->assertForbidden();

        $branchOnly = User::factory()->create();
        $this->grantBranch($branchOnly, $this->makeBranch('BKS'), 'report.purchase_request.view');
        $this->actingAs(User::find($branchOnly->id))->get('/reports/purchase-requests')->assertForbidden();
    }

    public function test_rekap_lists_every_branch_with_position_and_summary(): void
    {
        $this->seedScenario();

        $response = $this->actingAs($this->viewer())->get('/reports/purchase-requests');

        $response->assertOk()
            ->assertSee('RQ/BKS/202611/00001')->assertSee('RQ/UTM/202611/00003')
            ->assertSee('Menunggu penentuan supplier')
            ->assertSee('Menunggu Direktur (2/2)')
            ->assertSee('Ditolak Manager')->assertSee('Harga di atas pasar')
            ->assertSee('Disetujui')->assertSee('Dibatalkan');

        $summary = $response->viewData('summary');
        $this->assertSame(5, (int) $summary['total_dokumen']);
        $this->assertSame(1, (int) $summary['menunggu_approval']);
        $this->assertSame(1, (int) $summary['ditolak']);
        // Cancelled (9 jt) is not counted: 4 + 38,32 + 6 + 52,8 = 101.120.000
        $this->assertSame(101120000.0, (float) $summary['total_nilai']);
    }

    public function test_filters_by_status_branch_supplier_and_date(): void
    {
        $this->seedScenario();
        $viewer = $this->viewer();

        $this->actingAs($viewer)->get('/reports/purchase-requests?status=rejected')->assertOk()
            ->assertSee('RQ/UTM/202611/00004')->assertDontSee('RQ/BKS/202611/00001');

        $utmId = \App\Models\Branch::where('code', 'UTM')->value('id');
        $this->actingAs($viewer)->get('/reports/purchase-requests?branch_ids[]=' . $utmId)->assertOk()
            ->assertSee('RQ/UTM/202611/00003')->assertDontSee('RQ/BKS/202611/00002');

        $this->actingAs($viewer)->get('/reports/purchase-requests?date_from=2026-11-06&date_to=2026-11-08')->assertOk()
            ->assertSee('RQ/UTM/202611/00003')->assertDontSee('RQ/BKS/202611/00001')->assertDontSee('RQ/UTM/202611/00004');

        $supplierId = PurchaseRequest::where('number', 'RQ/BKS/202611/00002')->first()->lines->first()->supplier_id;
        $this->actingAs($viewer)->get('/reports/purchase-requests?supplier_id=' . $supplierId)->assertOk()
            ->assertSee('RQ/BKS/202611/00002')->assertDontSee('RQ/BKS/202611/00001');
    }

    public function test_detail_mode_lists_lines_with_supplier_and_price_and_filters_by_sparepart(): void
    {
        $scenario = $this->seedScenario();
        $line = $scenario['b']->lines->first();
        $code = $line->sparepartBranch->sparepart->code;
        $sparepartId = $line->sparepartBranch->sparepart_id;

        $this->actingAs($this->viewer())->get('/reports/purchase-requests?mode=detail')->assertOk()
            ->assertSee($code)->assertSee($line->supplier->name)->assertSee('38.320.000');

        $filtered = $this->actingAs($this->viewer())->get('/reports/purchase-requests?mode=detail&sparepart_id=' . $sparepartId);
        $filtered->assertOk()->assertSee('RQ/BKS/202611/00002')->assertDontSee('RQ/BKS/202611/00001');
    }

    public function test_odd_query_input_does_not_break_the_report(): void
    {
        $this->seedScenario();

        $this->actingAs($this->viewer())
            ->get('/reports/purchase-requests?status[]=x&mode[]=y&supplier_id[]=1&date_from[]=2026&branch_ids=oops&sparepart_id[]=3')
            ->assertOk();
    }

    public function test_sidebar_shows_the_report_link_only_with_the_permission(): void
    {
        $this->seedScenario();
        $viewer = $this->viewer();
        $this->grantGlobal($viewer, 'dashboard.view');
        $this->actingAs(User::find($viewer->id))->get('/dashboard')->assertSee(route('reports.purchase-requests.index'), false);

        $other = User::factory()->create();
        $this->grantGlobal($other, 'dashboard.view');
        $this->actingAs(User::find($other->id))->get('/dashboard')->assertDontSee(route('reports.purchase-requests.index'), false);
    }
}
