<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
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
use Tests\Concerns\ExtractsPdfText;
use Tests\TestCase;

class VehicleYearPkbTest extends TestCase
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

    protected function makeWorkOrderWithYear(Branch $branch, ?int $year): WorkOrder
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::create(['name' => "Motor {$branch->code}"]);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Honda']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Beat']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => "B 001 {$branch->code}", 'year' => $year,
        ]);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $catalog = ServiceCatalog::create(['code' => "SVC-{$branch->code}", 'name' => 'Ganti Oli', 'default_price' => 50000]);
        $sparepart = Sparepart::create(['code' => "OLI-{$branch->code}", 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'pkb.print');

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
                ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 1, 'unit_price' => 60000],
            ],
        ]);

        return WorkOrder::latest('id')->first();
    }

    public function test_vehicle_year_flows_through_lookup_show_and_print(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $workOrder = $this->makeWorkOrderWithYear($branch, 2020);

        $lookupResponse = $this->actingAs(User::first())->getJson("/work-orders/lookup/vehicles/{$workOrder->customer_id}");
        $lookupResponse->assertOk();
        $lookupResponse->assertJsonFragment(['year' => 2020]);

        $showResponse = $this->actingAs(User::first())->get("/work-orders/{$workOrder->id}");
        $showResponse->assertOk();
        $showResponse->assertSee('Tahun Kendaraan');
        $showResponse->assertSee('2020');

        $printResponse = $this->actingAs(User::first())->get("/work-orders/{$workOrder->id}/print");
        $printContent = $this->extractPdfText($printResponse->getContent());
        $this->assertStringContainsString('2020', $printContent);
    }

    public function test_vehicle_without_year_degrades_gracefully_everywhere(): void
    {
        $branch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $workOrder = $this->makeWorkOrderWithYear($branch, null);

        $showResponse = $this->actingAs(User::first())->get("/work-orders/{$workOrder->id}");
        $showResponse->assertOk();
        $showResponse->assertSee('Tahun Kendaraan');

        $printResponse = $this->actingAs(User::first())->get("/work-orders/{$workOrder->id}/print");
        $printResponse->assertOk();
    }
}
