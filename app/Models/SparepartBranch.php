<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SparepartBranch extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = ['sparepart_id', 'branch_id', 'rack_number', 'rack_id', 'selling_price', 'minimum_stock', 'is_active'];

    protected $casts = [
        'selling_price' => 'decimal:2',
        'minimum_stock' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected static function booted()
    {
        static::created(function (SparepartBranch $sparepartBranch) {
            SparepartBranchStock::create([
                'sparepart_branch_id' => $sparepartBranch->id,
                'on_hand_qty' => 0,
                'reserved_qty' => 0,
            ]);
        });
    }

    public function sparepart()
    {
        return $this->belongsTo(Sparepart::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function stock()
    {
        return $this->hasOne(SparepartBranchStock::class);
    }

    public function rack()
    {
        return $this->belongsTo(Rack::class);
    }
}
