<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Mechanic extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'name', 'phone', 'email', 'address', 'is_active', 'nip', 'join_date',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'join_date' => 'date',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function mechanicBranches()
    {
        return $this->hasMany(MechanicBranch::class);
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'mechanic_branches')
            ->wherePivot('is_active', true);
    }

    public function hasAccessToBranch(int $branchId): bool
    {
        return $this->mechanicBranches()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->exists();
    }

    public function getDisplayLabelAttribute(): string
    {
        return $this->nip ? "{$this->nip} - {$this->name}" : $this->name;
    }
}
