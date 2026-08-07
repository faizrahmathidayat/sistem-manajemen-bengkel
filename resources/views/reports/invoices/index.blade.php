@extends('layouts.app')
@section('title', 'Laporan Invoice')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-file-earmark-text me-2"></i>Laporan Invoice</h1>
    </div>
    @foreach ($invoices as $invoice)
        <div>
            {{ $invoice->number }}
            {{ number_format($invoice->subtotal_service, 0, ',', '.') }}
            {{ number_format($invoice->subtotal_sparepart, 0, ',', '.') }}
            {{ number_format($invoice->grand_total, 0, ',', '.') }}
        </div>
    @endforeach
@endsection
