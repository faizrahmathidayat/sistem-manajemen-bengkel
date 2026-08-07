@extends('layouts.print')
@section('report-title', 'Laporan Piutang')
@section('filter-summary', $filterSummary)
@section('note')
    @if ($truncated)
        <p class="print-note">Data melebihi 1.000 baris, ditampilkan sebagian. Gunakan Export Excel untuk data lengkap.</p>
    @endif
@endsection
@section('table')
    <table class="print-table">
        <thead>
            <tr>
                <th>No. Invoice</th>
                <th>Tanggal</th>
                <th>Customer</th>
                <th>Cabang</th>
                <th>Grand Total</th>
                <th>Sudah Dibayar</th>
                <th>Sisa Piutang</th>
                <th>Jatuh Tempo</th>
                <th>Umur Piutang</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoices as $invoice)
                <tr>
                    <td>{{ $invoice->number }}</td>
                    <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                    <td>{{ $invoice->customer->name }}</td>
                    <td>{{ $invoice->branch->name }}</td>
                    <td>{{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                    <td>{{ number_format($invoice->paid_amount, 0, ',', '.') }}</td>
                    <td>{{ number_format($invoice->grand_total - $invoice->paid_amount, 0, ',', '.') }}</td>
                    <td>{{ $invoice->due_date ? $invoice->due_date->format('d/m/Y') : '-' }}</td>
                    <td>{{ $invoice->aging_label }}</td>
                    <td>{{ $invoice->status }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
