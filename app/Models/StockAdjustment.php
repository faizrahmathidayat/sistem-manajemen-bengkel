<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use App\Support\StockAdjustmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'number', 'branch_id', 'adjustment_date', 'reason', 'status', 'approved_by', 'approved_at', 'notes',
    ];

    protected $casts = [
        'adjustment_date' => 'date',
        'approved_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => StockAdjustmentStatus::DRAFT,
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lines()
    {
        return $this->hasMany(StockAdjustmentLine::class)->orderBy('sort_order');
    }
}
