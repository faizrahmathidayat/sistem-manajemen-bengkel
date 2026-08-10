<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Rack;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\UserPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterRackIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function userWithPermissions(array $codes): User
    {
        $user = User::factory()->create();

        foreach ($codes as $code) {
            [$resource, $action] = explode('.', $code, 2);
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['resource' => $resource, 'action' => $action, 'description' => $code]
            );
            UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);
        }

        return User::find($user->id);
    }

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

    public function test_full_lifecycle_create_rack_assign_to_sparepart_and_display_in_index(): void
    {
        $rackUser = $this->userWithPermissions(['rack.view', 'rack.create', 'rack.edit']);
        $createRackResponse = $this->actingAs($rackUser)->post('/racks', ['code' => 'A1', 'is_active' => '1']);
        $createRackResponse->assertRedirect('/racks');
        $rack = Rack::where('code', 'A1')->firstOrFail();

        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartUser = User::factory()->create();
        $this->grantBranchPermission($sparepartUser, $branch, 'sparepart.view');
        $this->grantBranchPermission($sparepartUser, $branch, 'sparepart.create');
        $this->actingAs($sparepartUser)->get('/sparepart-branches');

        $storeResponse = $this->post('/sparepart-branches', [
            'branch_id' => $branch->id,
            'code' => 'BAN-01',
            'name' => 'Ban Depan',
            'rack_id' => $rack->id,
            'selling_price' => 150000,
            'minimum_stock' => 2,
        ]);
        $storeResponse->assertRedirect('/sparepart-branches');

        $indexResponse = $this->actingAs($sparepartUser)->get('/sparepart-branches?branch_id=' . $branch->id);
        $indexResponse->assertOk();
        $indexResponse->assertSee('A1');

        $deactivateRackResponse = $this->actingAs($rackUser)->put("/racks/{$rack->id}", ['code' => 'A1', 'is_active' => '0']);
        $deactivateRackResponse->assertRedirect('/racks');

        $createFormResponse = $this->actingAs($sparepartUser)->get('/sparepart-branches/create');
        $createFormResponse->assertOk();
        $createFormResponse->assertDontSee('>A1<', false);

        $sparepartBranch = SparepartBranch::whereHas('sparepart', fn ($q) => $q->where('code', 'BAN-01'))->first();
        $sparepartBranch->refresh();
        $this->assertSame($rack->id, $sparepartBranch->rack_id, 'Deactivating a rack must not clear rack_id on an already-linked sparepart branch.');
    }

    public function test_deleting_a_rack_nulls_out_rack_id_on_linked_sparepart_branches(): void
    {
        $rack = Rack::create(['code' => 'A1']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $sparepartBranch = SparepartBranch::create([
            'sparepart_id' => $sparepart->id,
            'branch_id' => $branch->id,
            'rack_id' => $rack->id,
            'selling_price' => 100000,
        ]);

        $rack->delete();

        $sparepartBranch->refresh();
        $this->assertNull($sparepartBranch->rack_id);
        $this->assertDatabaseHas('sparepart_branches', ['id' => $sparepartBranch->id]);
    }
}
