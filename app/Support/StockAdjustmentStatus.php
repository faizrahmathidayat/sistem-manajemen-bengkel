<?php

namespace App\Support;

class StockAdjustmentStatus
{
    const DRAFT = 'draft';
    const PENDING_APPROVAL = 'pending_approval';
    const APPROVED = 'approved';
    const POSTED = 'posted';
    const CANCELLED = 'cancelled';
}
