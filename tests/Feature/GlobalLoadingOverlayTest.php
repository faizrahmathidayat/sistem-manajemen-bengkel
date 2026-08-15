<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalLoadingOverlayTest extends TestCase
{
    use RefreshDatabase;

    public function test_layout_includes_global_loading_overlay_markup(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('id="globalLoadingOverlay"', false);
        $response->assertSee('page-loading-overlay d-none', false);
    }

    public function test_layout_includes_navigation_and_submit_delegation_script(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee("getElementById('globalLoadingOverlay')", false);
        $response->assertSeeInOrder([
            "appBody.addEventListener('click'",
            "appBody.addEventListener('submit'",
        ], false);
    }

    public function test_design_tokens_include_blurred_backdrop_and_neon_spinner_styles(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('backdrop-filter: blur(5px);', false);
        $response->assertSee('loadingSpin', false);
    }
}
