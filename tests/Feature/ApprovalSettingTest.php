<?php

namespace Tests\Feature;

use App\Models\ApprovalLevel;
use App\Models\ApprovalRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsPermissions;
use Tests\Concerns\MakesProcurementData;
use Tests\TestCase;

class ApprovalSettingTest extends TestCase
{
    use RefreshDatabase, GrantsPermissions, MakesProcurementData;

    protected function admin(array $codes = ['approval_setting.view', 'approval_setting.manage']): User
    {
        $user = User::factory()->create();
        foreach ($codes as $code) {
            $this->grantGlobal($user, $code);
        }

        return User::find($user->id);
    }

    /** The migration-seeded levels as a payload the form would post, with optional per-level overrides. */
    protected function levelsPayload(array $overrides = []): array
    {
        $payload = [];
        foreach (ApprovalLevel::orderBy('sort_order')->get() as $level) {
            $payload[] = array_merge([
                'id' => $level->id,
                'name' => $level->name,
                'is_active' => 1,
                'user_ids' => $level->users->pluck('id')->all(),
            ], $overrides[$level->name] ?? []);
        }

        return ['levels' => $payload];
    }

    protected function levelId(string $name): int
    {
        return ApprovalLevel::where('name', $name)->value('id');
    }

    protected function rulesPayload(array $rules): array
    {
        return ['rules' => $rules];
    }

    public function test_index_requires_view_permission_and_renders_both_tabs(): void
    {
        $this->actingAs(User::factory()->create())->get('/approval-settings')->assertForbidden();

        $admin = $this->admin(['approval_setting.view']);
        $this->actingAs($admin)->get('/approval-settings')->assertOk()->assertSee('Manager');
        $this->actingAs($admin)->get('/approval-settings?tab=levels')->assertOk()->assertSee('Tambah Level');
        $this->actingAs($admin)->get('/approval-settings?tab=rules')->assertOk()->assertSee('Tambah Aturan');
    }

    public function test_view_only_user_cannot_save(): void
    {
        $viewer = $this->admin(['approval_setting.view']);

        $this->actingAs($viewer)->put('/approval-settings/levels', $this->levelsPayload())->assertForbidden();
        $this->actingAs($viewer)->put('/approval-settings/rules', $this->rulesPayload([
            ['max_amount' => '', 'level_ids' => [$this->levelId('Manager')]],
        ]))->assertForbidden();
    }

