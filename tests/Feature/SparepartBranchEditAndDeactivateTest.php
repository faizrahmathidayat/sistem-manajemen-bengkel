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

class SparepartBranchEditAndDeactivateTest extends TestCase
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

    protected function makeSparepartBranch(Branch $branch): SparepartBranch
    {
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);

        return SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);
    }

    public function test_edit_shows_branch_config_fields_for_authorized_user(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.edit');

        $response = $this->actingAs(User::find($user->id))->get("/sparepart-branches/{$sparepartBranch->id}/edit");

        $response->assertOk();
        $response->assertSee('Ban Depan');
    }

    public function test_edit_is_forbidden_for_user_with_permission_in_a_different_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $otherBranch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $otherBranch, 'sparepart.edit');

        $response = $this->actingAs(User::find($user->id))->get("/sparepart-branches/{$sparepartBranch->id}/edit");

        $response->assertForbidden();
    }

    public function test_update_saves_rack_price_minimum_stock_without_touching_is_active(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.edit');

        $response = $this->actingAs(User::find($user->id))->put("/sparepart-branches/{$sparepartBranch->id}", [
            'rack_number' => 'C3',
            'selling_price' => 175000,
            'minimum_stock' => 4,
        ]);

        $response->assertRedirect('/sparepart-branches');
        $this->assertDatabaseHas('sparepart_branches', [
            'id' => $sparepartBranch->id,
            'rack_number' => 'C3',
            'selling_price' => 175000,
            'is_active' => true,
        ]);
    }

    public function test_deactivate_sets_is_active_false_and_requires_sparepart_delete_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.delete');

        $response = $this->actingAs(User::find($user->id))->patch("/sparepart-branches/{$sparepartBranch->id}/deactivate");

        $response->assertRedirect('/sparepart-branches');
        $this->assertDatabaseHas('sparepart_branches', ['id' => $sparepartBranch->id, 'is_active' => false]);
    }

    public function test_activate_sets_is_active_true(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $sparepartBranch->update(['is_active' => false]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.delete');

        $response = $this->actingAs(User::find($user->id))->patch("/sparepart-branches/{$sparepartBranch->id}/activate");

        $response->assertRedirect('/sparepart-branches');
        $this->assertDatabaseHas('sparepart_branches', ['id' => $sparepartBranch->id, 'is_active' => true]);
    }

    public function test_deactivate_is_forbidden_with_only_edit_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = $this->makeSparepartBranch($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'sparepart.edit');

        $response = $this->actingAs(User::find($user->id))->patch("/sparepart-branches/{$sparepartBranch->id}/deactivate");

        $response->assertForbidden();
    }
}
