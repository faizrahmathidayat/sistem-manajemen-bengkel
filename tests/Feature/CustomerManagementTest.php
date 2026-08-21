<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
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

    public function test_index_lists_customers_for_authorized_user(): void
    {
        Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $user = $this->userWithPermissions(['customer.view']);

        $response = $this->actingAs($user)->get('/customers');

        $response->assertOk();
        $response->assertSee('Budi Santoso');
    }

    public function test_index_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/customers');

        $response->assertForbidden();
    }

    public function test_store_creates_customer(): void
    {
        $user = $this->userWithPermissions(['customer.create']);

        $response = $this->actingAs($user)->post('/customers', [
            'customer_type' => 'INDIVIDUAL',
            'name' => 'Budi Santoso',
            'stnk_name' => 'Budi Santoso',
            'phone' => '081234567890',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/customers');
        $this->assertDatabaseHas('customers', ['name' => 'BUDI SANTOSO']);
    }

    public function test_store_uppercases_name_and_stnk_name(): void
    {
        $user = $this->userWithPermissions(['customer.create']);

        $response = $this->actingAs($user)->post('/customers', [
            'customer_type' => 'INDIVIDUAL',
            'name' => 'budi santoso',
            'stnk_name' => 'Budi Santoso Wijaya',
            'is_active' => '1',
        ]);

        $response->assertRedirect('/customers');
        $this->assertDatabaseHas('customers', [
            'name' => 'BUDI SANTOSO',
            'stnk_name' => 'BUDI SANTOSO WIJAYA',
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $user = $this->userWithPermissions(['customer.create']);

        $response = $this->actingAs($user)->post('/customers', []);

        $response->assertSessionHasErrors(['customer_type', 'name', 'stnk_name']);
    }

    public function test_store_rejects_invalid_customer_type(): void
    {
        $user = $this->userWithPermissions(['customer.create']);

        $response = $this->actingAs($user)->post('/customers', [
            'customer_type' => 'GOVERNMENT',
            'name' => 'Budi Santoso',
            'stnk_name' => 'Budi Santoso',
        ]);

        $response->assertSessionHasErrors(['customer_type']);
    }

    public function test_store_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/customers', [
            'customer_type' => 'INDIVIDUAL',
            'name' => 'Budi Santoso',
            'stnk_name' => 'Budi Santoso',
        ]);

        $response->assertForbidden();
    }

    public function test_show_renders_profil_tab_for_authorized_user(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $user = $this->userWithPermissions(['customer.view']);

        $response = $this->actingAs($user)->get("/customers/{$customer->id}");

        $response->assertOk();
        $response->assertSee('Budi Santoso');
    }

    public function test_update_edits_customer_and_can_deactivate(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $user = $this->userWithPermissions(['customer.edit']);

        $response = $this->actingAs($user)->put("/customers/{$customer->id}", [
            'customer_type' => 'INDIVIDUAL',
            'name' => 'Budi Santoso Edited',
            'stnk_name' => 'Budi Santoso',
            'is_active' => '0',
        ]);

        $response->assertRedirect("/customers/{$customer->id}");
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'BUDI SANTOSO EDITED',
            'is_active' => false,
        ]);
    }

    public function test_update_is_forbidden_without_permission(): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->put("/customers/{$customer->id}", [
            'customer_type' => 'INDIVIDUAL',
            'name' => 'Budi Santoso Edited',
            'stnk_name' => 'Budi Santoso',
        ]);

        $response->assertForbidden();
    }

    public function test_index_shows_empty_state_when_no_customers_match(): void
    {
        $user = $this->userWithPermissions(['customer.view']);

        $response = $this->actingAs($user)->get('/customers');

        $response->assertOk();
        $response->assertSee('Belum ada customer');
        $response->assertSee('Mulai dengan menambahkan customer pertama Anda.');
    }

    public function test_empty_state_cta_shown_with_create_permission(): void
    {
        $user = $this->userWithPermissions(['customer.view', 'customer.create']);

        $response = $this->actingAs($user)->get('/customers');

        $response->assertOk();
        $response->assertSee('Tambah Customer Pertama');
    }

    public function test_empty_state_cta_hidden_without_create_permission(): void
    {
        $user = $this->userWithPermissions(['customer.view']);

        $response = $this->actingAs($user)->get('/customers');

        $response->assertOk();
        $response->assertDontSee('Tambah Customer Pertama');
    }

    public function test_index_search_by_name_filters_results(): void
    {
        Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Siti Aminah', 'stnk_name' => 'Siti Aminah']);
        $user = $this->userWithPermissions(['customer.view']);

        $response = $this->actingAs($user)->get('/customers?q=Budi');

        $response->assertOk();
        $response->assertSee('Budi Santoso');
        $response->assertDontSee('Siti Aminah');
    }

    public function test_index_does_not_500_when_q_is_submitted_as_an_array(): void
    {
        Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $user = $this->userWithPermissions(['customer.view']);

        $response = $this->actingAs($user)->get('/customers?q[]=Budi');

        $response->assertOk();
        $response->assertSee('Budi Santoso');
    }

    public function test_index_search_by_phone_filters_results(): void
    {
        Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso', 'phone' => '081111111111']);
        Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Siti Aminah', 'stnk_name' => 'Siti Aminah', 'phone' => '082222222222']);
        $user = $this->userWithPermissions(['customer.view']);

        $response = $this->actingAs($user)->get('/customers?q=081111');

        $response->assertOk();
        $response->assertSee('Budi Santoso');
        $response->assertDontSee('Siti Aminah');
    }

    public function test_index_renders_filter_bar(): void
    {
        $user = $this->userWithPermissions(['customer.view']);

        $response = $this->actingAs($user)->get('/customers');

        $response->assertOk();
        $response->assertSee('Terapkan');
        $response->assertSee('Cari nama atau telepon...');
    }

    public function test_index_branch_filter_scopes_to_selected_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Siti Aminah', 'stnk_name' => 'Siti Aminah']);
        CustomerBranch::create(['customer_id' => $customerA->id, 'branch_id' => $branchA->id]);
        CustomerBranch::create(['customer_id' => $customerB->id, 'branch_id' => $branchB->id]);
        $user = $this->userWithPermissions(['customer.view']);
        (new UserBranchService())->assign($user, $branchA);
        (new UserBranchService())->assign($user, $branchB);

        $response = $this->actingAs(User::find($user->id))->get("/customers?branch_ids[]={$branchA->id}");

        $response->assertOk();
        $response->assertSee('Budi Santoso');
        $response->assertDontSee('Siti Aminah');
    }

    public function test_index_branch_filter_drops_branch_ids_the_user_is_not_assigned_to(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $customerA = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        $customerB = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Siti Aminah', 'stnk_name' => 'Siti Aminah']);
        CustomerBranch::create(['customer_id' => $customerA->id, 'branch_id' => $branchA->id]);
        CustomerBranch::create(['customer_id' => $customerB->id, 'branch_id' => $branchB->id]);
        $user = $this->userWithPermissions(['customer.view']);
        (new UserBranchService())->assign($user, $branchA);

        $response = $this->actingAs(User::find($user->id))->get("/customers?branch_ids[]={$branchB->id}");

        $response->assertOk();
        // The invalid branch id (not assigned to the user) is dropped, falling back to
        // unfiltered — proven here by seeing both a customer in the user's own branch
        // (branchA) and the customer in the disallowed branch (branchB). Combined with
        // test_index_branch_filter_scopes_to_selected_branch (which proves a *valid*
        // branch_id genuinely filters), these two tests together triangulate correct
        // behavior.
        $response->assertSee('Budi Santoso');
        $response->assertSee('Siti Aminah');
    }
}
