@if ($status === \App\Support\StockAdjustmentStatus::DRAFT)
    <span class="status-dot status-active">Draft</span>
@elseif ($status === \App\Support\StockAdjustmentStatus::PENDING_APPROVAL)
    <span class="status-dot status-active">Diajukan</span>
@elseif ($status === \App\Support\StockAdjustmentStatus::APPROVED)
    <span class="status-dot status-active">Disetujui</span>
@elseif ($status === \App\Support\StockAdjustmentStatus::POSTED)
    <span class="status-dot status-active">Diposting</span>
@else
    <span class="status-dot status-inactive">Dibatalkan</span>
@endif
