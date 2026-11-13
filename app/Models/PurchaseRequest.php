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
        'cancel_reason', 'cancelled_at', 'cancelled_by',
    ];

    protected $casts = [
        'request_date' => 'date',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
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

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function lines()
    {
        return $this->hasMany(PurchaseRequestLine::class)->orderBy('sort_order');
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function approvals()
    {
        return $this->hasMany(PurchaseRequestApproval::class)->orderBy('step_no');
    }

    /**
     * Where the request stands, as one line of text for lists and reports. Needs `approvals` loaded.
     */
    public function positionLabel(): string
    {
        $approvals = $this->approvals;

        switch ($this->status) {
            case PurchaseRequestStatus::WAITING_SUPPLIER:
                return 'Menunggu penentuan supplier';
            case PurchaseRequestStatus::WAITING_APPROVAL:
                $pending = $approvals->firstWhere('status', 'pending');

                return $pending
                    ? sprintf('Menunggu %s (%d/%d)', $pending->level_name, $pending->step_no, $approvals->count())
                    : 'Menunggu approval';
            case PurchaseRequestStatus::APPROVED:
                $last = $approvals->sortBy('step_no')->last();

                return 'Disetujui' . ($last && $last->acted_at ? ' ' . $last->level_name . ' ' . $last->acted_at->format('d/m') : '');
            case PurchaseRequestStatus::REJECTED:
                $rejected = $approvals->firstWhere('status', 'rejected');

                return 'Ditolak' . ($rejected ? ' ' . $rejected->level_name . ($rejected->notes ? ' — ' . $rejected->notes : '') : '');
            case PurchaseRequestStatus::CANCELLED:
                return 'Dibatalkan';
        }

        return '-';
    }

    /**
     * Whole days from submission to the last decision (or until now while still running);
     * null when the request was never submitted for approval. Needs `approvals` loaded.
     */
    public function processDays(): ?int
    {
        if (! $this->submitted_at) {
            return null;
        }

        $end = now();
        if ($this->status !== PurchaseRequestStatus::WAITING_APPROVAL) {
            $lastDecision = $this->approvals->pluck('acted_at')->filter()->max();
            if ($lastDecision) {
                $end = $lastDecision;
            }
        }

        return (int) $this->submitted_at->diffInDays($end);
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
