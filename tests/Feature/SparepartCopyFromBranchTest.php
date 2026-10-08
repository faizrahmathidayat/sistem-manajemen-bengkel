<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Rack;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SparepartCopyFromBranchTest extends TestCase
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
        UserBranchPermission::firstOrCreate(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);
    }

    /** @return array{0: User, 1: Branch, 2: Branch} */
    protected function setUpBranches(bool $createOnTarget = true): array
    {
        $user = User::factory()->create();
        $source = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $target = Branch::create(['code' => 'SMG', 'name' => 'Cabang Semarang']);
        $this->grantBranchPermission($user, $source, 'sparepart.view');
        $this->grantBranchPermission($user, $target, 'sparepart.view');
        if ($createOnTarget) {
            $this->grantBranchPermission($user, $target, 'sparepart.create');
        }

        return [User::find($user->id), $source, $target];
    }

    protected function selectBranch(User $user, Branch $branch): void
    {
        $this->actingAs($user)->get('/sparepart-branches?branch_id=' . $branch->id);
    }

    public function test_copy_creates_configs_for_target_with_price_and_min_stock_but_without_rack(): void
    {
        [$user, $source, $target] = $this->setUpBranches();
        $rack = Rack::create(['code' => 'A1']);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        SparepartBranch::create([
            'sparepart_id' => $sparepart->id, 'branch_id' => $source->id, 'rack_id' => $rack->id,
            'selling_price' => 60000, 'minimum_stock' => 5,
        ]);
        $this->selectBranch($user, $target);

        $response = $this->post('/sparepart-branches/copy-from-branch', [
            'branch_id' => $target->id, 'source_branch_id' => $source->id,
        ]);

        $response->assertRedirect('/sparepart-branches');
        $response->assertSessionHas('status', fn ($status) => str_contains($status, '1 sparepart berhasil disalin'));
        $copied = SparepartBranch::where('sparepart_id', $sparepart->id)->where('branch_id', $target->id)->first();
        $this->assertNotNull($copied);
        $this->assertNull($copied->rack_id);
        $this->assertSame(60000, (int) $copied->selling_price);
        $this->assertSame(5, (int) $copied->minimum_stock);
        $this->assertTrue($copied->is_active);
        $this->assertDatabaseHas('sparepart_branch_stocks', ['sparepart_branch_id' => $copied->id, 'on_hand_qty' => 0]);
        $this->assertSame($user->id, (int) $copied->created_by);
    }

    public function test_copy_skips_spareparts_already_configured_on_target_without_overwriting(): void
    {
        [$user, $source, $target] = $this->setUpBranches();
        $existing = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban']);
        $fresh = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli']);
        SparepartBranch::create(['sparepart_id' => $existing->id, 'branch_id' => $source->id, 'selling_price' => 100000]);
        SparepartBranch::create(['sparepart_id' => $fresh->id, 'branch_id' => $source->id, 'selling_price' => 60000]);
        SparepartBranch::create(['sparepart_id' => $existing->id, 'branch_id' => $target->id, 'selling_price' => 111111]);
        $this->selectBranch($user, $target);

        $this->post('/sparepart-branches/copy-from-branch', ['branch_id' => $target->id, 'source_branch_id' => $source->id]);

        $this->assertSame(2, SparepartBranch::where('branch_id', $target->id)->count());
        $this->assertSame(111111, (int) SparepartBranch::where('sparepart_id', $existing->id)->where('branch_id', $target->id)->value('selling_price'));
    }

    public function test_copy_ignores_inactive_source_configs_and_inactive_master_spareparts(): void
    {
        [$user, $source, $target] = $this->setUpBranches();
        $inactiveConfig = Sparepart::create(['code' => 'A-01', 'name' => 'A']);
        $inactiveMaster = Sparepart::create(['code' => 'B-01', 'name' => 'B', 'is_active' => false]);
        $active = Sparepart::create(['code' => 'C-01', 'name' => 'C']);
        SparepartBranch::create(['sparepart_id' => $inactiveConfig->id, 'branch_id' => $source->id, 'selling_price' => 1000, 'is_active' => false]);
        SparepartBranch::create(['sparepart_id' => $inactiveMaster->id, 'branch_id' => $source->id, 'selling_price' => 1000]);
        SparepartBranch::create(['sparepart_id' => $active->id, 'branch_id' => $source->id, 'selling_price' => 1000]);
        $this->selectBranch($user, $target);

        $this->post('/sparepart-branches/copy-from-branch', ['branch_id' => $target->id, 'source_branch_id' => $source->id]);

        $this->assertSame([$active->id], SparepartBranch::where('branch_id', $target->id)->pluck('sparepart_id')->all());
    }

    public function test_copy_forbidden_without_create_permission_on_target(): void
    {
        [$user, $source, $target] = $this->setUpBranches(false);
        $this->selectBranch($user, $target);

        $this->post('/sparepart-branches/copy-from-branch', ['branch_id' => $target->id, 'source_branch_id' => $source->id])
            ->assertForbidden();
        $this->get('/sparepart-branches/copy-from-branch')->assertForbidden();
    }

    public function test_copy_forbidden_without_view_permission_on_source(): void
    {
        $user = User::factory()->create();
        $source = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $target = Branch::create(['code' => 'SMG', 'name' => 'Cabang Semarang']);
        $this->grantBranchPermission($user, $target, 'sparepart.view');
        $this->grantBranchPermission($user, $target, 'sparepart.create');
        $this->selectBranch(User::find($user->id), $target);

        $this->post('/sparepart-branches/copy-from-branch', ['branch_id' => $target->id, 'source_branch_id' => $source->id])
            ->assertForbidden();
    }

    public function test_copy_rejects_same_source_and_target(): void
    {
        [$user, , $target] = $this->setUpBranches();
        $this->selectBranch($user, $target);

        $this->post('/sparepart-branches/copy-from-branch', ['branch_id' => $target->id, 'source_branch_id' => $target->id])
            ->assertSessionHasErrors('source_branch_id');
    }

    public function test_copy_with_nothing_new_reports_so_and_creates_nothing(): void
    {
        [$user, $source, $target] = $this->setUpBranches();
        $this->selectBranch($user, $target);

        $response = $this->post('/sparepart-branches/copy-from-branch', ['branch_id' => $target->id, 'source_branch_id' => $source->id]);

        $response->assertRedirect('/sparepart-branches');
        $response->assertSessionHas('status', fn ($status) => str_contains($status, 'Tidak ada sparepart baru'));
        $this->assertSame(0, SparepartBranch::where('branch_id', $target->id)->count());
    }

    public function test_page_lists_only_other_branches_with_copyable_counts(): void
    {
        [$user, $source, $target] = $this->setUpBranches();
        $a = Sparepart::create(['code' => 'A-01', 'name' => 'A']);
        $b = Sparepart::create(['code' => 'B-01', 'name' => 'B']);
        SparepartBranch::create(['sparepart_id' => $a->id, 'branch_id' => $source->id, 'selling_price' => 1000]);
        SparepartBranch::create(['sparepart_id' => $b->id, 'branch_id' => $source->id, 'selling_price' => 1000]);
        SparepartBranch::create(['sparepart_id' => $b->id, 'branch_id' => $target->id, 'selling_price' => 1000]);
        $this->selectBranch($user, $target);

        $response = $this->get('/sparepart-branches/copy-from-branch');

        $response->assertOk();
        $response->assertSee('Salin Sparepart ke Cabang Semarang');
        $response->assertSee('Cabang Jakarta (1 sparepart baru)');
        $response->assertDontSee('Cabang Semarang (');
    }
}
