<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehicleCategory extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = ['name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected $attributes = ['is_active' => true];

    public function brands()
    {
        return $this->hasMany(VehicleBrand::class, 'category_id');
    }
}
