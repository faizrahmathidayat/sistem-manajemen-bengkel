<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory, HasAudit, HasAttachments;

    protected $fillable = [
        'code', 'name', 'address', 'phone', 'email', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function userBranches()
    {
        return $this->hasMany(UserBranch::class);
    }
}
