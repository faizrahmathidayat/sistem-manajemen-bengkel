<?php

namespace Tests\Concerns;

use App\Models\ApprovalLevel;
use App\Models\ApprovalRule;
use App\Models\Branch;
use App\Models\PurchaseRequest;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

trait MakesProcurementData
{
    private $procurementSeq = 0;

    protected function makeBranch(string $code = 'BKS', string $name = 'Cabang Bekasi'): Branch
    {
        return Branch::firstOrCreate(['code' => $code], ['name' => $name]);
    }

    protected function makeSparepartBranch(Branch $branch, ?string $code = null, float $price = 60000): SparepartBranch
    {
        $this->procurementSeq++;
        $code = $code ?: 'SP-' . $this->procurementSeq;
        $sparepart = Sparepart::firstOrCreate(['code' => $code], ['name' => 'Sparepart ' . $code]);

        return SparepartBranch::firstOrCreate(
            ['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id],
            ['selling_price' => $price]
        );
    }

    protected function makeSupplier(?string $code = null, array $overrides = []): Supplier
    {
        $this->procurementSeq++;
        $code = $code ?: 'SUP-' . $this->procurementSeq;

        return Supplier::create(array_merge(['code' => $code, 'name' => 'Supplier ' . $code], $overrides));
    }

    protected function makeUser(bool $active = true): User
    {
        return User::factory()->create(['is_active' => $active]);
    }

    /**
     * @param array $lines each: ['sparepart_branch_id' => int, 'qty' => n, 'supplier_id' => ?int, 'unit_price' => ?n]
     */
    protected function makePurchaseRequest(Branch $branch, array $lines, array $overrides = []): PurchaseRequest
    {
        $this->procurementSeq++;
        $request = PurchaseRequest::create(array_merge([
            'number' => 'RQ/' . $branch->code . '/202611/' . str_pad((string) $this->procurementSeq, 5, '0', STR_PAD_LEFT),
            'branch_id' => $branch->id,
            'request_date' => '2026-11-13',
        ], $overrides));

        foreach (array_values($lines) as $index => $line) {
            $qty = (float) $line['qty'];
            $price = array_key_exists('unit_price', $line) ? $line['unit_price'] : null;
            $request->lines()->create([
                'sparepart_branch_id' => $line['sparepart_branch_id'],
                'qty' => $qty,
                'supplier_id' => $line['supplier_id'] ?? null,
                'unit_price' => $price,
                'line_total' => $price === null ? null : round($qty * (float) $price, 2),
                'sort_order' => $index,
            ]);
        }

        return $request->fresh('lines');
    }

    /** Wipe the migration-seeded Manager/Direktur/CEO levels and rules so a test controls its own config. */
    protected function resetApprovalConfig(): void
    {
        DB::table('approval_rule_levels')->delete();
        DB::table('approval_rules')->delete();
        DB::table('approval_level_users')->delete();
        DB::table('approval_levels')->delete();
    }

    protected function makeApprovalLevel(string $name, int $sortOrder, array $users = [], bool $active = true): ApprovalLevel
    {
        $level = ApprovalLevel::create(['name' => $name, 'sort_order' => $sortOrder, 'is_active' => $active]);
        $level->users()->sync(array_map(function ($user) {
            return $user->id;
        }, $users));

        return $level;
    }

    protected function makeApprovalRule(?float $maxAmount, array $levels, int $sortOrder): ApprovalRule
    {
        $rule = ApprovalRule::create(['max_amount' => $maxAmount, 'sort_order' => $sortOrder]);
        $rule->levels()->sync(array_map(function ($level) {
            return $level->id;
        }, $levels));

        return $rule;
    }
}
