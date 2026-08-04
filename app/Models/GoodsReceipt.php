<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use App\Support\GoodsReceiptStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoodsReceipt extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'number', 'branch_id', 'receipt_date', 'reference_number', 'status', 'notes',
    ];

    protected $casts = [
        'receipt_date' => 'date',
    ];

    protected $attributes = [
        'status' => GoodsReceiptStatus::DRAFT,
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines()
    {
        return $this->hasMany(GoodsReceiptLine::class)->orderBy('sort_order');
    }
}
