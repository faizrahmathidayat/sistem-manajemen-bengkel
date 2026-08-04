<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryReservation extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'branch_id', 'sparepart_branch_id', 'reservation_type', 'reference_type', 'reference_id', 'qty', 'status', 'created_by',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
    ];

    protected $attributes = [
        'status' => 'active',
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
