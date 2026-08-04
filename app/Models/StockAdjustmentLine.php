<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockAdjustmentLine extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'stock_adjustment_id', 'sparepart_branch_id', 'system_qty', 'physical_qty', 'adjustment_qty', 'reason', 'sort_order',
    ];

    protected $casts = [
        'system_qty' => 'decimal:3',
        'physical_qty' => 'decimal:3',
        'adjustment_qty' => 'decimal:3',
    ];

    public function stockAdjustment()
    {
        return $this->belongsTo(StockAdjustment::class);
    }

    public function sparepartBranch()
    {
        return $this->belongsTo(SparepartBranch::class);
    }
}
