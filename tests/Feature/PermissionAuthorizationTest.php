<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PermissionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_only_perform_actions_they_have_been_granted(): void
    {
        $permission = Permission::create([
            'code' => 'pkb.view',
            'resource' => 'pkb',
            'action' => 'view',
            'description' => 'Melihat PKB',
        ]);
        $user = User::factory()->create();

        $this->assertFalse($user->can('pkb.view'));

        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);

        $reloaded = User::find($user->id);
        $this->assertTrue($reloaded->can('pkb.view'));
        $this->assertFalse($reloaded->can('invoice.void'));
    }

    public function test_inactive_permission_is_not_granted_even_if_assigned(): void
    {
        $permission = Permission::create([
            'code' => 'invoice.void',
            'resource' => 'invoice',
            'action' => 'void',
            'description' => 'Void invoice',
            'is_active' => false,
        ]);
        $user = User::factory()->create();
        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);

        $this->assertFalse($user->can('invoice.void'));
    }

    public function test_gate_before_does_not_short_circuit_argument_based_checks(): void
    {
        // The user holds the 'some.ability' permission code, so a no-argument
        // check must pass via the Gate::before blanket permission-code gate...
        $permission = Permission::create([
            'code' => 'some.ability',
            'resource' => 'some',
            'action' => 'ability',
            'description' => 'Some ability',
        ]);
        $user = User::factory()->create();
        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);
        $reloaded = User::find($user->id);

        $this->assertTrue($reloaded->can('some.ability'));

        // ...but an argument-based (record-level) check for the same ability
        // must defer to the Policy/Gate::define callback instead of being
        // short-circuited to true purely because the user holds the code.
        Gate::define('some.ability', fn ($gateUser, $model) => false);
        $someModelInstance = User::factory()->create();

        $this->assertFalse($reloaded->can('some.ability', $someModelInstance));
    }

    public function test_deactivated_user_with_granted_permission_is_denied(): void
    {
        $permission = Permission::create([
            'code' => 'pkb.view',
            'resource' => 'pkb',
            'action' => 'view',
            'description' => 'Melihat PKB',
        ]);
        $user = User::factory()->create(['is_active' => false]);
        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);

        $this->assertFalse($user->can('pkb.view'));
        $this->assertFalse($user->hasPermissionTo('pkb.view'));
    }
}
