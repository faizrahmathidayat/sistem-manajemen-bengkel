<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'customer_type', 'name', 'stnk_name', 'address', 'phone', 'email', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function customerBranches()
    {
        return $this->hasMany(CustomerBranch::class);
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'customer_branches')
            ->wherePivot('is_active', true);
    }

    public function hasAccessToBranch(int $branchId): bool
    {
        return $this->customerBranches()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->exists();
    }

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class);
    }
}
