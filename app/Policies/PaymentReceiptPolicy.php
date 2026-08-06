<?php

namespace App\Policies;

use App\Models\PaymentReceipt;
use App\Models\User;
use App\Support\PaymentReceiptStatus;

class PaymentReceiptPolicy
{
    public function view(User $user, PaymentReceipt $paymentReceipt): bool
    {
        return $user->hasPermissionToInBranch('payment.view', $paymentReceipt->branch_id);
    }

    public function void(User $user, PaymentReceipt $paymentReceipt): bool
    {
        return $paymentReceipt->status === PaymentReceiptStatus::POSTED
            && $user->hasPermissionToInBranch('payment.void', $paymentReceipt->branch_id);
    }
}
