<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockTransferLine extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'stock_transfer_id', 'sparepart_id', 'qty', 'sort_order',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
    ];

    public function stockTransfer()
    {
        return $this->belongsTo(StockTransfer::class);
    }

    public function sparepart()
    {
        return $this->belongsTo(Sparepart::class);
    }
}
