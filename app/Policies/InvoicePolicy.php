<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\InvoiceStatus;
use App\Support\WorkOrderStatus;

class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        return $user->hasPermissionToInBranch('invoice.view', $invoice->branch_id);
    }

    public function create(User $user, WorkOrder $workOrder): bool
    {
        return $workOrder->status === WorkOrderStatus::COMPLETED
            && $user->hasPermissionToInBranch('invoice.create', $workOrder->branch_id);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::DRAFT
            && $user->hasPermissionToInBranch('invoice.edit', $invoice->branch_id);
    }

    public function post(User $user, Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::DRAFT
            && $user->hasPermissionToInBranch('invoice.post', $invoice->branch_id);
    }
}
