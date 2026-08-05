<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
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
use App\Support\InvoiceDetailItemType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceModelTest extends TestCase
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

    // Mirrors InvoiceControllerTest::makeWorkOrder()/makeInvoice() — duplicated rather than
    // shared, matching this codebase's existing convention of each test file keeping its own
    // local scenario builder (see the comment atop InvoiceControllerTest::makeWorkOrder()).
    protected function makeInvoice(Branch $branch): Invoice
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::create(['name' => "Mobil {$branch->code}"]);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => "B 1234 {$branch->code}",
        ]);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $catalog = ServiceCatalog::create(['code' => "SVC-01-{$branch->code}", 'name' => 'Ganti Oli', 'default_price' => 50000]);
        $sparepart = Sparepart::create(['code' => "OLI-01-{$branch->code}", 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => 10]);

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
                ['service_catalog_id' => $catalog->id, 'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 50000],
            ],
            'spareparts' => [
                ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 2, 'unit_price' => 60000],
            ],
        ]);
        $workOrder = WorkOrder::latest('id')->first();
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/confirm");
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        return (new InvoiceService())->createFromWorkOrder($workOrder->fresh());
    }

    public function test_invoice_detail_rejects_sparepart_row_without_sparepart_branch_id(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $sparepartDetail = $invoice->details->firstWhere('item_type', InvoiceDetailItemType::SPAREPART);

        $this->expectException(QueryException::class);
        InvoiceDetail::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceDetailItemType::SPAREPART,
            'work_order_service_line_id' => null,
            'work_order_sparepart_line_id' => $sparepartDetail->work_order_sparepart_line_id,
            'sparepart_branch_id' => null,
            'description' => 'Sparepart tanpa cabang',
            'qty' => 1,
            'unit_price' => 10000,
            'line_total' => 10000,
            'sort_order' => 99,
        ]);
    }

    public function test_invoice_detail_allows_free_form_line_traced_to_neither_service_nor_sparepart_line(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $sparepartBranchId = $invoice->details->firstWhere('item_type', InvoiceDetailItemType::SPAREPART)->sparepart_branch_id;

        $detail = InvoiceDetail::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceDetailItemType::SPAREPART,
            'work_order_service_line_id' => null,
            'work_order_sparepart_line_id' => null,
            'sparepart_branch_id' => $sparepartBranchId,
            'description' => 'Sparepart tambahan',
            'qty' => 1,
            'unit_price' => 10000,
            'line_total' => 10000,
            'sort_order' => 99,
        ]);

        $this->assertNotNull($detail->id);
    }

    public function test_invoice_detail_rejects_row_tracing_to_both_service_and_sparepart_line(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);
        $serviceDetail = $invoice->details->firstWhere('item_type', InvoiceDetailItemType::SERVICE);
        $sparepartDetail = $invoice->details->firstWhere('item_type', InvoiceDetailItemType::SPAREPART);

        $this->expectException(QueryException::class);
        InvoiceDetail::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceDetailItemType::SERVICE,
            'work_order_service_line_id' => $serviceDetail->work_order_service_line_id,
            'work_order_sparepart_line_id' => $sparepartDetail->work_order_sparepart_line_id,
            'sparepart_branch_id' => null,
            'description' => 'Baris tidak valid',
            'qty' => 1,
            'unit_price' => 10000,
            'line_total' => 10000,
            'sort_order' => 99,
        ]);
    }

    public function test_post_invoice_deducts_stock_for_free_form_sparepart_line_not_traced_to_pkb(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);

        $extraSparepart = Sparepart::create(['code' => 'FLT-01', 'name' => 'Filter Udara']);
        $extraSparepartBranch = SparepartBranch::create(['sparepart_id' => $extraSparepart->id, 'branch_id' => $branch->id, 'selling_price' => 45000]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $extraSparepartBranch->id)->update(['on_hand_qty' => 5]);

        InvoiceDetail::create([
            'invoice_id' => $invoice->id,
            'item_type' => InvoiceDetailItemType::SPAREPART,
            'work_order_service_line_id' => null,
            'work_order_sparepart_line_id' => null,
            'sparepart_branch_id' => $extraSparepartBranch->id,
            'description' => 'Filter Udara',
            'qty' => 1,
            'unit_price' => 45000,
            'line_total' => 45000,
            'sort_order' => 99,
        ]);
        $invoice->update([
            'subtotal_sparepart' => (float) $invoice->subtotal_sparepart + 45000,
            'grand_total' => (float) $invoice->grand_total + 45000,
        ]);

        (new InvoiceService())->postInvoice($invoice->fresh());

        $stockAfter = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $extraSparepartBranch->id)->first();
        $this->assertSame(4.0, (float) $stockAfter->on_hand_qty);
        $this->assertDatabaseHas('inventory_movements', [
            'sparepart_branch_id' => $extraSparepartBranch->id,
            'movement_type' => 'usage_out',
        ]);
    }
}
