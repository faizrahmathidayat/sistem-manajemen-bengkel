<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Invoice;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\Permission;
use App\Models\ServiceCatalog;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use App\Models\WorkOrder;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoicePkbGapReportControllerTest extends TestCase
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

    /**
     * Creates a real PKB -> Invoice pair via the actual HTTP flow (work order create/confirm/complete,
     * invoice create, optional edit to introduce a gap, optional post). Returns the fresh Invoice,
     * fresh WorkOrder, and the expected pkb_total (sum of the ORIGINAL PKB line amounts — pkb_total
     * never changes after PKB completion regardless of any later invoice edit).
     *
     * @return array{invoice: Invoice, workOrder: WorkOrder, pkbTotal: float}
     */
    protected function makeGapPair(
        Branch $branch,
        Customer $customer,
        float $serviceAmount,
        float $sparepartAmount,
        string $invoiceDate,
        ?array $editPayload = null,
        bool $post = true
    ): array {
        CustomerBranch::firstOrCreate(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::firstOrCreate(['name' => "Mobil {$branch->code}"]);
        $brand = VehicleBrand::firstOrCreate(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::firstOrCreate(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id,
            'plate_number' => 'B ' . random_int(1000, 9999) . ' ' . $branch->code,
        ]);
        $mechanic = Mechanic::firstOrCreate(['name' => "Mekanik {$branch->code}"]);
        MechanicBranch::firstOrCreate(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $catalog = ServiceCatalog::create(['code' => 'SVC-' . random_int(1000, 9999), 'name' => 'Ganti Oli', 'default_price' => $serviceAmount]);

        $spareparts = [];
        if ($sparepartAmount > 0) {
            $sparepart = Sparepart::create(['code' => 'OLI-' . random_int(1000, 9999), 'name' => 'Oli Mesin']);
            $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => $sparepartAmount]);
            DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => 10]);
            $spareparts = [['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 1, 'unit_price' => $sparepartAmount]];
        }

        $pkbUser = User::factory()->create();
        foreach (['pkb.create', 'pkb.confirm', 'pkb.complete', 'invoice.create', 'invoice.edit', 'invoice.post'] as $code) {
            $this->grantBranchPermission($pkbUser, $branch, $code);
        }

        $this->actingAs($pkbUser)->post('/work-orders', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id,
            'work_order_date' => now()->format('Y-m-d'),
            'services' => [
                ['service_catalog_id' => $catalog->id, 'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => $serviceAmount],
            ],
            'spareparts' => $spareparts,
        ]);
        $workOrder = WorkOrder::latest('id')->first();
        $this->actingAs($pkbUser)->patch("/work-orders/{$workOrder->id}/confirm");
        $this->actingAs($pkbUser)->patch("/work-orders/{$workOrder->id}/complete");

        $this->actingAs($pkbUser)->post('/invoices', ['work_order_id' => $workOrder->id]);
        $invoice = Invoice::where('work_order_id', $workOrder->id)->firstOrFail();

        if ($editPayload) {
            $this->actingAs($pkbUser)->put("/invoices/{$invoice->id}", $editPayload);
            $invoice = $invoice->fresh();
        }

        if ($post) {
            $this->actingAs($pkbUser)->patch("/invoices/{$invoice->id}/post");
            $invoice = $invoice->fresh();
        }

        $invoice->update(['invoice_date' => $invoiceDate]);

        return [
            'invoice' => $invoice->fresh('details'),
            'workOrder' => $workOrder->fresh(),
            'pkbTotal' => round($serviceAmount + $sparepartAmount, 2),
        ];
    }

    public function test_index_shows_no_access_view_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/reports/invoice-pkb-gap');

        $response->assertOk();
        $response->assertSee('belum memiliki akses', false);
    }

    public function test_index_lists_gap_pairs_for_permitted_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $pair = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap');

        $response->assertOk();
        $response->assertSee($pair['workOrder']->number);
        $response->assertSee($pair['invoice']->number);
    }

    public function test_index_is_scoped_to_permitted_branches_only(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Dewi Lestari', 'stnk_name' => 'Dewi Lestari']);
        $editPayload = fn ($amount) => [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => $amount]],
            'spareparts' => [],
        ];
        $pairA = $this->makeGapPair($branchA, $customerA, 100000, 0, now()->toDateString(), $editPayload(100000));
        $pairB = $this->makeGapPair($branchB, $customerB, 100000, 0, now()->toDateString(), $editPayload(100000));
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branchA, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap');

        $response->assertOk();
        $response->assertSee($pairA['invoice']->number);
        $response->assertDontSee($pairB['invoice']->number);
    }

    public function test_index_filters_by_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Dewi Lestari', 'stnk_name' => 'Dewi Lestari']);
        $editPayload = fn ($amount) => [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => $amount]],
            'spareparts' => [],
        ];
        $pairA = $this->makeGapPair($branchA, $customerA, 100000, 0, now()->toDateString(), $editPayload(100000));
        $pairB = $this->makeGapPair($branchB, $customerB, 100000, 0, now()->toDateString(), $editPayload(100000));
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branchA, 'report.invoice_pkb_gap.view');
        $this->grantBranchPermission($viewer, $branchB, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get("/reports/invoice-pkb-gap?branch_ids[]={$branchA->id}");

        $response->assertOk();
        $response->assertSee($pairA['invoice']->number);
        $response->assertDontSee($pairB['invoice']->number);
    }

    public function test_index_filters_by_search_matching_invoice_number(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $editPayload = ['discount_percent' => 0, 'tax_percent' => 10, 'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]], 'spareparts' => []];
        $pairA = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), $editPayload);
        $pairB = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), $editPayload);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?search=' . urlencode($pairA['invoice']->number));

        $response->assertOk();
        $response->assertSee($pairA['invoice']->number);
        $response->assertDontSee($pairB['invoice']->number);
    }

    public function test_index_filters_by_search_matching_work_order_number(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $editPayload = ['discount_percent' => 0, 'tax_percent' => 10, 'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]], 'spareparts' => []];
        $pairA = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), $editPayload);
        $pairB = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), $editPayload);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?search=' . urlencode($pairA['workOrder']->number));

        $response->assertOk();
        $response->assertSee($pairA['invoice']->number);
        $response->assertDontSee($pairB['invoice']->number);
    }

    public function test_index_filters_by_search_matching_customer_name(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Dewi Lestari', 'stnk_name' => 'Dewi Lestari']);
        $editPayload = ['discount_percent' => 0, 'tax_percent' => 10, 'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]], 'spareparts' => []];
        $pairA = $this->makeGapPair($branch, $customerA, 100000, 0, now()->toDateString(), $editPayload);
        $pairB = $this->makeGapPair($branch, $customerB, 100000, 0, now()->toDateString(), $editPayload);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?search=Budi');

        $response->assertOk();
        $response->assertSee($pairA['invoice']->number);
        $response->assertDontSee($pairB['invoice']->number);
    }

    public function test_index_filters_by_invoice_date_range(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $editPayload = ['discount_percent' => 0, 'tax_percent' => 10, 'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]], 'spareparts' => []];
        $old = $this->makeGapPair($branch, $customer, 100000, 0, '2025-01-01', $editPayload);
        $recent = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), $editPayload);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?date_from=' . now()->subDay()->toDateString());

        $response->assertOk();
        $response->assertSee($recent['invoice']->number);
        $response->assertDontSee($old['invoice']->number);
    }

    public function test_index_gap_status_default_ada_selisih_excludes_exact_match(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $exact = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString());
        $gt = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap');

        $response->assertOk();
        $response->assertSee($gt['invoice']->number);
        $response->assertDontSee($exact['invoice']->number);
    }

    public function test_index_gap_status_sesuai_shows_only_exact_matches(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $exact = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString());
        $gt = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?gap_status=sesuai');

        $response->assertOk();
        $response->assertSee($exact['invoice']->number);
        $response->assertDontSee($gt['invoice']->number);
    }

    public function test_index_gap_status_invoice_gt_pkb(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $gt = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $lt = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 10, 'tax_percent' => 0,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?gap_status=invoice_gt_pkb');

        $response->assertOk();
        $response->assertSee($gt['invoice']->number);
        $response->assertDontSee($lt['invoice']->number);
    }

    public function test_index_gap_status_invoice_lt_pkb(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $gt = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $lt = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 10, 'tax_percent' => 0,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?gap_status=invoice_lt_pkb');

        $response->assertOk();
        $response->assertSee($lt['invoice']->number);
        $response->assertDontSee($gt['invoice']->number);
    }

    public function test_index_gap_status_semua_shows_all(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $exact = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString());
        $gt = $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?gap_status=semua');

        $response->assertOk();
        $response->assertSee($exact['invoice']->number);
        $response->assertSee($gt['invoice']->number);
    }

    public function test_index_invalid_gap_status_value_falls_back_to_ada_selisih(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?gap_status=bogus');

        $response->assertOk();
        $response->assertViewHas('gapStatus', 'ada_selisih');
    }

    public function test_index_computes_summary_cards_correctly(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        // pkb_total=100000, grand_total=110000 (tax 10%, no discount) -> varian +10000
        $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?gap_status=semua');

        $response->assertOk();
        $response->assertViewHas('summary', function ($summary) {
            return (int) $summary->total_transaksi === 1
                && (float) $summary->total_nilai_pkb === 100000.0
                && (float) $summary->total_nilai_invoice === 110000.0
                && (float) $summary->total_varian_netto === 10000.0;
        });
    }

    public function test_index_defaults_to_rekap_mode_and_does_not_eager_load_work_order_lines(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap');

        $response->assertOk();
        $response->assertViewHas('mode', 'rekap');
        $response->assertViewHas('invoices', function ($invoices) {
            $invoice = $invoices->first();
            return $invoice->relationLoaded('workOrder') === false || $invoice->workOrder->relationLoaded('serviceLines') === false;
        });
    }

    public function test_index_invalid_mode_value_falls_back_to_rekap(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeGapPair($branch, $customer, 100000, 0, now()->toDateString(), [
            'discount_percent' => 0, 'tax_percent' => 10,
            'services' => [['description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 100000]],
            'spareparts' => [],
        ]);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?mode=bogus');

        $response->assertOk();
        $response->assertViewHas('mode', 'rekap');
    }

    public function test_index_detail_mode_categorizes_line_comparisons_correctly(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        // PKB: 1 service (Ganti Oli, 100000) + 1 sparepart (Oli Mesin, 60000) = pkb_total 160000.
        $pair = $this->makeGapPair($branch, $customer, 100000, 60000, now()->toDateString(), null, false);
        $serviceDetail = $pair['invoice']->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
        $sparepartDetail = $pair['invoice']->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);

        $viewer = User::factory()->create();
        $editor = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice_pkb_gap.view');
        $this->grantBranchPermission($editor, $branch, 'invoice.edit');

        // Edit: service line price changed (100000 -> 120000, same PKB line = "changed"),
        // sparepart line dropped entirely ("removed"), new free-form service line added ("added").
        $this->actingAs($editor)->put("/invoices/{$pair['invoice']->id}", [
            'discount_percent' => 0, 'tax_percent' => 0,
            'services' => [
                [
                    'work_order_service_line_id' => $serviceDetail->work_order_service_line_id,
                    'description' => 'Ganti Oli',
                    'qty' => 1,
                    'unit_price' => 120000,
                ],
                [
                    'description' => 'Jasa Tambahan',
                    'qty' => 1,
                    'unit_price' => 25000,
                ],
            ],
            'spareparts' => [],
        ]);

        $response = $this->actingAs($viewer)->get('/reports/invoice-pkb-gap?mode=detail&gap_status=semua');

        $response->assertOk();
        $response->assertViewHas('invoices', function ($invoices) {
            $lines = $invoices->first()->comparisonLines;
            $changed = collect($lines)->firstWhere('item_name', 'Ganti Oli');
            $removed = collect($lines)->firstWhere('item_name', 'Oli Mesin');
            $added = collect($lines)->firstWhere('item_name', 'Jasa Tambahan');

            return $changed['category'] === 'changed'
                && (float) $changed['pkb_price'] === 100000.0
                && (float) $changed['invoice_price'] === 120000.0
                && $removed['category'] === 'removed'
                && $removed['invoice_qty'] === null
                && $added['category'] === 'added'
                && $added['pkb_qty'] === null;
        });
    }
}
