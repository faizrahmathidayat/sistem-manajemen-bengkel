<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use App\Support\PurchaseRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseRequest extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'number', 'branch_id', 'request_date', 'status', 'notes', 'total_amount',
        'submitted_at', 'submitted_by', 'rejected_reason',
    ];

    protected $casts = [
        'request_date' => 'date',
        'submitted_at' => 'datetime',
        'total_amount' => 'decimal:2',
    ];

    protected $attributes = [
        'status' => PurchaseRequestStatus::WAITING_SUPPLIER,
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function lines()
    {
        return $this->hasMany(PurchaseRequestLine::class)->orderBy('sort_order');
    }

    public function approvals()
    {
        return $this->hasMany(PurchaseRequestApproval::class)->orderBy('step_no');
    }

    /**
     * True when every line has a supplier and a unit price above zero.
     */
    public function isAssignmentComplete(): bool
    {
        $lines = $this->lines;
        if ($lines->isEmpty()) {
            return false;
        }

        return $lines->every(fn (PurchaseRequestLine $line) => $line->supplier_id !== null
            && $line->unit_price !== null
            && (float) $line->unit_price > 0);
    }

    public function computeTotal(): float
    {
        return (float) $this->lines->sum(
            fn (PurchaseRequestLine $line) => round((float) $line->qty * (float) $line->unit_price, 2)
        );
    }
}
