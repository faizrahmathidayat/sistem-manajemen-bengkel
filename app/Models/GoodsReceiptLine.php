<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoodsReceiptLine extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'goods_receipt_id', 'sparepart_branch_id', 'qty', 'purchase_price', 'line_total', 'sort_order',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'purchase_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function goodsReceipt()
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function sparepartBranch()
    {
        return $this->belongsTo(SparepartBranch::class);
    }
}
