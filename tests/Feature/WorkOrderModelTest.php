<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\ServiceCatalog;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use App\Models\WorkOrder;
use App\Models\WorkOrderServiceLine;
use App\Models\WorkOrderSparepartLine;
use App\Support\WorkOrderStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderModelTest extends TestCase
{
    use RefreshDatabase;

    protected function makeVehicle(): Vehicle
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso']);
        $category = VehicleCategory::create(['name' => 'Mobil']);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Avanza']);

        return Vehicle::create([
            'customer_id' => $customer->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'type_id' => $type->id,
            'plate_number' => 'B 1234 XYZ',
        ]);
    }

    public function test_work_order_can_be_created_with_fillable_fields_and_defaults_to_draft(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $vehicle = $this->makeVehicle();
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);

        $workOrder = WorkOrder::create([
            'number' => 'PKB/JKT/202608/00001',
            'branch_id' => $branch->id,
            'customer_id' => $vehicle->customer_id,
            'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id,
            'work_order_date' => now()->format('Y-m-d'),
        ]);

        $this->assertSame(WorkOrderStatus::DRAFT, $workOrder->status);
        $this->assertSame($user->id, $workOrder->created_by);
    }

    public function test_work_order_number_is_unique(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $vehicle = $this->makeVehicle();
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $attrs = [
            'number' => 'PKB/JKT/202608/00001',
            'branch_id' => $branch->id,
            'customer_id' => $vehicle->customer_id,
            'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id,
            'work_order_date' => now()->format('Y-m-d'),
        ];
        WorkOrder::create($attrs);

        $this->expectException(QueryException::class);
        WorkOrder::create($attrs);
    }

    public function test_service_line_belongs_to_work_order_and_optional_catalog(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $vehicle = $this->makeVehicle();
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $workOrder = WorkOrder::create([
            'number' => 'PKB/JKT/202608/00001', 'branch_id' => $branch->id,
            'customer_id' => $vehicle->customer_id, 'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id, 'work_order_date' => now()->format('Y-m-d'),
        ]);
        $catalog = ServiceCatalog::create(['code' => 'SVC-01', 'name' => 'Ganti Oli', 'default_price' => 50000]);

        $line = WorkOrderServiceLine::create([
            'work_order_id' => $workOrder->id,
            'service_catalog_id' => $catalog->id,
            'description' => 'Ganti Oli',
            'qty' => 1,
            'unit_price' => 50000,
            'line_total' => 50000,
        ]);

        $this->assertSame($workOrder->id, $line->workOrder->id);
        $this->assertSame($catalog->id, $line->serviceCatalog->id);
        $this->assertCount(1, $workOrder->serviceLines);
    }

    public function test_service_line_allows_null_catalog_for_free_text_jasa(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $vehicle = $this->makeVehicle();
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $workOrder = WorkOrder::create([
            'number' => 'PKB/JKT/202608/00001', 'branch_id' => $branch->id,
            'customer_id' => $vehicle->customer_id, 'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id, 'work_order_date' => now()->format('Y-m-d'),
        ]);

        $line = WorkOrderServiceLine::create([
            'work_order_id' => $workOrder->id,
            'service_catalog_id' => null,
            'description' => 'Servis manual custom',
            'qty' => 1,
            'unit_price' => 75000,
            'line_total' => 75000,
        ]);

        $this->assertNull($line->service_catalog_id);
        $this->assertNull($line->serviceCatalog);
    }

    public function test_sparepart_line_belongs_to_work_order_and_sparepart_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $vehicle = $this->makeVehicle();
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $workOrder = WorkOrder::create([
            'number' => 'PKB/JKT/202608/00001', 'branch_id' => $branch->id,
            'customer_id' => $vehicle->customer_id, 'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id, 'work_order_date' => now()->format('Y-m-d'),
        ]);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);

        $line = WorkOrderSparepartLine::create([
            'work_order_id' => $workOrder->id,
            'sparepart_branch_id' => $sparepartBranch->id,
            'item_code_snapshot' => 'OLI-01',
            'item_name_snapshot' => 'Oli Mesin',
            'qty' => 2,
            'default_unit_price' => 60000,
            'unit_price' => 60000,
            'line_total' => 120000,
        ]);

        $this->assertSame($workOrder->id, $line->workOrder->id);
        $this->assertSame($sparepartBranch->id, $line->sparepartBranch->id);
        $this->assertCount(1, $workOrder->sparepartLines);
    }

    public function test_deleting_work_order_cascades_to_its_lines(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $vehicle = $this->makeVehicle();
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        $workOrder = WorkOrder::create([
            'number' => 'PKB/JKT/202608/00001', 'branch_id' => $branch->id,
            'customer_id' => $vehicle->customer_id, 'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id, 'work_order_date' => now()->format('Y-m-d'),
        ]);
        $line = WorkOrderServiceLine::create([
            'work_order_id' => $workOrder->id, 'description' => 'Ganti Oli',
            'qty' => 1, 'unit_price' => 50000, 'line_total' => 50000,
        ]);

        $workOrder->delete();

        $this->assertDatabaseMissing('work_order_service_lines', ['id' => $line->id]);
    }
}
