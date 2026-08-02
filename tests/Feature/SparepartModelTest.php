<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\SparepartBranchStock;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SparepartModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_sparepart_can_be_created_with_fillable_fields(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);

        $this->assertSame('Ban Depan', $sparepart->name);
        $this->assertTrue($sparepart->is_active);
        $this->assertSame($user->id, $sparepart->created_by);
    }

    public function test_sparepart_code_is_unique(): void
    {
        Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);

        $this->expectException(QueryException::class);
        Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Belakang']);
    }

    public function test_sparepart_branches_rejects_duplicate_pair(): void
    {
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);

        $this->expectException(QueryException::class);
        SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 120000]);
    }

    public function test_creating_sparepart_branch_automatically_creates_a_zeroed_stock_row(): void
    {
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);

        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);

        $this->assertDatabaseHas('sparepart_branch_stocks', [
            'sparepart_branch_id' => $sparepartBranch->id,
            'on_hand_qty' => 0,
            'reserved_qty' => 0,
        ]);
    }

    public function test_available_qty_accessor_computes_on_hand_minus_reserved(): void
    {
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);
        DB::table('sparepart_branch_stocks')
            ->where('sparepart_branch_id', $sparepartBranch->id)
            ->update(['on_hand_qty' => 10, 'reserved_qty' => 3]);

        $stock = SparepartBranchStock::find($sparepartBranch->id);

        $this->assertEquals(7, $stock->available_qty);
    }

    public function test_deleting_sparepart_branch_cascades_to_its_stock_row(): void
    {
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);

        $sparepartBranch->delete();

        $this->assertDatabaseMissing('sparepart_branch_stocks', ['sparepart_branch_id' => $sparepartBranch->id]);
    }

    public function test_stock_check_constraint_rejects_negative_on_hand_qty(): void
    {
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);

        $this->expectException(QueryException::class);
        DB::table('sparepart_branch_stocks')
            ->where('sparepart_branch_id', $sparepartBranch->id)
            ->update(['on_hand_qty' => -1]);
    }

    public function test_stock_check_constraint_rejects_reserved_greater_than_on_hand(): void
    {
        $sparepart = Sparepart::create(['code' => 'BAN-01', 'name' => 'Ban Depan']);
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 100000]);

        $this->expectException(QueryException::class);
        DB::table('sparepart_branch_stocks')
            ->where('sparepart_branch_id', $sparepartBranch->id)
            ->update(['on_hand_qty' => 5, 'reserved_qty' => 10]);
    }
}
