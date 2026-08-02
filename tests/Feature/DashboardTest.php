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
use Tests\TestCase;

class DashboardTest extends TestCase
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

    public function test_stock_overview_sums_on_hand_and_reserved_across_selected_branches(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'sparepart.view');
        $this->grantBranchPermission($user, $branchB, 'sparepart.view');
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $configA = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branchA->id, 'selling_price' => 100000]);
        $configB = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branchB->id, 'selling_price' => 100000]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $configA->id)->update(['on_hand_qty' => 10, 'reserved_qty' => 2]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $configB->id)->update(['on_hand_qty' => 5, 'reserved_qty' => 1]);

        $response = $this->actingAs(User::find($user->id))
            ->getJson('/dashboard?branch_ids[]=' . $branchA->id . '&branch_ids[]=' . $branchB->id);

        $response->assertOk();
        $response->assertJson(['stockOverview' => ['onHand' => 15.0, 'reserved' => 3.0, 'available' => 12.0]]);
    }

    public function test_stock_overview_excludes_branches_without_sparepart_view_permission(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'sparepart.view');
        (new UserBranchService())->assign($user, $branchB);
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $configA = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branchA->id, 'selling_price' => 100000]);
        $configB = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branchB->id, 'selling_price' => 100000]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $configA->id)->update(['on_hand_qty' => 10]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $configB->id)->update(['on_hand_qty' => 999]);

        $response = $this->actingAs(User::find($user->id))
            ->getJson('/dashboard?branch_ids[]=' . $branchA->id . '&branch_ids[]=' . $branchB->id);

        $response->assertOk();
        $response->assertJson(['stockOverview' => ['onHand' => 10.0, 'reserved' => 0.0, 'available' => 10.0]]);
    }

    public function test_critical_stock_count_finds_configs_under_minimum(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $low = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $lowConfig = SparepartBranch::create(['sparepart_id' => $low->id, 'branch_id' => $branch->id, 'selling_price' => 100000, 'minimum_stock' => 5]);
        $ok = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $okConfig = SparepartBranch::create(['sparepart_id' => $ok->id, 'branch_id' => $branch->id, 'selling_price' => 50000, 'minimum_stock' => 2]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $lowConfig->id)->update(['on_hand_qty' => 1]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $okConfig->id)->update(['on_hand_qty' => 10]);

        $response = $this->actingAs(User::find($user->id))->getJson('/dashboard?branch_ids[]=' . $branch->id);

        $response->assertOk();
        $response->assertJson(['criticalStockCount' => 1]);
    }

    public function test_dashboard_defaults_filter_to_users_default_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $branchService = new UserBranchService();
        $branchService->assign($user, $branchA, true);
        $branchService->assign($user, $branchB, false);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Cabang Jakarta', false);
    }

    public function test_dashboard_ignores_branch_ids_the_user_is_not_assigned_to(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branchA, true);

        $response = $this->actingAs(User::find($user->id))
            ->getJson('/dashboard?branch_ids[]=' . $branchA->id . '&branch_ids[]=' . $branchB->id);

        $response->assertOk();
        $response->assertJson(['selectedBranchIds' => [$branchA->id]]);
    }

    public function test_dashboard_json_response_shape(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch, true);

        $response = $this->actingAs(User::find($user->id))->getJson('/dashboard');

        $response->assertOk();
        $response->assertJsonStructure(['selectedBranchIds']);
    }

    public function test_dashboard_shows_empty_state_for_user_with_zero_branches(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('belum ditugaskan ke cabang manapun', false);
    }

    public function test_dashboard_includes_dummy_pkb_status_and_receivables(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch, true);

        $response = $this->actingAs(User::find($user->id))->getJson('/dashboard');

        $response->assertOk();
        $response->assertJsonStructure(['pkbStatus' => ['open', 'shortage', 'completed'], 'receivables' => ['revenue', 'unpaid']]);
    }
}
