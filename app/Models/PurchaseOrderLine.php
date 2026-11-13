<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderLine extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'purchase_order_id', 'purchase_request_line_id', 'sparepart_branch_id',
        'qty_ordered', 'unit_price', 'line_total', 'qty_received', 'sort_order',
    ];

    protected $casts = [
        'qty_ordered' => 'decimal:3',
        'qty_received' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseRequestLine()
    {
        return $this->belongsTo(PurchaseRequestLine::class);
    }

    public function sparepartBranch()
    {
        return $this->belongsTo(SparepartBranch::class);
    }

    public function remainingQty(): float
    {
        return round((float) $this->qty_ordered - (float) $this->qty_received, 3);
    }
}
