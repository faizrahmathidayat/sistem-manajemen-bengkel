<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkOrder;
use App\Support\WorkOrderStatus;

class WorkOrderPolicy
{
    public function view(User $user, WorkOrder $workOrder): bool
    {
        return $user->hasPermissionToInBranch('pkb.view', $workOrder->branch_id);
    }

    public function update(User $user, WorkOrder $workOrder): bool
    {
        return $workOrder->status === WorkOrderStatus::DRAFT
            && $user->hasPermissionToInBranch('pkb.edit', $workOrder->branch_id);
    }

    public function cancel(User $user, WorkOrder $workOrder): bool
    {
        return in_array($workOrder->status, [WorkOrderStatus::DRAFT, WorkOrderStatus::OPEN, WorkOrderStatus::SHORTAGE], true)
            && $user->hasPermissionToInBranch('pkb.cancel', $workOrder->branch_id);
    }

    public function confirm(User $user, WorkOrder $workOrder): bool
    {
        return $workOrder->status === WorkOrderStatus::DRAFT
            && $user->hasPermissionToInBranch('pkb.confirm', $workOrder->branch_id);
    }

    public function overrideShortage(User $user, WorkOrder $workOrder): bool
    {
        return $workOrder->status === WorkOrderStatus::SHORTAGE
            && is_null($workOrder->shortage_overridden_at)
            && $user->hasPermissionToInBranch('pkb.override_stock_shortage', $workOrder->branch_id);
    }
}
