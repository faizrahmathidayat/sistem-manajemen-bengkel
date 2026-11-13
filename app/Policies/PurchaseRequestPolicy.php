<?php

namespace App\Policies;

use App\Models\PurchaseRequest;
use App\Models\User;
use App\Support\PurchaseRequestStatus;

class PurchaseRequestPolicy
{
    public function view(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->hasPermissionToInBranch('purchase_request.view', $purchaseRequest->branch_id)
            || $user->hasPermissionTo('purchase_request.view_all')
            || $user->hasPermissionTo('purchase_request.assign_supplier')
            || $user->hasPermissionTo('approval_setting.manage');
    }

    public function update(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->isEditableByCreator($user, $purchaseRequest);
    }

    public function cancel(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->isEditableByCreator($user, $purchaseRequest);
    }

    public function assignSupplier(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $purchaseRequest->status === PurchaseRequestStatus::WAITING_SUPPLIER
            && $user->hasPermissionTo('purchase_request.assign_supplier');
    }

    /**
     * The requester may change or cancel their own request only until the warehouse head
     * starts filling in suppliers.
     */
    protected function isEditableByCreator(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $purchaseRequest->status === PurchaseRequestStatus::WAITING_SUPPLIER
            && (int) $purchaseRequest->created_by === (int) $user->id
            && $user->hasPermissionToInBranch('purchase_request.create', $purchaseRequest->branch_id)
            && ! $purchaseRequest->lines()->whereNotNull('supplier_id')->exists();
    }
}
