<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\GoodsReceiptStatus;
use App\Support\PurchaseOrderStatus;

class PurchaseOrderPolicy
{
    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->hasPermissionTo('purchase_order.view');
    }

    public function print(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->view($user, $purchaseOrder);
    }

    public function issue(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $purchaseOrder->status === PurchaseOrderStatus::DRAFT
            && $user->hasPermissionTo('purchase_order.issue');
    }

    /**
     * Cancellable only before any goods were actually received (a posted receipt already
     * moved stock and cannot be unwound here).
     */
    public function cancel(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return in_array($purchaseOrder->status, [PurchaseOrderStatus::DRAFT, PurchaseOrderStatus::ISSUED], true)
            && $user->hasPermissionTo('purchase_order.cancel')
            && ! $purchaseOrder->goodsReceipts()->where('status', GoodsReceiptStatus::POSTED)->exists();
    }
}
