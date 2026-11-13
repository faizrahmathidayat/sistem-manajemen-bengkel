<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\PurchaseOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsPermissions;
use Tests\Concerns\MakesProcurementData;
use Tests\TestCase;

class PurchaseOrderReportControllerTest extends TestCase
{
    use RefreshDatabase, GrantsPermissions, MakesProcurementData;

    protected function viewer(): User
    {
        $user = User::factory()->create();
        $this->grantGlobal($user, 'report.purchase_order.view');

        return User::find($user->id);
    }

    /**
     * PO1 BKS/S1 partially received (9,5 jt of 12,9 jt), PO2 BKS/S2 draft, PO3 UTM/S1 completed,
     * PO4 UTM/S3 cancelled. Every PO has 120 @ 85.000 + 60 @ 45.000 = 12.900.000.
     */
    protected function scenario(): array
    {
        $bks = $this->makeBranch('BKS');
        $utm = $this->makeBranch('UTM', 'Cabang Utama');
        $s1 = $this->makeSupplier('SUP-001', ['name' => 'PT Sumber Oli Jaya']);
        $s2 = $this->makeSupplier('SUP-002', ['name' => 'CV Mitra Otomotif']);
        $s3 = $this->makeSupplier('SUP-003', ['name' => 'UD Sinar Spare']);

        $po1 = $this->makeIssuedPurchaseOrder($bks, PurchaseOrderStatus::PARTIALLY_RECEIVED, $s1);
        $po1->lines[0]->update(['qty_received' => 80]);
        $po1->lines[1]->update(['qty_received' => 60]);
        $po2 = $this->makeIssuedPurchaseOrder($bks, PurchaseOrderStatus::DRAFT, $s2);
        $po3 = $this->makeIssuedPurchaseOrder($utm, PurchaseOrderStatus::COMPLETED, $s1);
        $po3->lines[0]->update(['qty_received' => 120]);
        $po3->lines[1]->update(['qty_received' => 60]);
        $po4 = $this->makeIssuedPurchaseOrder($utm, PurchaseOrderStatus::CANCELLED, $s3);

        return compact('po1', 'po2', 'po3', 'po4', 's1', 's2', 's3', 'bks', 'utm');
    }

    public function test_report_requires_the_global_permission(): void
    {
        $this->actingAs(User::factory()->create())->get('/reports/purchase-orders')->assertForbidden();
    }

    public function test_rekap_shows_value_received_outstanding_and_percentage(): void
    {
        $d = $this->scenario();

        $response = $this->actingAs($this->viewer())->get('/reports/purchase-orders');

        $response->assertOk()
            ->assertSee($d['po1']->number)->assertSee($d['po2']->number)->assertSee($d['po4']->number)
            ->assertSee('9.500.000')->assertSee('3.400.000')->assertSee('78%');

        $summary = $response->viewData('summary');
        $this->assertSame(3, (int) $summary['jumlah_po']); // cancelled is not counted
        $this->assertSame(38700000.0, (float) $summary['nilai_dipesan']);
        $this->assertSame(22400000.0, (float) $summary['nilai_diterima']);
        $this->assertSame(16300000.0, (float) $summary['nilai_outstanding']);
    }

    public function test_outstanding_filter_keeps_only_issued_and_partially_received_orders(): void
    {
        $d = $this->scenario();

        $response = $this->actingAs($this->viewer())->get('/reports/purchase-orders?outstanding=1');

        $response->assertOk()->assertSee($d['po1']->number)
            ->assertDontSee($d['po2']->number)->assertDontSee($d['po3']->number)->assertDontSee($d['po4']->number);
        $summary = $response->viewData('summary');
        $this->assertSame(1, (int) $summary['jumlah_po']);
        $this->assertSame(3400000.0, (float) $summary['nilai_outstanding']);
    }

