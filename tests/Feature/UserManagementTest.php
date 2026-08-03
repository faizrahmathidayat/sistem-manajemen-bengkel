<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
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

    public function test_index_lists_users_for_authorized_user(): void
    {
        User::factory()->create(['name' => 'Budi Santoso']);
        $user = $this->userWithPermissions(['user.view']);

        $response = $this->actingAs($user)->get('/users');

        $response->assertOk();
        $response->assertSee('Budi Santoso');
    }

    public function test_index_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/users');

        $response->assertForbidden();
    }

    public function test_store_creates_user_with_hashed_password(): void
    {
        $user = $this->userWithPermissions(['user.create']);

        $response = $this->actingAs($user)->post('/users', [
            'name' => 'Romi Ramdani',
            'username' => 'romi_ramdani',
            'password' => 'romi_ramdani',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/users');
        $created = User::where('username', 'romi_ramdani')->firstOrFail();
        $this->assertTrue(Hash::check('romi_ramdani', $created->password));
        $this->assertTrue($created->is_active);
    }

    public function test_store_validates_required_and_unique_username(): void
    {
        User::factory()->create(['username' => 'existing']);
        $user = $this->userWithPermissions(['user.create']);

        $response = $this->actingAs($user)->post('/users', [
            'name' => 'Duplikat',
            'username' => 'existing',
            'password' => 'secret123',
        ]);

        $response->assertSessionHasErrors(['username']);
    }

    public function test_store_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/users', [
            'name' => 'X',
            'username' => 'x_user',
            'password' => 'secret123',
        ]);

        $response->assertForbidden();
    }

    public function test_show_renders_profil_tab_for_authorized_user(): void
    {
        $target = User::factory()->create(['name' => 'Syilawati']);
        $user = $this->userWithPermissions(['user.view', 'user.edit']);

        $response = $this->actingAs($user)->get("/users/{$target->id}");

        $response->assertOk();
        $response->assertSee('Syilawati');
    }

    public function test_update_edits_name_username_and_optionally_password(): void
    {
        $target = User::factory()->create(['name' => 'Old Name', 'username' => 'old_username']);
        $user = $this->userWithPermissions(['user.view', 'user.edit']);

        $response = $this->actingAs($user)->put("/users/{$target->id}", [
            'name' => 'New Name',
            'username' => 'new_username',
            'password' => 'newpassword',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('users.show', $target));
        $target->refresh();
        $this->assertSame('New Name', $target->name);
        $this->assertSame('new_username', $target->username);
        $this->assertTrue(Hash::check('newpassword', $target->password));
    }

    public function test_update_keeps_password_unchanged_when_left_blank(): void
    {
        $target = User::factory()->create();
        $originalHash = $target->password;
        $user = $this->userWithPermissions(['user.view', 'user.edit']);

        $this->actingAs($user)->put("/users/{$target->id}", [
            'name' => $target->name,
            'username' => $target->username,
            'password' => '',
            'is_active' => '1',
        ]);

        $target->refresh();
        $this->assertSame($originalHash, $target->password);
    }

    public function test_update_can_deactivate_a_different_user(): void
    {
        $target = User::factory()->create(['is_active' => true]);
        $user = $this->userWithPermissions(['user.view', 'user.edit']);

        $this->actingAs($user)->put("/users/{$target->id}", [
            'name' => $target->name,
            'username' => $target->username,
            'is_active' => '0',
        ]);

        $target->refresh();
        $this->assertFalse($target->is_active);
    }

    public function test_user_cannot_deactivate_own_account_even_via_crafted_request(): void
    {
        $user = $this->userWithPermissions(['user.view', 'user.edit']);

        $response = $this->actingAs($user)->put("/users/{$user->id}", [
            'name' => $user->name,
            'username' => $user->username,
            'is_active' => '0',
        ]);

        $response->assertRedirect(route('users.show', $user));
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => true]);
    }

    public function test_update_is_forbidden_without_permission(): void
    {
        $target = User::factory()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put("/users/{$target->id}", [
            'name' => 'X',
            'username' => $target->username,
        ]);

        $response->assertForbidden();
    }

    public function test_index_search_by_name_filters_results(): void
    {
        User::factory()->create(['name' => 'Agus Setiawan']);
        User::factory()->create(['name' => 'Budi Santoso']);
        $user = $this->userWithPermissions(['user.view']);

        $response = $this->actingAs($user)->get('/users?q=Agus');

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
        $response->assertDontSee('Budi Santoso');
    }

    public function test_index_search_by_username_filters_results(): void
    {
        User::factory()->create(['name' => 'Agus Setiawan', 'username' => 'agus_setiawan']);
        User::factory()->create(['name' => 'Budi Santoso', 'username' => 'budi_santoso']);
        $user = $this->userWithPermissions(['user.view']);

        $response = $this->actingAs($user)->get('/users?q=agus_setiawan');

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
        $response->assertDontSee('Budi Santoso');
    }

    public function test_index_does_not_500_when_q_is_submitted_as_an_array(): void
    {
        User::factory()->create(['name' => 'Agus Setiawan']);
        $user = $this->userWithPermissions(['user.view']);

        $response = $this->actingAs($user)->get('/users?q[]=Agus');

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
    }

    public function test_index_branch_filter_scopes_to_selected_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $targetA = User::factory()->create(['name' => 'Agus Setiawan']);
        $targetB = User::factory()->create(['name' => 'Budi Santoso']);
        $admin = $this->userWithPermissions(['user.view']);
        (new UserBranchService())->assign($targetA, $branchA);
        (new UserBranchService())->assign($targetB, $branchB);
        (new UserBranchService())->assign($admin, $branchA);
        (new UserBranchService())->assign($admin, $branchB);

        $response = $this->actingAs(User::find($admin->id))->get("/users?branch_ids[]={$branchA->id}");

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
        $response->assertDontSee('Budi Santoso');
    }

    public function test_index_branch_filter_drops_branch_ids_the_user_is_not_assigned_to(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $targetA = User::factory()->create(['name' => 'Agus Setiawan']);
        $targetB = User::factory()->create(['name' => 'Budi Santoso']);
        $admin = $this->userWithPermissions(['user.view']);
        (new UserBranchService())->assign($targetA, $branchA);
        (new UserBranchService())->assign($targetB, $branchB);
        (new UserBranchService())->assign($admin, $branchA);

        $response = $this->actingAs(User::find($admin->id))->get("/users?branch_ids[]={$branchB->id}");

        $response->assertOk();
        $response->assertSee('Agus Setiawan');
        $response->assertSee('Budi Santoso');
    }

    public function test_index_shows_empty_state_when_no_users_match(): void
    {
        $user = $this->userWithPermissions(['user.view']);

        $response = $this->actingAs($user)->get('/users?q=NoSuchUserXYZ');

        $response->assertOk();
        $response->assertSee('Belum ada user');
    }

    public function test_empty_state_cta_shown_with_create_permission(): void
    {
        $user = $this->userWithPermissions(['user.view', 'user.create']);

        $response = $this->actingAs($user)->get('/users?q=NoSuchUserXYZ');

        $response->assertOk();
        $response->assertSee('Tambah User Pertama');
    }

    public function test_empty_state_cta_hidden_without_create_permission(): void
    {
        $user = $this->userWithPermissions(['user.view']);

        $response = $this->actingAs($user)->get('/users?q=NoSuchUserXYZ');

        $response->assertOk();
        $response->assertDontSee('Tambah User Pertama');
    }

    public function test_index_renders_filter_bar(): void
    {
        $user = $this->userWithPermissions(['user.view']);

        $response = $this->actingAs($user)->get('/users');

        $response->assertOk();
        $response->assertSee('Terapkan');
        $response->assertSee('Cari nama atau username...');
    }
}
