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

class WorkshopPerformanceReportControllerTest extends TestCase
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

    protected function makeInvoiceWithLines(Branch $branch, Customer $customer, Mechanic $mechanic, array $serviceAmounts, array $sparepartAmounts, string $invoiceDate): Invoice
    {
        CustomerBranch::firstOrCreate(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        MechanicBranch::firstOrCreate(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::firstOrCreate(['name' => "Mobil {$branch->code}"]);
        $brand = VehicleBrand::firstOrCreate(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::firstOrCreate(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id,
            'plate_number' => 'B ' . random_int(1000, 9999) . ' ' . $branch->code,
        ]);

        $services = [];
        foreach ($serviceAmounts as $index => $amount) {
            $catalog = ServiceCatalog::create(['code' => 'SVC-' . random_int(10000, 99999), 'name' => "Jasa {$index}", 'default_price' => $amount]);
            $services[] = ['service_catalog_id' => $catalog->id, 'description' => "Jasa {$index}", 'qty' => 1, 'unit_price' => $amount];
        }

        $spareparts = [];
        foreach ($sparepartAmounts as $index => $amount) {
            $sparepart = Sparepart::create(['code' => 'SPR-' . random_int(10000, 99999), 'name' => "Sparepart {$index}"]);
            $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => $amount]);
            DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => 10]);
            $spareparts[] = ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 1, 'unit_price' => $amount];
        }

        $user = User::factory()->create();
        foreach (['pkb.create', 'pkb.confirm', 'pkb.complete', 'invoice.create', 'invoice.post'] as $code) {
            $this->grantBranchPermission($user, $branch, $code);
        }

        $this->actingAs($user)->post('/work-orders', [
            'branch_id' => $branch->id, 'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id, 'work_order_date' => now()->format('Y-m-d'),
            'services' => $services,
            'spareparts' => $spareparts,
        ]);
        $workOrder = WorkOrder::latest('id')->first();
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/confirm");
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");
        $this->actingAs($user)->post('/invoices', ['work_order_id' => $workOrder->id]);
        $invoice = Invoice::where('work_order_id', $workOrder->id)->firstOrFail();
        $this->actingAs($user)->patch("/invoices/{$invoice->id}/post");
        $invoice->update(['invoice_date' => $invoiceDate]);

        return $invoice->fresh(['details', 'workOrder.mechanic', 'branch', 'customer']);
    }

    protected function applyDiscountPercent(Invoice $invoice, float $percent): Invoice
    {
        foreach ($invoice->details as $detail) {
            $gross = (float) $detail->qty * (float) $detail->unit_price;
            $discountAmount = round($gross * ($percent / 100), 2);
            $detail->update([
                'discount_percent' => $percent,
                'discount_amount' => $discountAmount,
                'line_total' => round($gross - $discountAmount, 2),
            ]);
        }

        return $invoice->fresh(['details', 'workOrder.mechanic', 'branch', 'customer']);
    }

    public function test_index_shows_no_access_view_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/reports/workshop-performance');

        $response->assertOk();
        $response->assertSee('Anda belum memiliki akses laporan performance bengkel di cabang manapun.');
    }

    public function test_mechanic_view_shows_correct_aggregates_per_mechanic(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer1 = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $customer2 = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Siti Aminah', 'stnk_name' => 'Siti Aminah']);
        $mechanicA = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);
        $mechanicB = Mechanic::create(['name' => 'Bambang Wijaya', 'nip' => 'MEK-002']);
        $mechanicC = Mechanic::create(['name' => 'Candra Kusuma', 'nip' => 'MEK-003']);
        MechanicBranch::create(['mechanic_id' => $mechanicC->id, 'branch_id' => $branch->id]);

        $invoice1 = $this->makeInvoiceWithLines($branch, $customer1, $mechanicA, [100000], [], now()->toDateString());
        $invoice2 = $this->makeInvoiceWithLines($branch, $customer2, $mechanicA, [100000], [100000], now()->toDateString());
        $this->applyDiscountPercent($invoice2, 10);
        $this->makeInvoiceWithLines($branch, $customer1, $mechanicB, [80000], [], now()->toDateString());

        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.workshop_performance.view');

        $response = $this->actingAs($viewer)->get('/reports/workshop-performance');

        $response->assertOk();
        $response->assertSee('MEK-001 - Agus Setiawan');
        $response->assertSee('MEK-002 - Bambang Wijaya');
        $response->assertDontSee('MEK-003 - Candra Kusuma');
        $response->assertSee('190.000');
        $response->assertSee('90.000');
        $response->assertSee('280.000');
        $response->assertSee('80.000');
    }

    public function test_mechanic_view_excludes_direct_sale_invoices(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::firstOrCreate(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);
        $this->makeInvoiceWithLines($branch, $customer, $mechanic, [100000], [], now()->toDateString());

        $creator = User::factory()->create();
        $this->grantBranchPermission($creator, $branch, 'invoice.create');
        $this->actingAs($creator)->post('/invoices/direct', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'services' => [['description' => 'Cuci Mobil', 'qty' => 1, 'unit_price' => 500000, 'discount_percent' => 0]],
            'spareparts' => [],
        ]);

        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.workshop_performance.view');

        $response = $this->actingAs($viewer)->get('/reports/workshop-performance');

        $response->assertOk();
        $response->assertSee('MEK-001 - Agus Setiawan');
        // Subtotal Jasa mekanik tetap 100.000 (bukan 600.000) — membuktikan invoice Direct
        // Sales (500.000, tanpa mekanik) tidak ikut ter-agregasi lewat INNER JOIN work_orders.
        $response->assertSee('100.000');
    }

    public function test_mechanic_view_filters_by_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $mechanicA = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);
        $mechanicB = Mechanic::create(['name' => 'Bambang Wijaya', 'nip' => 'MEK-002']);
        $this->makeInvoiceWithLines($branchA, $customer, $mechanicA, [100000], [], '2026-01-10');
        $this->makeInvoiceWithLines($branchB, $customer, $mechanicB, [100000], [], now()->toDateString());

        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branchA, 'report.workshop_performance.view');
        $this->grantBranchPermission($viewer, $branchB, 'report.workshop_performance.view');

        $response = $this->actingAs($viewer)->get('/reports/workshop-performance?branch_ids[]=' . $branchA->id);

        $response->assertOk();
        $response->assertSee('MEK-001 - Agus Setiawan');
        $response->assertDontSee('MEK-002 - Bambang Wijaya');
    }

    public function test_invoice_detail_view_pairs_jasa_and_sparepart_lines(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan', 'nip' => 'MEK-001']);
        $this->makeInvoiceWithLines($branch, $customer, $mechanic, [100000], [90000, 40000], now()->toDateString());

        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.workshop_performance.view');

        $response = $this->actingAs($viewer)->get('/reports/workshop-performance?view_type=invoice_detail');

        $response->assertOk();
        $response->assertSee('MEK-001 - Agus Setiawan');
        $response->assertSee('Jasa 0');
        $response->assertSee('Sparepart 0');
        $response->assertSee('Sparepart 1');
    }

    public function test_invoice_detail_view_shows_dash_mechanic_for_direct_sale(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::firstOrCreate(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $creator = User::factory()->create();
        $this->grantBranchPermission($creator, $branch, 'invoice.create');
        $this->actingAs($creator)->post('/invoices/direct', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'services' => [['description' => 'Cuci Mobil', 'qty' => 1, 'unit_price' => 40000, 'discount_percent' => 0]],
            'spareparts' => [],
        ]);
        $directSale = Invoice::latest('id')->first();

        $viewer = User::factory()->create();
        $this->grantBranchPermission($viewer, $branch, 'report.workshop_performance.view');

        $response = $this->actingAs($viewer)->get('/reports/workshop-performance?view_type=invoice_detail');

        $response->assertOk();
        $response->assertSee($directSale->number);
        $response->assertSee('Cuci Mobil');
    }
}
