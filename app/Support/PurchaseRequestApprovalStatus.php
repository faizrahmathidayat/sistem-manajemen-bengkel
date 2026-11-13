<?php

namespace App\Support;

class PurchaseRequestApprovalStatus
{
    const PENDING = 'pending';
    const APPROVED = 'approved';
    const REJECTED = 'rejected';
    /** Step that never got its turn because an earlier step rejected the request. */
    const VOID = 'void';

    const LABELS = [
        self::PENDING => 'Menunggu',
        self::APPROVED => 'Disetujui',
        self::REJECTED => 'Ditolak',
        self::VOID => 'Tidak diproses',
    ];
}
