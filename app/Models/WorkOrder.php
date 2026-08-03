<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use App\Support\WorkOrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkOrder extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'number', 'branch_id', 'customer_id', 'vehicle_id', 'mechanic_id',
        'work_order_date', 'odometer_km', 'status', 'notes',
    ];

    protected $casts = [
        'work_order_date' => 'date',
        'odometer_km' => 'decimal:1',
    ];

    protected $attributes = [
        'status' => WorkOrderStatus::DRAFT,
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function mechanic()
    {
        return $this->belongsTo(Mechanic::class);
    }

    public function serviceLines()
    {
        return $this->hasMany(WorkOrderServiceLine::class)->orderBy('sort_order');
    }

    public function sparepartLines()
    {
        return $this->hasMany(WorkOrderSparepartLine::class)->orderBy('sort_order');
    }
}
