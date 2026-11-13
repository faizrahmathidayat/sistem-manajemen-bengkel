<?php

namespace App\Policies;

use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\PurchaseRequestService;
use App\Support\PurchaseRequestStatus;

class PurchaseRequestPolicy
{
    public function view(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $user->hasPermissionToInBranch('purchase_request.view', $purchaseRequest->branch_id)
            || $user->hasPermissionTo('purchase_request.view_all')
            || $user->hasPermissionTo('purchase_request.assign_supplier')
            || $user->hasPermissionTo('approval_setting.manage')
            || $this->isSnapshotApprover($user, $purchaseRequest);
    }

    public function update(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->isEditableByCreator($user, $purchaseRequest);
    }

    /**
     * Before submission the requester or the warehouse head may cancel (even after suppliers were
     * filled in); once it is in approval only the warehouse head may, with a reason. Approved,
     * rejected and cancelled requests are final (an approved one is undone via its purchase orders).
     */
    public function cancel(User $user, PurchaseRequest $purchaseRequest): bool
    {
        if ($purchaseRequest->status === PurchaseRequestStatus::WAITING_SUPPLIER) {
            return $user->hasPermissionTo('purchase_request.assign_supplier')
                || ((int) $purchaseRequest->created_by === (int) $user->id
                    && $user->hasPermissionToInBranch('purchase_request.create', $purchaseRequest->branch_id));
        }

        if ($purchaseRequest->status === PurchaseRequestStatus::WAITING_APPROVAL) {
            return $user->hasPermissionTo('purchase_request.assign_supplier');
        }

        return false;
    }

    public function assignSupplier(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $purchaseRequest->status === PurchaseRequestStatus::WAITING_SUPPLIER
            && $user->hasPermissionTo('purchase_request.assign_supplier');
    }

    public function submit(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->assignSupplier($user, $purchaseRequest);
    }

    public function approve(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return app(PurchaseRequestService::class)->canAct($purchaseRequest, $user);
    }

    public function reject(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $this->approve($user, $purchaseRequest);
    }

    public function reloadApprovers(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $purchaseRequest->status === PurchaseRequestStatus::WAITING_APPROVAL
            && $user->hasPermissionTo('approval_setting.manage');
    }

    protected function isSnapshotApprover(User $user, PurchaseRequest $purchaseRequest): bool
    {
        return $purchaseRequest->approvals()
            ->whereHas('users', function ($query) use ($user) {
                $query->where('users.id', $user->id);
            })->exists();
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