    public function test_save_levels_adds_renames_reorders_and_syncs_users_with_audit(): void
    {
        $sri = $this->makeUser();
        $hadi = $this->makeUser();
        $payload = $this->levelsPayload([
            'Manager' => ['user_ids' => [$sri->id]],
            'Direktur' => ['user_ids' => [$hadi->id]],
        ]);
        $payload['levels'][] = ['name' => 'Kepala Cabang', 'is_active' => 1, 'user_ids' => [$sri->id]];
        // Move Kepala Cabang to the front.
        array_unshift($payload['levels'], array_pop($payload['levels']));

        $this->actingAs($this->admin())->put('/approval-settings/levels', $payload)
            ->assertSessionHasNoErrors()->assertRedirect();

        $ordered = ApprovalLevel::orderBy('sort_order')->pluck('name')->all();
        $this->assertSame(['Kepala Cabang', 'Manager', 'Direktur', 'CEO'], $ordered);
        $this->assertSame([$sri->id], ApprovalLevel::where('name', 'Manager')->first()->users->pluck('id')->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'approval_setting.changed']);
    }

    public function test_save_levels_rejects_duplicate_names_and_inactive_users(): void
    {
        $inactive = $this->makeUser(false);
        $admin = $this->admin();

        $dup = $this->levelsPayload();
        $dup['levels'][1]['name'] = 'manager';
        $this->actingAs($admin)->put('/approval-settings/levels', $dup)->assertSessionHasErrors('levels');

        $this->actingAs($admin)->put('/approval-settings/levels', $this->levelsPayload([
            'Manager' => ['user_ids' => [$inactive->id]],
        ]))->assertSessionHasErrors('levels');

        $this->assertSame([], ApprovalLevel::where('name', 'Manager')->first()->users->pluck('id')->all());
    }

    public function test_level_used_by_a_rule_cannot_be_deleted_or_deactivated(): void
    {
        $admin = $this->admin();

        $deactivate = $this->levelsPayload(['Direktur' => ['is_active' => 0]]);
        $this->actingAs($admin)->put('/approval-settings/levels', $deactivate)->assertSessionHasErrors('levels');
        $this->assertTrue(ApprovalLevel::where('name', 'Direktur')->first()->is_active);

        $delete = $this->levelsPayload(['CEO' => ['delete' => 1]]);
        $this->actingAs($admin)->put('/approval-settings/levels', $delete)->assertSessionHasErrors('levels');
        $this->assertSame(1, ApprovalLevel::where('name', 'CEO')->count());
    }

    public function test_unused_level_can_be_deleted(): void
    {
        $admin = $this->admin();
        $payload = $this->levelsPayload();
        $payload['levels'][] = ['name' => 'Sementara', 'is_active' => 1, 'user_ids' => []];
        $this->actingAs($admin)->put('/approval-settings/levels', $payload)->assertSessionHasNoErrors();
        $id = $this->levelId('Sementara');

        $remove = $this->levelsPayload();
        $remove['levels'][] = ['id' => $id, 'name' => 'Sementara', 'is_active' => 1, 'user_ids' => [], 'delete' => 1];
        $this->actingAs($admin)->put('/approval-settings/levels', $remove)->assertSessionHasNoErrors();

        $this->assertSame(0, ApprovalLevel::where('name', 'Sementara')->count());
    }

    public function test_save_rules_replaces_rules_and_normalizes_thousand_separators(): void
    {
        $manager = $this->levelId('Manager');
        $director = $this->levelId('Direktur');

        $this->actingAs($this->admin())->put('/approval-settings/rules', $this->rulesPayload([
            ['max_amount' => '5.000.000', 'level_ids' => [$manager]],
            ['max_amount' => '', 'level_ids' => [$manager, $director]],
        ]))->assertSessionHasNoErrors();

        $rules = ApprovalRule::orderBy('sort_order')->get();
        $this->assertCount(2, $rules);
        $this->assertSame('5000000.00', (string) $rules[0]->max_amount);
        $this->assertNull($rules[1]->max_amount);
        $this->assertSame([$manager, $director], $rules[1]->levels()->orderBy('sort_order')->pluck('approval_levels.id')->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'approval_setting.changed']);
    }

    public function test_save_rules_rejects_invalid_configurations(): void
    {
        $m = $this->levelId('Manager');
        $d = $this->levelId('Direktur');
        $admin = $this->admin();
        $before = ApprovalRule::count();

        $cases = [
            'bounds not ascending' => [['max_amount' => 50000000, 'level_ids' => [$m]], ['max_amount' => 10000000, 'level_ids' => [$m]], ['max_amount' => '', 'level_ids' => [$m]]],
            'equal bounds' => [['max_amount' => 10000000, 'level_ids' => [$m]], ['max_amount' => 10000000, 'level_ids' => [$m]], ['max_amount' => '', 'level_ids' => [$m]]],
            'last rule bounded' => [['max_amount' => 10000000, 'level_ids' => [$m]], ['max_amount' => 50000000, 'level_ids' => [$m, $d]]],
            'null bound before the end' => [['max_amount' => '', 'level_ids' => [$m]], ['max_amount' => '', 'level_ids' => [$m, $d]]],
            'rule without levels' => [['max_amount' => 10000000, 'level_ids' => []], ['max_amount' => '', 'level_ids' => [$m]]],
            'zero bound' => [['max_amount' => 0, 'level_ids' => [$m]], ['max_amount' => '', 'level_ids' => [$m]]],
            'no rules' => [],
        ];

        foreach ($cases as $label => $rules) {
            $response = $this->actingAs($admin)->put('/approval-settings/rules', $this->rulesPayload($rules));
            $this->assertTrue($response->getSession()->has('errors') && $response->getSession()->get('errors')->has('rules'), 'No rules error for: ' . $label);
            $this->assertSame($before, ApprovalRule::count(), $label);
        }
    }

    public function test_save_rules_rejects_inactive_levels(): void
    {
        $retired = ApprovalLevel::create(['name' => 'Lama', 'sort_order' => 9, 'is_active' => false]);

        $this->actingAs($this->admin())->put('/approval-settings/rules', $this->rulesPayload([
            ['max_amount' => '', 'level_ids' => [$retired->id]],
        ]))->assertSessionHasErrors('rules');
    }

    public function test_preview_returns_the_chain_with_approver_names_or_a_readable_message(): void
    {
        $sri = User::factory()->create(['name' => 'Sri Wahyuni']);
        $hadi = User::factory()->create(['name' => 'Hadi Santoso']);
        ApprovalLevel::where('name', 'Manager')->first()->users()->sync([$sri->id]);
        ApprovalLevel::where('name', 'Direktur')->first()->users()->sync([$hadi->id]);
        $admin = $this->admin(['approval_setting.view']);

        $ok = $this->actingAs($admin)->getJson('/approval-settings/preview?total=38320000')->assertOk()->json();
        $this->assertTrue($ok['ok']);
        $this->assertSame(['Manager', 'Direktur'], array_column($ok['steps'], 'level_name'));
        $this->assertSame(['Sri Wahyuni'], $ok['steps'][0]['users']);

        $missing = $this->actingAs($admin)->getJson('/approval-settings/preview?total=900000000')->assertOk()->json();
        $this->assertFalse($missing['ok']);
        $this->assertStringContainsString('CEO', $missing['message']);

        $this->actingAs($admin)->getJson('/approval-settings/preview?total=abc')->assertOk()->assertJson(['ok' => false]);
        $this->actingAs(User::factory()->create())->getJson('/approval-settings/preview?total=1000')->assertForbidden();
    }

    public function test_user_lookup_returns_only_active_users_for_view_permission(): void
    {
        User::factory()->create(['name' => 'Budi Aktif', 'is_active' => true]);
        User::factory()->create(['name' => 'Budi Mati', 'is_active' => false]);
        $admin = $this->admin(['approval_setting.view']);

        $json = $this->actingAs($admin)->getJson('/approval-settings/lookup-users?q=Budi')->assertOk()->json();

        $this->assertCount(1, $json);
        $this->assertStringContainsString('Budi Aktif', $json[0]['text']);
        $this->actingAs(User::factory()->create())->getJson('/approval-settings/lookup-users?q=Budi')->assertForbidden();
    }

    public function test_sidebar_link_needs_approval_setting_view(): void
    {
        $this->actingAs($this->admin(['approval_setting.view']))->get('/approval-settings')
            ->assertSee(route('approval-settings.index'), false);

        $other = User::factory()->create();
        $this->grantGlobal($other, 'dashboard.view');
        $this->actingAs(User::find($other->id))->get('/dashboard')
            ->assertDontSee(route('approval-settings.index'), false);
    }
}
