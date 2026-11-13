@php
    $statusClass = [
        \App\Support\PurchaseOrderStatus::DRAFT => 'status-inactive',
        \App\Support\PurchaseOrderStatus::ISSUED => 'status-warning',
        \App\Support\PurchaseOrderStatus::PARTIALLY_RECEIVED => 'status-warning',
        \App\Support\PurchaseOrderStatus::COMPLETED => 'status-active',
        \App\Support\PurchaseOrderStatus::CANCELLED => 'status-danger',
    ][$status] ?? 'status-inactive';
@endphp
<span class="status-dot {{ $statusClass }}">{{ \App\Support\PurchaseOrderStatus::LABELS[$status] ?? 'Status tidak dikenal' }}</span>
