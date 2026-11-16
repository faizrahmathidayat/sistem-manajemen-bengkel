<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseRequestApproval extends Model
{
    protected $fillable = [
        'purchase_request_id', 'step_no', 'approval_level_id', 'level_name',
        'status', 'can_select_items', 'acted_by', 'acted_at', 'notes',
    ];

    protected $casts = [
        'acted_at' => 'datetime',
        'step_no' => 'integer',
        'can_select_items' => 'boolean',
    ];

    public function purchaseRequest()
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function approvalLevel()
    {
        return $this->belongsTo(ApprovalLevel::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'acted_by');
    }

    /** Snapshot of the users who may decide this step (copied from the level at submit time). */
    public function users()
    {
        return $this->belongsToMany(User::class, 'purchase_request_approval_users', 'purchase_request_approval_id', 'user_id')->withTimestamps();
    }
}
