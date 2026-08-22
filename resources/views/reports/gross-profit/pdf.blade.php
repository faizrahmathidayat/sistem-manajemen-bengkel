@extends('layouts.print')
@section('title', 'Laporan Laba Rugi')
@section('table')
    <p style="margin-bottom: 8px;"><strong>{{ $filterSummary }}</strong></p>
    @if ($truncated)
        <p style="color: red;">Data melebihi 1.000 baris, hanya 1.000 baris pertama yang ditampilkan.</p>
    @endif

    @if ($viewType === 'invoice_detail')
        <table class="print-table">
            <thead>
                <tr>
                    <th>No. Invoice</th><th>Tanggal</th><th>Cabang</th><th>Customer</th>
                    <th>Pendapatan Jasa</th><th>Pendapatan Sparepart</th><th>Total HPP</th><th>Laba Kotor</th><th>Margin %</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoices as $invoice)
                    @php
                        $pendapatan = (float) $invoice->pendapatan_jasa + (float) $invoice->pendapatan_sparepart;
                        $labaKotor = $pendapatan - (float) $invoice->total_hpp;
                        $margin = $pendapatan > 0 ? ($labaKotor / $pendapatan) * 100 : 0;
                    @endphp
                    <tr>
                        <td>{{ $invoice->number }}</td>
                        <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                        <td>{{ $invoice->branch->name }}</td>
                        <td>{{ $invoice->customer->name }}</td>
                        <td>{{ number_format($invoice->pendapatan_jasa, 0, ',', '.') }}</td>
                        <td>{{ number_format($invoice->pendapatan_sparepart, 0, ',', '.') }}</td>
                        <td>{{ number_format($invoice->total_hpp, 0, ',', '.') }}</td>
                        <td>{{ number_format($labaKotor, 0, ',', '.') }}</td>
                        <td>{{ number_format($margin, 1, ',', '.') }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <table class="print-table">
            <thead>
                <tr>
                    <th>Periode</th><th>Cabang</th>
                    <th>Pendapatan Jasa</th><th>Pendapatan Sparepart</th><th>Total HPP</th><th>Laba Kotor</th><th>Margin %</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($summaryRows as $row)
                    @php
                        $pendapatan = (float) $row->pendapatan_jasa + (float) $row->pendapatan_sparepart;
                        $labaKotor = $pendapatan - (float) $row->total_hpp;
                        $margin = $pendapatan > 0 ? ($labaKotor / $pendapatan) * 100 : 0;
                        $branchName = optional($branches->firstWhere('id', $row->branch_id))->name ?? '-';
                    @endphp
                    <tr>
                        <td>{{ $row->period }}</td>
                        <td>{{ $branchName }}</td>
                        <td>{{ number_format($row->pendapatan_jasa, 0, ',', '.') }}</td>
                        <td>{{ number_format($row->pendapatan_sparepart, 0, ',', '.') }}</td>
                        <td>{{ number_format($row->total_hpp, 0, ',', '.') }}</td>
                        <td>{{ number_format($labaKotor, 0, ',', '.') }}</td>
                        <td>{{ number_format($margin, 1, ',', '.') }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
