<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VehicleType extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = ['brand_id', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected $attributes = ['is_active' => true];

    public function brand()
    {
        return $this->belongsTo(VehicleBrand::class, 'brand_id');
    }
}
