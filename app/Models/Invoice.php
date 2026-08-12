<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use App\Support\InvoiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'number', 'hash_id', 'pin', 'work_order_id', 'branch_id', 'customer_id', 'invoice_date', 'due_date', 'status',
        'subtotal_service', 'subtotal_sparepart',
        'discount_percent', 'discount_amount',
        'tax_percent', 'tax_amount',
        'grand_total', 'paid_amount', 'notes',
        'cancel_reason', 'cancelled_by', 'cancelled_at',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'subtotal_service' => 'decimal:2',
        'subtotal_sparepart' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_percent' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'cancelled_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => InvoiceStatus::DRAFT,
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function details()
    {
        return $this->hasMany(InvoiceDetail::class)->orderBy('sort_order');
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function getOutstandingAmountAttribute()
    {
        return round((float) $this->grand_total - (float) $this->paid_amount, 2);
    }

    public function getIsDirectSaleAttribute(): bool
    {
        return is_null($this->work_order_id);
    }
}
