<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavbarRedesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_navbar_no_longer_displays_permission_badges(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('topbar-permission-badge', false);
    }

    public function test_navbar_displays_todays_date_in_indonesian(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee(now()->locale('id')->translatedFormat('l, d F Y'));
    }

    public function test_navbar_displays_theme_toggle_button(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('id="themeToggleBtn"', false);
    }

    public function test_navbar_displays_profile_dropdown_with_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('id="profileDropdownToggle"', false);
        $response->assertSee(route('logout'), false);
        $response->assertSee(strtoupper(mb_substr($user->name, 0, 1)), false);
    }
}
