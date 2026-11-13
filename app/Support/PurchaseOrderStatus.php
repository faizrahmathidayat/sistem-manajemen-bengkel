<?php

namespace App\Support;

class PurchaseOrderStatus
{
    const DRAFT = 'draft';
    const ISSUED = 'issued';
    const PARTIALLY_RECEIVED = 'partially_received';
    const COMPLETED = 'completed';
    const CANCELLED = 'cancelled';

    const LABELS = [
        self::DRAFT => 'Draft',
        self::ISSUED => 'Diterbitkan',
        self::PARTIALLY_RECEIVED => 'Diterima Sebagian',
        self::COMPLETED => 'Selesai',
        self::CANCELLED => 'Dibatalkan',
    ];

    /** Statuses in which goods may still be received against the order. */
    const RECEIVABLE = [self::ISSUED, self::PARTIALLY_RECEIVED];
}
