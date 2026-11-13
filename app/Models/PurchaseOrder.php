<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use App\Support\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'number', 'purchase_request_id', 'supplier_id', 'branch_id', 'order_date', 'status',
        'total_amount', 'notes', 'issued_at', 'issued_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'issued_at' => 'datetime',
        'total_amount' => 'decimal:2',
    ];

    protected $attributes = [
        'status' => PurchaseOrderStatus::DRAFT,
    ];

    public function purchaseRequest()
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function lines()
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('sort_order');
    }

    public function goodsReceipts()
    {
        return $this->hasMany(GoodsReceipt::class);
    }
}
