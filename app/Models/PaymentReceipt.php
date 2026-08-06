<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use App\Support\PaymentReceiptStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentReceipt extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'number', 'branch_id', 'customer_id', 'payment_date', 'payment_method',
        'reference_number', 'amount', 'status', 'notes',
        'voided_at', 'voided_by', 'void_reason',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
        'voided_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => PaymentReceiptStatus::POSTED,
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function voidedBy()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
