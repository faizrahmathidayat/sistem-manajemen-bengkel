<?php

namespace App\Support;

class PurchaseRequestStatus
{
    const WAITING_SUPPLIER = 'waiting_supplier';
    const WAITING_APPROVAL = 'waiting_approval';
    const APPROVED = 'approved';
    const REJECTED = 'rejected';
    const CANCELLED = 'cancelled';

    const LABELS = [
        self::WAITING_SUPPLIER => 'Menunggu Supplier',
        self::WAITING_APPROVAL => 'Menunggu Approval',
        self::APPROVED => 'Disetujui',
        self::REJECTED => 'Ditolak',
        self::CANCELLED => 'Dibatalkan',
    ];
}
