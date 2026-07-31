<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
