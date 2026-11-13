<?php

namespace Tests\Feature;

use App\Exceptions\ApprovalChainException;
use App\Models\ApprovalLevel;
use App\Models\ApprovalRule;
use App\Models\User;
use App\Services\ApprovalChainResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesProcurementData;
use Tests\TestCase;

class ApprovalChainResolverTest extends TestCase
{
    use RefreshDatabase, MakesProcurementData;

    /** Manager(1) / Direktur(2) / CEO(3) with one active user each and the 10 jt / 50 jt / unbounded rules. */
    protected function standardConfig(): array
    {
        $this->resetApprovalConfig();
        $manager = $this->makeApprovalLevel('Manager', 1, [$this->makeUser()]);
        $director = $this->makeApprovalLevel('Direktur', 2, [$this->makeUser()]);
        $ceo = $this->makeApprovalLevel('CEO', 3, [$this->makeUser()]);
        $this->makeApprovalRule(10000000, [$manager], 1);
        $this->makeApprovalRule(50000000, [$manager, $director], 2);
        $this->makeApprovalRule(null, [$manager, $director, $ceo], 3);

        return [$manager, $director, $ceo];
    }

    protected function names(array $steps): array
    {
        return array_map(function ($step) {
            return $step['level_name'];
        }, $steps);
    }

    public function test_total_at_exactly_first_boundary_uses_first_rule(): void
    {
        $this->standardConfig();

        $steps = (new ApprovalChainResolver())->resolve(10000000);

        $this->assertSame(['Manager'], $this->names($steps));
    }

    public function test_total_one_rupiah_above_boundary_uses_second_rule(): void
    {
        $this->standardConfig();

        $this->assertSame(['Manager', 'Direktur'], $this->names((new ApprovalChainResolver())->resolve(10000001)));
        $this->assertSame(['Manager', 'Direktur'], $this->names((new ApprovalChainResolver())->resolve(50000000)));
    }

    public function test_total_above_last_bound_uses_unbounded_rule(): void
    {
        $this->standardConfig();

        $this->assertSame(
            ['Manager', 'Direktur', 'CEO'],
            $this->names((new ApprovalChainResolver())->resolve(50000001))
        );
        $this->assertSame(
            ['Manager', 'Direktur', 'CEO'],
            $this->names((new ApprovalChainResolver())->resolve(750000000))
        );
    }

    public function test_steps_carry_level_id_and_active_user_ids(): void
    {
        [$manager] = $this->standardConfig();

        $steps = (new ApprovalChainResolver())->resolve(1000);

        $this->assertSame($manager->id, $steps[0]['level_id']);
        $this->assertSame($manager->users->pluck('id')->all(), $steps[0]['user_ids']);
    }

    public function test_steps_follow_level_sort_order_not_rule_insertion_order(): void
    {
        $this->resetApprovalConfig();
        $director = $this->makeApprovalLevel('Direktur', 2, [$this->makeUser()]);
        $manager = $this->makeApprovalLevel('Manager', 1, [$this->makeUser()]);
        // Levels attached in reverse order on purpose.
        $this->makeApprovalRule(null, [$director, $manager], 1);

        $this->assertSame(['Manager', 'Direktur'], $this->names((new ApprovalChainResolver())->resolve(5)));
    }

    public function test_rule_selection_does_not_depend_on_rule_sort_order_column(): void
    {
        $this->resetApprovalConfig();
        $manager = $this->makeApprovalLevel('Manager', 1, [$this->makeUser()]);
        $director = $this->makeApprovalLevel('Direktur', 2, [$this->makeUser()]);
        // Stored out of order: the bigger bound first.
        $this->makeApprovalRule(50000000, [$manager, $director], 1);
        $this->makeApprovalRule(10000000, [$manager], 2);
        $this->makeApprovalRule(null, [$manager, $director], 3);

        $this->assertSame(['Manager'], $this->names((new ApprovalChainResolver())->resolve(9000000)));
    }

    public function test_inactive_levels_and_inactive_users_are_excluded(): void
    {
        $this->resetApprovalConfig();
        $activeUser = $this->makeUser();
        $manager = $this->makeApprovalLevel('Manager', 1, [$activeUser, $this->makeUser(false)]);
        $retired = $this->makeApprovalLevel('Kepala Lama', 2, [$this->makeUser()], false);
        $this->makeApprovalRule(null, [$manager, $retired], 1);

        $steps = (new ApprovalChainResolver())->resolve(1000);

        $this->assertSame(['Manager'], $this->names($steps));
        $this->assertSame([$activeUser->id], $steps[0]['user_ids']);
    }

    public function test_level_without_active_users_throws_with_level_name_in_message(): void
    {
        $this->resetApprovalConfig();
        $manager = $this->makeApprovalLevel('Manager', 1, [$this->makeUser()]);
        $director = $this->makeApprovalLevel('Direktur', 2, [$this->makeUser(false)]);
        $this->makeApprovalRule(null, [$manager, $director], 1);

        try {
            (new ApprovalChainResolver())->resolve(1000);
            $this->fail('Expected ApprovalChainException');
        } catch (ApprovalChainException $e) {
            $this->assertStringContainsString('Direktur', $e->getMessage());
        }
    }

    public function test_zero_or_negative_total_throws(): void
    {
        $this->standardConfig();

        $this->expectException(ApprovalChainException::class);
        (new ApprovalChainResolver())->resolve(0);
    }

    public function test_missing_rules_throw(): void
    {
        $this->resetApprovalConfig();

        $this->expectException(ApprovalChainException::class);
        (new ApprovalChainResolver())->resolve(1000);
    }

    public function test_seed_migration_defaults_exist(): void
    {
        $this->assertSame(['Manager', 'Direktur', 'CEO'], ApprovalLevel::orderBy('sort_order')->pluck('name')->all());
        $rules = ApprovalRule::orderBy('sort_order')->get();
        $this->assertCount(3, $rules);
        $this->assertSame('10000000.00', (string) $rules[0]->max_amount);
        $this->assertSame('50000000.00', (string) $rules[1]->max_amount);
        $this->assertNull($rules[2]->max_amount);
        $this->assertSame(['Manager'], $rules[0]->levels->pluck('name')->all());
        $this->assertSame(['Manager', 'Direktur', 'CEO'], $rules[2]->levels()->orderBy('sort_order')->pluck('name')->all());
        $this->assertSame(0, \DB::table('approval_level_users')->count());
    }
}
