<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use App\Support\TransferStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockTransfer extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'number', 'from_branch_id', 'to_branch_id', 'transfer_date', 'status',
        'approved_by', 'approved_at', 'dispatched_by', 'dispatched_at', 'received_by', 'received_at', 'notes',
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'approved_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => TransferStatus::DRAFT,
    ];

    public function fromBranch()
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch()
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function dispatchedBy()
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function lines()
    {
        return $this->hasMany(StockTransferLine::class)->orderBy('sort_order');
    }
}
