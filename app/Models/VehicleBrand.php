<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehicleBrand extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = ['category_id', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected $attributes = ['is_active' => true];

    public function category()
    {
        return $this->belongsTo(VehicleCategory::class, 'category_id');
    }

    public function types()
    {
        return $this->hasMany(VehicleType::class, 'brand_id');
    }
}
