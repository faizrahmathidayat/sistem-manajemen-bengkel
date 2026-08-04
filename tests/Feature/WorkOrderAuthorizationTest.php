<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use App\Models\WorkOrder;
use App\Services\UserBranchService;
use App\Support\WorkOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkOrderAuthorizationTest extends TestCase
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

    protected function makeWorkOrder(Branch $branch, array $overrides = []): WorkOrder
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $category = VehicleCategory::create(['name' => 'Mobil']);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 XYZ',
        ]);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);

        return WorkOrder::create(array_merge([
            'number' => 'PKB/JKT/202608/00001',
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id,
            'work_order_date' => now()->format('Y-m-d'),
        ], $overrides));
    }

    public function test_policy_grants_view_and_update_for_the_correct_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $this->grantBranchPermission($user, $branch, 'pkb.edit');
        $workOrder = $this->makeWorkOrder($branch);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('view', $workOrder));
        $this->assertTrue($reloaded->can('update', $workOrder));
    }

    public function test_policy_denies_access_for_a_user_with_permission_in_a_different_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'pkb.view');
        $workOrder = $this->makeWorkOrder($branchB);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('view', $workOrder));
    }

    public function test_policy_update_requires_edit_code_not_just_view(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.view');
        $workOrder = $this->makeWorkOrder($branch);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('view', $workOrder));
        $this->assertFalse($reloaded->can('update', $workOrder));
    }

    public function test_policy_denies_update_and_cancel_for_a_cancelled_work_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.edit');
        $this->grantBranchPermission($user, $branch, 'pkb.cancel');
        $workOrder = $this->makeWorkOrder($branch, ['status' => WorkOrderStatus::CANCELLED]);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('update', $workOrder));
        $this->assertFalse($reloaded->can('cancel', $workOrder));
    }

    public function test_policy_grants_cancel_for_a_draft_work_order_with_cancel_code(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.cancel');
        $workOrder = $this->makeWorkOrder($branch);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('cancel', $workOrder));
    }

    public function test_policy_grants_cancel_for_an_open_work_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.cancel');
        $workOrder = $this->makeWorkOrder($branch, ['status' => WorkOrderStatus::OPEN]);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('cancel', $workOrder));
    }

    public function test_policy_grants_cancel_for_a_shortage_work_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.cancel');
        $workOrder = $this->makeWorkOrder($branch, ['status' => WorkOrderStatus::SHORTAGE]);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('cancel', $workOrder));
    }

    public function test_policy_grants_confirm_for_a_draft_work_order_with_confirm_code(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.confirm');
        $workOrder = $this->makeWorkOrder($branch);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('confirm', $workOrder));
    }

    public function test_policy_denies_confirm_for_a_non_draft_work_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.confirm');
        $workOrder = $this->makeWorkOrder($branch, ['status' => WorkOrderStatus::OPEN]);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('confirm', $workOrder));
    }

    public function test_policy_grants_override_shortage_for_a_not_yet_overridden_shortage_work_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.override_stock_shortage');
        $workOrder = $this->makeWorkOrder($branch, ['status' => WorkOrderStatus::SHORTAGE]);

        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('overrideShortage', $workOrder));
    }

    public function test_policy_denies_override_shortage_when_already_overridden(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.override_stock_shortage');
        $workOrder = $this->makeWorkOrder($branch, [
            'status' => WorkOrderStatus::SHORTAGE,
            'shortage_overridden_at' => now(),
        ]);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('overrideShortage', $workOrder));
    }

    public function test_policy_denies_override_shortage_for_a_non_shortage_work_order(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.override_stock_shortage');
        $workOrder = $this->makeWorkOrder($branch, ['status' => WorkOrderStatus::OPEN]);

        $reloaded = User::find($user->id);

        $this->assertFalse($reloaded->can('overrideShortage', $workOrder));
    }
}
