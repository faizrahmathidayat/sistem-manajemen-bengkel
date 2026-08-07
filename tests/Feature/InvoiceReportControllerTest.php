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
use App\Services\InvoiceService;
use App\Services\UserBranchService;
use App\Support\InvoiceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoiceReportControllerTest extends TestCase
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

    protected function makeInvoice(Branch $branch, Customer $customer, float $serviceAmount, float $sparepartAmount, string $invoiceDate, bool $post = true): Invoice
    {
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

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.confirm');
        $this->grantBranchPermission($user, $branch, 'pkb.complete');

        $this->actingAs($user)->post('/work-orders', [
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
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/confirm");
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        $invoice = (new InvoiceService())->createFromWorkOrder($workOrder->fresh());
        if ($post) {
            $invoice = (new InvoiceService())->postInvoice($invoice);
        }
        $invoice->update(['invoice_date' => $invoiceDate]);

        return $invoice->fresh();
    }

    public function test_index_shows_no_access_view_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/reports/invoices');

        $response->assertOk();
        $response->assertSee('belum memiliki akses', false);
    }

    public function test_index_lists_invoices_for_permitted_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoice = $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices');

        $response->assertOk();
        $response->assertSee($invoice->number);
    }

    public function test_index_is_scoped_to_permitted_branches_only(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Dewi Lestari', 'stnk_name' => 'Dewi Lestari']);
        $invoiceA = $this->makeInvoice($branchA, $customerA, 100000, 0, now()->toDateString());
        $invoiceB = $this->makeInvoice($branchB, $customerB, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branchA, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices');

        $response->assertOk();
        $response->assertSee($invoiceA->number);
        $response->assertDontSee($invoiceB->number);
    }

    public function test_index_filters_by_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Dewi Lestari', 'stnk_name' => 'Dewi Lestari']);
        $invoiceA = $this->makeInvoice($branchA, $customerA, 100000, 0, now()->toDateString());
        $invoiceB = $this->makeInvoice($branchB, $customerB, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branchA, 'report.invoice.view');
        $this->grantBranchPermission($viewer, $branchB, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get("/reports/invoices?branch_ids[]={$branchA->id}");

        $response->assertOk();
        $response->assertSee($invoiceA->number);
        $response->assertDontSee($invoiceB->number);
    }

    public function test_index_filters_by_status(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $draft = $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString(), false);
        $posted = $this->makeInvoice($branch, $customer, 50000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices?status=' . InvoiceStatus::POSTED);

        $response->assertOk();
        $response->assertSee($posted->number);
        $response->assertDontSee($draft->number);
    }

    public function test_index_filters_by_search_matching_invoice_number(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $invoiceA = $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString());
        $invoiceB = $this->makeInvoice($branch, $customer, 50000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices?search=' . urlencode($invoiceA->number));

        $response->assertOk();
        $response->assertSee($invoiceA->number);
        $response->assertDontSee($invoiceB->number);
    }

    public function test_index_filters_by_search_matching_customer_name(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Dewi Lestari', 'stnk_name' => 'Dewi Lestari']);
        $invoiceA = $this->makeInvoice($branch, $customerA, 100000, 0, now()->toDateString());
        $invoiceB = $this->makeInvoice($branch, $customerB, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices?search=Budi');

        $response->assertOk();
        $response->assertSee($invoiceA->number);
        $response->assertDontSee($invoiceB->number);
    }

    public function test_index_filters_by_invoice_date_range(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $old = $this->makeInvoice($branch, $customer, 100000, 0, '2025-01-01');
        $recent = $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices?date_from=' . now()->subDay()->toDateString());

        $response->assertOk();
        $response->assertSee($recent->number);
        $response->assertDontSee($old->number);
    }

    public function test_index_computes_summary_cards_across_all_statuses_by_default(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeInvoice($branch, $customer, 100000, 60000, now()->toDateString());
        $this->makeInvoice($branch, $customer, 50000, 0, now()->toDateString(), false);
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices');

        $response->assertOk();
        $response->assertViewHas('summary', function ($summary) {
            return (int) $summary->total_invoice === 2
                && (float) $summary->total_nominal === 210000.0
                && (float) $summary->total_paid === 0.0
                && (float) $summary->total_remaining === 210000.0;
        });
    }

    public function test_index_shows_stored_money_columns_per_row(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeInvoice($branch, $customer, 100000, 60000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices');

        $response->assertOk();
        $response->assertSee('100.000');
        $response->assertSee('60.000');
        $response->assertSee('160.000');
    }

    public function test_index_defaults_to_rekap_mode_and_does_not_eager_load_details(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeInvoice($branch, $customer, 100000, 60000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices');

        $response->assertOk();
        $response->assertViewHas('mode', 'rekap');
        $response->assertViewHas('invoices', function ($invoices) {
            return $invoices->first()->relationLoaded('details') === false;
        });
    }

    public function test_index_detail_mode_eager_loads_details_with_snapshot_fields(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeInvoice($branch, $customer, 100000, 60000, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices?mode=detail');

        $response->assertOk();
        $response->assertViewHas('mode', 'detail');
        $response->assertViewHas('invoices', function ($invoices) {
            $invoice = $invoices->first();
            $serviceDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SERVICE);
            $sparepartDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);

            return $invoice->relationLoaded('details') === true
                && $serviceDetail->description === 'Ganti Oli'
                && (float) $serviceDetail->line_total === 100000.0
                && $sparepartDetail->description === 'Oli Mesin'
                && (float) $sparepartDetail->line_total === 60000.0;
        });
    }

    public function test_index_invalid_mode_value_falls_back_to_rekap(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $this->makeInvoice($branch, $customer, 100000, 0, now()->toDateString());
        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.invoice.view');

        $response = $this->actingAs($viewer)->get('/reports/invoices?mode=bogus');

        $response->assertOk();
        $response->assertViewHas('mode', 'rekap');
    }
}
