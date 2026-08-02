<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'customer_id', 'plate_number', 'frame_number', 'engine_number',
        'category_id', 'brand_id', 'type_id', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    protected $attributes = ['is_active' => true];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function category()
    {
        return $this->belongsTo(VehicleCategory::class, 'category_id');
    }

    public function brand()
    {
        return $this->belongsTo(VehicleBrand::class, 'brand_id');
    }

    public function type()
    {
        return $this->belongsTo(VehicleType::class, 'type_id');
    }
}
