<?php

namespace App\Policies;

use App\Models\GoodsReceipt;
use App\Models\User;
use App\Support\GoodsReceiptStatus;

class GoodsReceiptPolicy
{
    public function view(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $user->hasPermissionToInBranch('receipt.view', $goodsReceipt->branch_id);
    }

    public function update(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $goodsReceipt->status === GoodsReceiptStatus::DRAFT
            && $user->hasPermissionToInBranch('receipt.create', $goodsReceipt->branch_id);
    }

    public function post(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $goodsReceipt->status === GoodsReceiptStatus::DRAFT
            && $user->hasPermissionToInBranch('receipt.post', $goodsReceipt->branch_id);
    }

    public function cancel(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $goodsReceipt->status === GoodsReceiptStatus::DRAFT
            && $user->hasPermissionToInBranch('receipt.cancel', $goodsReceipt->branch_id);
    }
}
