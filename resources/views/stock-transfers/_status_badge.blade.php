@if ($status === \App\Support\TransferStatus::DRAFT)
    <span class="status-dot status-active">Draft</span>
@elseif ($status === \App\Support\TransferStatus::APPROVED)
    <span class="status-dot status-active">Disetujui</span>
@elseif ($status === \App\Support\TransferStatus::DISPATCHED)
    <span class="status-dot status-active">Dikirim</span>
@elseif ($status === \App\Support\TransferStatus::RECEIVED)
    <span class="status-dot status-active">Diterima</span>
@elseif ($status === \App\Support\TransferStatus::CANCELLED)
    <span class="status-dot status-inactive">Dibatalkan</span>
@else
    <span class="status-dot status-inactive">Status tidak dikenal</span>
@endif
