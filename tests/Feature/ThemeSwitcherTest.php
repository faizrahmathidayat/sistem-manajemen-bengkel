<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeSwitcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_layout_includes_anti_fouc_script_before_design_tokens(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSeeInOrder([
            "localStorage.getItem('theme')",
            'data-bs-theme',
            '--color-bg: #F5F7FB;',
        ], false);
    }

    public function test_guest_layout_includes_anti_fouc_script(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee("localStorage.getItem('theme')", false);
    }

    public function test_authenticated_layout_includes_dark_mode_token_overrides(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('html[data-bs-theme="dark"]', false);
        $response->assertSee('--color-bg: #0F172A;', false);
    }

    public function test_authenticated_layout_includes_theme_toggle_handler_script(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee("getElementById('themeToggleBtn')", false);
    }
}
