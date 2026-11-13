<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApprovalRule extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = ['max_amount', 'sort_order'];

    protected $casts = [
        'max_amount' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function levels()
    {
        return $this->belongsToMany(ApprovalLevel::class, 'approval_rule_levels', 'approval_rule_id', 'approval_level_id')->withTimestamps();
    }
}
