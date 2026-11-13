<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseRequestLine extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'purchase_request_id', 'sparepart_branch_id', 'qty', 'supplier_id', 'unit_price', 'line_total', 'sort_order',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function purchaseRequest()
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function sparepartBranch()
    {
        return $this->belongsTo(SparepartBranch::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
