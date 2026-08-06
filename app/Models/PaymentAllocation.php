<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentAllocation extends Model
{
    use HasFactory;

    protected $fillable = ['payment_receipt_id', 'invoice_id', 'allocated_amount'];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
    ];

    public function paymentReceipt()
    {
        return $this->belongsTo(PaymentReceipt::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
