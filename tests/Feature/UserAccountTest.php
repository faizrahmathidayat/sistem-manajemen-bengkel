<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_username_is_required_and_unique(): void
    {
        User::factory()->create(['username' => 'budi']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        User::factory()->create(['username' => 'budi']);
    }

    public function test_user_is_active_by_default(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->is_active);
        $this->assertNull($user->last_login_at);
    }

    public function test_is_super_admin_matches_configured_username(): void
    {
        $superAdmin = User::factory()->create(['username' => config('app.superadmin_username')]);
        $regular = User::factory()->create(['username' => 'someone_else']);

        $this->assertTrue($superAdmin->isSuperAdmin());
        $this->assertFalse($regular->isSuperAdmin());
    }
}
