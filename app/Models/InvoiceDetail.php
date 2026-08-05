<?php

namespace App\Models;

use App\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceDetail extends Model
{
    use HasFactory, HasAudit;

    protected $fillable = [
        'invoice_id', 'item_type',
        'work_order_service_line_id', 'work_order_sparepart_line_id',
        'item_code_snapshot', 'description', 'qty', 'unit_price', 'line_total', 'sort_order',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function serviceLine()
    {
        return $this->belongsTo(WorkOrderServiceLine::class, 'work_order_service_line_id');
    }

    public function sparepartLine()
    {
        return $this->belongsTo(WorkOrderSparepartLine::class, 'work_order_sparepart_line_id');
    }
}
