<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_branch_while_authenticated_stamps_created_by_and_updated_by(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $branch = Branch::create([
            'code' => 'JKT',
            'name' => 'Cabang Jakarta',
        ]);

        $this->assertSame($user->id, $branch->created_by);
        $this->assertSame($user->id, $branch->updated_by);
        $this->assertTrue($branch->is_active);
    }

    public function test_updating_branch_stamps_updated_by_with_current_user(): void
    {
        $creator = User::factory()->create();
        $this->actingAs($creator);
        $branch = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);

        $editor = User::factory()->create();
        $this->actingAs($editor);
        $branch->update(['name' => 'Cabang Bandung Kota']);

        $this->assertSame($creator->id, $branch->created_by);
        $this->assertSame($editor->id, $branch->updated_by);
    }

    public function test_branch_code_must_be_unique(): void
    {
        Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta 2']);
    }
}
