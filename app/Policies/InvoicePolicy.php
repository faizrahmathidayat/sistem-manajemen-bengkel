<?php

namespace App\Policies;

use App\Models\Branch;
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

    public function createDirect(User $user, Branch $branch): bool
    {
        return $user->hasPermissionToInBranch('invoice.create', $branch->id);
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

    public function cancel(User $user, Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::DRAFT
            && $user->hasPermissionToInBranch('invoice.void', $invoice->branch_id);
    }

    public function print(User $user, Invoice $invoice): bool
    {
        return in_array($invoice->status, [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::PAID], true)
            && $user->hasPermissionToInBranch('invoice.print', $invoice->branch_id);
    }

    public function sendEmail(User $user, Invoice $invoice): bool
    {
        return in_array($invoice->status, [InvoiceStatus::POSTED, InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::PAID], true)
            && $user->hasPermissionToInBranch('invoice.email', $invoice->branch_id);
    }
}
