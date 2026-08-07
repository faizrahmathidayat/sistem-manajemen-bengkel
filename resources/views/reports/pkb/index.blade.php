@extends('layouts.app')
@section('title', 'Laporan PKB')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-file-earmark-bar-graph me-2"></i>Laporan PKB</h1>
    </div>
    @foreach ($workOrders as $workOrder)
        <div>
            {{ $workOrder->number }}
            {{ number_format($workOrder->subtotal_service ?? 0, 0, ',', '.') }}
            {{ number_format($workOrder->subtotal_sparepart ?? 0, 0, ',', '.') }}
            {{ number_format(($workOrder->subtotal_service ?? 0) + ($workOrder->subtotal_sparepart ?? 0), 0, ',', '.') }}
        </div>
    @endforeach
@endsection
