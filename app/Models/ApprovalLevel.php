<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApprovalLevel extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = ['name', 'sort_order', 'is_active', 'can_select_items'];

    protected $casts = [
        'is_active' => 'boolean',
        'can_select_items' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'approval_level_users', 'approval_level_id', 'user_id')->withTimestamps();
    }

    public function rules()
    {
        return $this->belongsToMany(ApprovalRule::class, 'approval_rule_levels', 'approval_level_id', 'approval_rule_id')->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
