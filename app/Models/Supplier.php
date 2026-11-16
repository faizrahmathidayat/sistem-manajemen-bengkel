<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'code', 'name', 'contact_person', 'phone', 'address', 'account_number', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getLabelAttribute(): string
    {
        return $this->code . ' — ' . $this->name;
    }
}
