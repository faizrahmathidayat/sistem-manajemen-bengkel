<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComponentPolishTest extends TestCase
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

    public function test_design_tokens_include_theme_aware_sidebar_variables(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('--color-sidebar: #FFFFFF;', false);
        $response->assertSee('--color-sidebar-border: #DBE4EF;', false);
        $response->assertSee('--color-sidebar: #090F1D;', false);
    }

    public function test_design_tokens_include_stat_icon_badge_and_row_animation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('color-mix(in srgb, var(--color-accent) 12%, transparent)', false);
        $response->assertSee('@keyframes rowFadeSlideIn', false);
        $response->assertSee('.line-row-enter', false);
    }

    public function test_work_order_create_page_includes_row_enter_animation_class(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');

        $response = $this->actingAs($user)->get(route('work-orders.create'));

        $response->assertOk();
        $response->assertSee("classList.add('line-row-enter')", false);
    }

    public function test_invoice_create_direct_page_includes_row_enter_animation_class(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.create');

        $response = $this->actingAs($user)->get(route('invoices.createDirect'));

        $response->assertOk();
        $response->assertSee("classList.add('line-row-enter')", false);
    }
}
