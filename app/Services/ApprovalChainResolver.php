<?php

namespace App\Services;

use App\Exceptions\ApprovalChainException;
use App\Models\ApprovalRule;

class ApprovalChainResolver
{
    /**
     * Build the ordered approval chain for a request total.
     *
     * The rule is the one with the smallest inclusive max_amount that is >= $total; when the
     * total exceeds every bound, the unbounded (NULL) rule applies. Levels follow
     * approval_levels.sort_order. Every level must have at least one active approver.
     *
     * @return array<int, array{level_id:int, level_name:string, user_ids:int[]}>
     *
     * @throws ApprovalChainException
     */
    public function resolve(float $total): array
    {
        $total = round($total, 2);
        if ($total <= 0) {
            throw new ApprovalChainException('Total permintaan harus lebih dari 0 untuk menentukan rantai approval.');
        }

        $rules = ApprovalRule::with(['levels' => function ($query) {
            $query->where('approval_levels.is_active', true)->orderBy('approval_levels.sort_order');
        }, 'levels.users' => function ($query) {
            $query->where('users.is_active', true);
        }])->get();

        if ($rules->isEmpty()) {
            throw new ApprovalChainException('Aturan approval belum diatur. Hubungi admin.');
        }

        $rule = $rules
            ->filter(fn (ApprovalRule $r) => $r->max_amount !== null && $total <= round((float) $r->max_amount, 2))
            ->sortBy(fn (ApprovalRule $r) => (float) $r->max_amount)
            ->first();

        if ($rule === null) {
            $rule = $rules->first(fn (ApprovalRule $r) => $r->max_amount === null);
        }

        if ($rule === null) {
            throw new ApprovalChainException('Tidak ada aturan approval untuk nominal ini. Hubungi admin.');
        }

        $levels = $rule->levels;
        if ($levels->isEmpty()) {
            throw new ApprovalChainException('Aturan approval untuk nominal ini tidak memiliki level aktif. Hubungi admin.');
        }

        $steps = [];
        foreach ($levels as $level) {
            $userIds = $level->users->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            if (empty($userIds)) {
                throw new ApprovalChainException("Approver level {$level->name} belum diatur.");
            }

            $steps[] = [
                'level_id' => (int) $level->id,
                'level_name' => $level->name,
                'user_ids' => $userIds,
                'can_select_items' => (bool) $level->can_select_items,
            ];
        }

        return $steps;
    }
}
