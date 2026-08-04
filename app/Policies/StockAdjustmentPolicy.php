<?php

namespace App\Policies;

use App\Models\StockAdjustment;
use App\Models\User;
use App\Support\StockAdjustmentStatus;

class StockAdjustmentPolicy
{
    public function view(User $user, StockAdjustment $stockAdjustment): bool
    {
        return $user->hasPermissionToInBranch('stock_adjustment.view', $stockAdjustment->branch_id);
    }

    public function update(User $user, StockAdjustment $stockAdjustment): bool
    {
        return $stockAdjustment->status === StockAdjustmentStatus::DRAFT
            && $user->hasPermissionToInBranch('stock_adjustment.create', $stockAdjustment->branch_id);
    }

    public function submit(User $user, StockAdjustment $stockAdjustment): bool
    {
        return $stockAdjustment->status === StockAdjustmentStatus::DRAFT
            && $user->hasPermissionToInBranch('stock_adjustment.create', $stockAdjustment->branch_id);
    }

    public function approve(User $user, StockAdjustment $stockAdjustment): bool
    {
        return $stockAdjustment->status === StockAdjustmentStatus::PENDING_APPROVAL
            && $user->hasPermissionToInBranch('stock_adjustment.approve', $stockAdjustment->branch_id);
    }

    public function post(User $user, StockAdjustment $stockAdjustment): bool
    {
        return $stockAdjustment->status === StockAdjustmentStatus::APPROVED
            && $user->hasPermissionToInBranch('stock_adjustment.post', $stockAdjustment->branch_id);
    }

    public function cancel(User $user, StockAdjustment $stockAdjustment): bool
    {
        return in_array($stockAdjustment->status, [
            StockAdjustmentStatus::DRAFT,
            StockAdjustmentStatus::PENDING_APPROVAL,
            StockAdjustmentStatus::APPROVED,
        ], true)
            && $user->hasPermissionToInBranch('stock_adjustment.cancel', $stockAdjustment->branch_id);
    }
}
