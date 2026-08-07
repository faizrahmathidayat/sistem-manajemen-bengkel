@extends('layouts.app')
@section('title', 'Laporan Gap Invoice vs PKB')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-bar-chart-steps me-2"></i>PKB vs Invoice</h1>
    </div>
    @foreach ($invoices as $invoice)
        <div>
            {{ $invoice->workOrder->number }}
            {{ $invoice->number }}
            {{ number_format($invoice->pkb_total, 0, ',', '.') }}
            {{ number_format($invoice->grand_total, 0, ',', '.') }}
        </div>
    @endforeach
@endsection