    public function test_filters_by_branch_supplier_status_and_date(): void
    {
        $d = $this->scenario();
        $viewer = $this->viewer();
        $d['po1']->update(['order_date' => '2026-11-02']);
        $d['po3']->update(['order_date' => '2026-11-20']);

        $this->actingAs($viewer)->get('/reports/purchase-orders?branch_ids[]=' . $d['utm']->id)->assertOk()
            ->assertSee($d['po3']->number)->assertDontSee($d['po1']->number);
        $this->actingAs($viewer)->get('/reports/purchase-orders?supplier_id=' . $d['s2']->id)->assertOk()
            ->assertSee($d['po2']->number)->assertDontSee($d['po1']->number);
        $this->actingAs($viewer)->get('/reports/purchase-orders?status=completed')->assertOk()
            ->assertSee($d['po3']->number)->assertDontSee($d['po1']->number);
        $this->actingAs($viewer)->get('/reports/purchase-orders?date_from=2026-11-15&date_to=2026-11-30')->assertOk()
            ->assertSee($d['po3']->number)->assertDontSee($d['po1']->number);
    }

    public function test_detail_mode_shows_ordered_received_remaining_and_filters_by_sparepart(): void
    {
        $d = $this->scenario();
        $line = $d['po1']->lines[0];
        $sparepartId = $line->sparepartBranch->sparepart_id;

        $response = $this->actingAs($this->viewer())->get('/reports/purchase-orders?mode=detail');
        $response->assertOk()->assertSee($line->sparepartBranch->sparepart->code)->assertSee('Sisa')->assertSee('85.000');

        $filtered = $this->actingAs($this->viewer())->get('/reports/purchase-orders?mode=detail&sparepart_id=' . $sparepartId);
        $filtered->assertOk()->assertSee($d['po1']->number)->assertDontSee($d['po2']->number);
    }

    public function test_per_supplier_mode_aggregates_orders_value_received_and_outstanding(): void
    {
        $d = $this->scenario();

        $response = $this->actingAs($this->viewer())->get('/reports/purchase-orders?mode=supplier');

        $response->assertOk()->assertSee('PT Sumber Oli Jaya')->assertSee('CV Mitra Otomotif')->assertDontSee('UD Sinar Spare');
        $rows = collect($response->viewData('rows')->items())->keyBy('supplier_name');
        $this->assertSame(2, (int) $rows['PT Sumber Oli Jaya']->po_count);
        $this->assertSame(25800000.0, (float) $rows['PT Sumber Oli Jaya']->ordered_value);
        $this->assertSame(22400000.0, (float) $rows['PT Sumber Oli Jaya']->received_value);
        $this->assertSame(1, (int) $rows['CV Mitra Otomotif']->po_count);
        $this->assertSame(0.0, (float) $rows['CV Mitra Otomotif']->received_value);
    }

    public function test_cancelled_orders_are_listed_but_never_counted(): void
    {
        $d = $this->scenario();

        $response = $this->actingAs($this->viewer())->get('/reports/purchase-orders?status=cancelled');

        $response->assertOk()->assertSee($d['po4']->number);
        $summary = $response->viewData('summary');
        $this->assertSame(0, (int) $summary['jumlah_po']);
        $this->assertSame(0.0, (float) $summary['nilai_dipesan']);
    }

    public function test_odd_query_input_does_not_break_the_report(): void
    {
        $this->scenario();

        $this->actingAs($this->viewer())
            ->get('/reports/purchase-orders?status[]=x&mode[]=y&supplier_id[]=1&outstanding[]=1&date_to[]=2026&branch_ids=oops')
            ->assertOk();
    }

    public function test_sidebar_link_follows_the_permission(): void
    {
        $viewer = $this->viewer();
        $this->grantGlobal($viewer, 'dashboard.view');
        $this->actingAs(User::find($viewer->id))->get('/dashboard')->assertSee(route('reports.purchase-orders.index'), false);

        $other = User::factory()->create();
        $this->grantGlobal($other, 'dashboard.view');
        $this->actingAs(User::find($other->id))->get('/dashboard')->assertDontSee(route('reports.purchase-orders.index'), false);
    }
}
