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

class SparepartBranchIndexAndCreateTest extends TestCase
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

    public function test_index_shows_no_access_page_for_user_without_any_branch_grant(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/sparepart-branches');

        $response->assertOk();
        $response->assertSee('belum memiliki akses');
    }

    public function test_index_lists_configs_for_the_current_branch_only(): void
    {
        $user = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->grantBranchPermission($user, $branchA, 'sparepart.view');
        $this->grantBranchPermission($user, $branchB, 'sparepart.view');
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan Jakarta']);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branchA->id, 'selling_price' => 100000]);
        $sparepartB = Sparepart::create(['code' => 'BAN-02', 'name' => 'Ban Depan Bandung']);
        SparepartBranch::create(['sparepart_id' => $sparepartB->id, 'branch_id' => $branchB->id, 'selling_price' => 90000]);

        $response = $this->actingAs(User::find($user->id))->get('/sparepart-branches?branch_id=' . $branchA->id);

        $response->assertOk();
        $response->assertSee('Ban Depan Jakarta');
        $response->assertDontSee('Ban Depan Bandung');
    }

    public function test_index_does_not_leak_data_or_switch_session_for_branch_without_permission(): void
    {
        $user = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->grantBranchPermission($user, $branchA, 'sparepart.view');
        // Note: user is NOT granted sparepart.view in branch B.
        $sparepartA = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan Jakarta']);
        SparepartBranch::create(['sparepart_id' => $sparepartA->id, 'branch_id' => $branchA->id, 'selling_price' => 100000]);
        $sparepartB = Sparepart::create(['code' => 'BAN-02', 'name' => 'Ban Depan Bandung']);
        SparepartBranch::create(['sparepart_id' => $sparepartB->id, 'branch_id' => $branchB->id, 'selling_price' => 90000]);

        $response = $this->actingAs($user)->get('/sparepart-branches?branch_id=' . $branchB->id);

        $response->assertOk();
        $response->assertSee('Ban Depan Jakarta');
        $response->assertDontSee('Ban Depan Bandung');
        $this->assertSame($branchA->id, session('current_sparepart_branch_id'), 'Session must not switch to a branch the user lacks permission for.');
    }

    public function test_index_search_filters_by_code_or_name(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $ban = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        SparepartBranch::create(['sparepart_id' => $ban->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);
        $oli = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        SparepartBranch::create(['sparepart_id' => $oli->id, 'branch_id' => $branch->id, 'selling_price' => 50000]);

        $response = $this->actingAs(User::find($user->id))->get('/sparepart-branches?q=Oli');

        $response->assertOk();
        $response->assertSee('Oli Mesin');
        $response->assertDontSee('Ban Depan');
    }

    public function test_create_new_sparepart_creates_identity_branch_config_and_zeroed_stock(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $this->grantBranchPermission($user, $branch, 'sparepart.create');
        $this->actingAs(User::find($user->id))->get('/sparepart-branches'); // establishes session branch context

        $response = $this->post('/sparepart-branches', [
            'branch_id' => $branch->id,
            'code' => 'BAN-01',
            'name' => 'Ban Depan',
            'rack_number' => 'A1',
            'selling_price' => 150000,
            'minimum_stock' => 2,
        ]);

        $response->assertRedirect('/sparepart-branches');
        $this->assertDatabaseHas('spareparts', ['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $sparepartBranch = SparepartBranch::whereHas('sparepart', fn ($q) => $q->where('code', 'BAN-01'))->first();
        $this->assertNotNull($sparepartBranch);
        $this->assertSame($branch->id, $sparepartBranch->branch_id);
        $this->assertDatabaseHas('sparepart_branch_stocks', ['sparepart_branch_id' => $sparepartBranch->id, 'on_hand_qty' => 0]);
    }

    public function test_create_new_sparepart_requires_sparepart_create_permission_in_current_branch(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $this->actingAs(User::find($user->id))->get('/sparepart-branches');

        $response = $this->post('/sparepart-branches', [
            'branch_id' => $branch->id, 'code' => 'BAN-01', 'name' => 'Ban Depan', 'selling_price' => 150000,
        ]);

        $response->assertForbidden();
    }

    public function test_create_new_sparepart_validates_global_code_uniqueness(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $this->grantBranchPermission($user, $branch, 'sparepart.create');
        Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan Lama']);
        $this->actingAs(User::find($user->id))->get('/sparepart-branches');

        $response = $this->post('/sparepart-branches', [
            'branch_id' => $branch->id, 'code' => 'BAN-01', 'name' => 'Ban Depan Baru', 'selling_price' => 150000,
        ]);

        $response->assertSessionHasErrors(['code']);
    }

    public function test_create_new_sparepart_writes_to_authorized_branch_even_when_view_permission_fallback_differs(): void
    {
        $user = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        // User has sparepart.create in branch A but NOT sparepart.view in branch A.
        // User has sparepart.view in branch B (so resolveCurrentBranch() / session would point at B).
        $this->grantBranchPermission($user, $branchA, 'sparepart.create');
        $this->grantBranchPermission($user, $branchB, 'sparepart.view');
        session(['current_sparepart_branch_id' => $branchB->id]);

        $response = $this->actingAs($user)->post('/sparepart-branches', [
            'branch_id' => $branchA->id,
            'code' => 'BAN-01',
            'name' => 'Ban Depan',
            'rack_number' => 'A1',
            'selling_price' => 150000,
            'minimum_stock' => 2,
        ]);

        $response->assertRedirect('/sparepart-branches');
        $sparepartBranch = SparepartBranch::whereHas('sparepart', fn ($q) => $q->where('code', 'BAN-01'))->first();
        $this->assertNotNull($sparepartBranch);
        $this->assertSame($branchA->id, $sparepartBranch->branch_id, 'Sparepart must be written to the branch_id submitted with the form (A), not the session/view-permission fallback branch (B).');
    }

    public function test_store_existing_writes_to_authorized_branch_even_when_view_permission_fallback_differs(): void
    {
        $user = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->grantBranchPermission($user, $branchA, 'sparepart.create');
        $this->grantBranchPermission($user, $branchB, 'sparepart.view');
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        session(['current_sparepart_branch_id' => $branchB->id]);

        $response = $this->actingAs($user)->post('/sparepart-branches/existing', [
            'branch_id' => $branchA->id,
            'sparepart_id' => $sparepart->id,
            'rack_number' => 'B2',
            'selling_price' => 60000,
            'minimum_stock' => 5,
        ]);

        $response->assertRedirect('/sparepart-branches');
        $sparepartBranch = SparepartBranch::where('sparepart_id', $sparepart->id)->first();
        $this->assertNotNull($sparepartBranch);
        $this->assertSame($branchA->id, $sparepartBranch->branch_id, 'Sparepart must be attached to the branch_id submitted with the form (A), not the session/view-permission fallback branch (B).');
    }

    public function test_create_existing_lists_only_spareparts_not_yet_configured_for_current_branch(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $this->grantBranchPermission($user, $branch, 'sparepart.create');
        $alreadyConfigured = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Sudah Ada']);
        SparepartBranch::create(['sparepart_id' => $alreadyConfigured->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);
        $notYetConfigured = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Belum Ada']);
        $this->actingAs(User::find($user->id))->get('/sparepart-branches');

        $response = $this->get('/sparepart-branches/create-existing');

        $response->assertOk();
        $response->assertSee('Oli Belum Ada');
        $response->assertDontSee('Ban Sudah Ada');
    }

    public function test_store_existing_attaches_sparepart_to_branch_with_new_config_and_stock(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $this->grantBranchPermission($user, $branch, 'sparepart.create');
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $this->actingAs(User::find($user->id))->get('/sparepart-branches');

        $response = $this->post('/sparepart-branches/existing', [
            'branch_id' => $branch->id,
            'sparepart_id' => $sparepart->id,
            'rack_number' => 'B2',
            'selling_price' => 60000,
            'minimum_stock' => 5,
        ]);

        $response->assertRedirect('/sparepart-branches');
        $sparepartBranch = SparepartBranch::where('sparepart_id', $sparepart->id)->where('branch_id', $branch->id)->first();
        $this->assertNotNull($sparepartBranch);
        $this->assertDatabaseHas('sparepart_branch_stocks', ['sparepart_branch_id' => $sparepartBranch->id, 'on_hand_qty' => 0]);
    }

    public function test_store_existing_rejects_sparepart_already_configured_for_branch(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $this->grantBranchPermission($user, $branch, 'sparepart.create');
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);
        $this->actingAs(User::find($user->id))->get('/sparepart-branches');

        $response = $this->post('/sparepart-branches/existing', [
            'branch_id' => $branch->id, 'sparepart_id' => $sparepart->id, 'selling_price' => 100000,
        ]);

        $response->assertSessionHasErrors(['sparepart_id']);
    }

    public function test_index_does_not_500_when_q_is_submitted_as_an_array(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);

        $response = $this->actingAs(User::find($user->id))->get('/sparepart-branches?q[]=Ban');

        $response->assertOk();
        $response->assertSee('Ban Depan');
    }

    public function test_index_shows_empty_state_when_branch_has_no_spareparts(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');

        $response = $this->actingAs(User::find($user->id))->get('/sparepart-branches');

        $response->assertOk();
        $response->assertSee('Belum ada sparepart di cabang ini');
    }

    public function test_empty_state_cta_shown_when_user_has_create_permission_in_current_branch(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');
        $this->grantBranchPermission($user, $branch, 'sparepart.create');

        $response = $this->actingAs(User::find($user->id))->get('/sparepart-branches');

        $response->assertOk();
        $response->assertSee('Sparepart Baru');
    }

    public function test_empty_state_cta_hidden_when_user_lacks_create_permission_in_current_branch(): void
    {
        $user = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->grantBranchPermission($user, $branchA, 'sparepart.view');
        // sparepart.create granted only in branch B, not the current branch (A).
        $this->grantBranchPermission($user, $branchB, 'sparepart.create');

        $response = $this->actingAs(User::find($user->id))->get('/sparepart-branches?branch_id=' . $branchA->id);

        $response->assertOk();
        $response->assertDontSee('Sparepart Baru');
    }

    public function test_index_renders_filter_bar_with_branch_switcher(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');

        $response = $this->actingAs(User::find($user->id))->get('/sparepart-branches');

        $response->assertOk();
        $response->assertSee('Terapkan');
        $response->assertSee('Cabang Jakarta');
    }

    public function test_create_shows_no_access_page_for_user_without_create_permission_in_any_branch(): void
    {
        $user = User::factory()->create();
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->grantBranchPermission($user, $branch, 'sparepart.view');

        $response = $this->actingAs($user)->get('/sparepart-branches/create');

        $response->assertOk();
        $response->assertSee('belum memiliki akses');
    }

    public function test_create_lists_every_branch_the_user_can_create_in(): void
    {
        $user = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->grantBranchPermission($user, $branchA, 'sparepart.create');
        $this->grantBranchPermission($user, $branchB, 'sparepart.create');

        $response = $this->actingAs($user)->get('/sparepart-branches/create');

        $response->assertOk();
        $response->assertSee('Cabang Jakarta');
        $response->assertSee('Cabang Bandung');
    }

    public function test_create_is_reachable_when_session_branch_lacks_create_permission_but_another_branch_has_it(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        // User has sparepart.view (only) in branch A, so the index page's session
        // switcher parks them on branch A. They have sparepart.create only in branch B.
        $this->grantBranchPermission($user, $branchA, 'sparepart.view');
        $this->grantBranchPermission($user, $branchB, 'sparepart.create');
        $this->actingAs($user)->get('/sparepart-branches'); // establishes session on branch A

        $response = $this->get('/sparepart-branches/create');

        $response->assertOk();
        $response->assertSee('Cabang Bandung');
        $response->assertDontSee('403');
    }

    public function test_create_defaults_select_to_session_branch_when_it_is_a_valid_option(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'sparepart.view');
        $this->grantBranchPermission($user, $branchA, 'sparepart.create');
        $this->grantBranchPermission($user, $branchB, 'sparepart.view');
        $this->grantBranchPermission($user, $branchB, 'sparepart.create');
        $this->actingAs($user)->get('/sparepart-branches?branch_id=' . $branchB->id); // session -> branch B

        $response = $this->get('/sparepart-branches/create');

        $response->assertOk();
        $response->assertSee('<option value="' . $branchB->id . '" selected', false);
    }

    public function test_store_still_rejects_branch_id_the_user_cannot_create_in(): void
    {
        $user = User::factory()->create();
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->grantBranchPermission($user, $branchA, 'sparepart.create');
        // User has no sparepart.create grant in branch B.
        $this->actingAs($user)->get('/sparepart-branches');

        $response = $this->post('/sparepart-branches', [
            'branch_id' => $branchB->id, 'code' => 'BAN-01', 'name' => 'Ban Depan', 'selling_price' => 150000,
        ]);

        $response->assertForbidden();
    }

    public function test_create_does_not_change_session_branch_after_successful_store_into_a_different_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'sparepart.view');
        $this->grantBranchPermission($user, $branchA, 'sparepart.create');
        $this->grantBranchPermission($user, $branchB, 'sparepart.create');
        $this->actingAs($user)->get('/sparepart-branches'); // session -> branch A (first allowed)

        $response = $this->post('/sparepart-branches', [
            'branch_id' => $branchB->id,
            'code' => 'BAN-01',
            'name' => 'Ban Depan',
            'selling_price' => 150000,
        ]);

        $response->assertRedirect('/sparepart-branches');
        $this->assertSame($branchA->id, session('current_sparepart_branch_id'), 'Creating into a different branch must not switch the session context.');
    }
}
