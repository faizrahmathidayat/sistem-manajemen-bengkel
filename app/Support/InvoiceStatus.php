<?php

namespace App\Support;

class InvoiceStatus
{
    const DRAFT = 'draft';
    const POSTED = 'posted';
    const CANCELLED = 'cancelled';
    const PARTIALLY_PAID = 'partially_paid';
    const PAID = 'paid';
}
