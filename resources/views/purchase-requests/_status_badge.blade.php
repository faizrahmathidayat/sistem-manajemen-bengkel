@php
    $statusClass = [
        \App\Support\PurchaseRequestStatus::WAITING_SUPPLIER => 'status-warning',
        \App\Support\PurchaseRequestStatus::WAITING_APPROVAL => 'status-warning',
        \App\Support\PurchaseRequestStatus::APPROVED => 'status-active',
        \App\Support\PurchaseRequestStatus::REJECTED => 'status-danger',
        \App\Support\PurchaseRequestStatus::CANCELLED => 'status-inactive',
    ][$status] ?? 'status-inactive';
@endphp
<span class="status-dot {{ $statusClass }}">{{ \App\Support\PurchaseRequestStatus::LABELS[$status] ?? 'Status tidak dikenal' }}</span>
