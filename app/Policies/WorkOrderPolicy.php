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
        return $workOrder->status === WorkOrderStatus::DRAFT
            && $user->hasPermissionToInBranch('pkb.cancel', $workOrder->branch_id);
    }
}
