<?php

namespace App\Policies;

use App\Models\StockTransfer;
use App\Models\User;
use App\Support\TransferStatus;

class StockTransferPolicy
{
    public function view(User $user, StockTransfer $stockTransfer): bool
    {
        return $user->hasPermissionToInBranch('stock_transfer.view', $stockTransfer->from_branch_id)
            || $user->hasPermissionToInBranch('stock_transfer.view', $stockTransfer->to_branch_id);
    }

    public function update(User $user, StockTransfer $stockTransfer): bool
    {
        return $stockTransfer->status === TransferStatus::DRAFT
            && $user->hasPermissionToInBranch('stock_transfer.create', $stockTransfer->from_branch_id);
    }

    public function approve(User $user, StockTransfer $stockTransfer): bool
    {
        return $stockTransfer->status === TransferStatus::DRAFT
            && $user->hasPermissionToInBranch('stock_transfer.approve', $stockTransfer->from_branch_id);
    }

    public function dispatch(User $user, StockTransfer $stockTransfer): bool
    {
        return $stockTransfer->status === TransferStatus::APPROVED
            && $user->hasPermissionToInBranch('stock_transfer.dispatch', $stockTransfer->from_branch_id);
    }

    public function receive(User $user, StockTransfer $stockTransfer): bool
    {
        return $stockTransfer->status === TransferStatus::DISPATCHED
            && $user->hasPermissionToInBranch('stock_transfer.receive', $stockTransfer->to_branch_id);
    }

    public function cancel(User $user, StockTransfer $stockTransfer): bool
    {
        return in_array($stockTransfer->status, [TransferStatus::DRAFT, TransferStatus::APPROVED], true)
            && $user->hasPermissionToInBranch('stock_transfer.cancel', $stockTransfer->from_branch_id);
    }
}
