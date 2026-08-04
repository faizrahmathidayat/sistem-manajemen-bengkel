<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'movement_at', 'branch_id', 'sparepart_branch_id', 'movement_type',
        'qty_in', 'qty_out', 'balance_after', 'reference_type', 'reference_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'movement_at' => 'datetime',
        'qty_in' => 'decimal:3',
        'qty_out' => 'decimal:3',
        'balance_after' => 'decimal:3',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function sparepartBranch()
    {
        return $this->belongsTo(SparepartBranch::class);
    }
}
