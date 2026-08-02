<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MechanicBranch extends Model
{
    use HasFactory;

    protected $fillable = ['mechanic_id', 'branch_id', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function mechanic()
    {
        return $this->belongsTo(Mechanic::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
