@extends('layouts.app')
@section('title', 'Laporan Piutang')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-file-earmark-minus me-2"></i>Laporan Piutang</h1>
    </div>
    <table class="table">
        <tbody>
            @foreach ($invoices as $invoice)
                <tr>
                    <td>{{ $invoice->number }}</td>
                    <td>{{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
