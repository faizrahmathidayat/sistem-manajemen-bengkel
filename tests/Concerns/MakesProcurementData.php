<?php

namespace Tests\Concerns;

use App\Models\ApprovalLevel;
use App\Models\ApprovalRule;
use App\Models\Branch;
use App\Models\PurchaseRequest;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseOrderGenerator;
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
            ['selling_price' => $price, 'minimum_stock' => 10]
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

    /**
     * Replace the seeded config with Manager(1) / Direktur(2) / CEO(3), one fresh active user each,
     * and the 10 jt / 50 jt / unbounded rules. Returns the three levels (users via ->users).
     */
    protected function makeStandardApprovalConfig(): array
    {
        $this->resetApprovalConfig();
        $manager = $this->makeApprovalLevel('Manager', 1, [$this->makeUser()]);
        $director = $this->makeApprovalLevel('Direktur', 2, [$this->makeUser()]);
        $ceo = $this->makeApprovalLevel('CEO', 3, [$this->makeUser()]);
        $this->makeApprovalRule(10000000, [$manager], 1);
        $this->makeApprovalRule(50000000, [$manager, $director], 2);
        $this->makeApprovalRule(null, [$manager, $director, $ceo], 3);

        return [$manager->load('users'), $director->load('users'), $ceo->load('users')];
    }

    /** A request whose single line is fully assigned so that its total equals $total exactly. */
    protected function makeAssignedRequest(Branch $branch, int $total, array $overrides = []): PurchaseRequest
    {
        $supplier = $this->makeSupplier();

        return $this->makePurchaseRequest($branch, [[
            'sparepart_branch_id' => $this->makeSparepartBranch($branch)->id,
            'qty' => 1,
            'supplier_id' => $supplier->id,
            'unit_price' => $total,
        ]], $overrides);
    }

    /** A PO with two lines (120 @ 85.000 and 60 @ 45.000) in the given status. */
    protected function makeIssuedPurchaseOrder(Branch $branch, string $status = 'issued', ?Supplier $supplier = null): PurchaseOrder
    {
        $supplier = $supplier ?: $this->makeSupplier();
        $request = $this->makePurchaseRequest($branch, [
            ['sparepart_branch_id' => $this->makeSparepartBranch($branch)->id, 'qty' => 120, 'supplier_id' => $supplier->id, 'unit_price' => 85000],
            ['sparepart_branch_id' => $this->makeSparepartBranch($branch)->id, 'qty' => 60, 'supplier_id' => $supplier->id, 'unit_price' => 45000],
        ]);
        $order = (new PurchaseOrderGenerator())->generateFor($request)->first();
        $order->update(['status' => $status]);

        return $order->fresh(['lines', 'supplier', 'branch']);
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
