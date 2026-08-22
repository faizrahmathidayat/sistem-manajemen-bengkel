<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SparepartBranchStock extends Model
{
    protected $primaryKey = 'sparepart_branch_id';

    public $incrementing = false;

    protected $keyType = 'int';

    const CREATED_AT = null;

    protected $fillable = ['sparepart_branch_id', 'on_hand_qty', 'reserved_qty', 'average_cost'];

    protected $casts = [
        'on_hand_qty' => 'decimal:3',
        'reserved_qty' => 'decimal:3',
        'average_cost' => 'decimal:2',
    ];

    public function sparepartBranch()
    {
        return $this->belongsTo(SparepartBranch::class);
    }

    public function getAvailableQtyAttribute()
    {
        return $this->on_hand_qty - $this->reserved_qty;
    }
}
