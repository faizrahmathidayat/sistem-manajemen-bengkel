<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_correct_username_and_password(): void
    {
        $user = User::factory()->create([
            'username' => 'budi',
            'password' => bcrypt('rahasia123'),
        ]);

        $response = $this->post('/login', [
            'username' => 'budi',
            'password' => 'rahasia123',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create(['username' => 'budi', 'password' => bcrypt('rahasia123')]);

        $response = $this->post('/login', [
            'username' => 'budi',
            'password' => 'salah',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create([
            'username' => 'budi',
            'password' => bcrypt('rahasia123'),
            'is_active' => false,
        ]);

        $response = $this->post('/login', [
            'username' => 'budi',
            'password' => 'rahasia123',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/logout');

        $this->assertGuest();
    }

    public function test_deactivated_user_is_logged_out_on_next_request(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        $user->update(['is_active' => false]);

        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
        $this->assertGuest();
    }
}
