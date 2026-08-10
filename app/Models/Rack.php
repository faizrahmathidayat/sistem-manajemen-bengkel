<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Rack extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = ['code', 'is_active', 'created_by', 'updated_by'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function sparepartBranches()
    {
        return $this->hasMany(SparepartBranch::class);
    }
}
