@extends('layouts.print')
@section('report-title', 'Laporan Performance Bengkel')
@section('filter-summary', $filterSummary)
@section('note')
    @if ($truncated)
        <p class="print-note">Data melebihi 1.000 baris, ditampilkan sebagian. Gunakan Export Excel untuk data lengkap.</p>
    @endif
@endsection
@section('table')
    @if ($viewType === 'mechanic')
        <table class="print-table">
            <thead>
                <tr>
                    <th>Mekanik</th><th>Total Customer</th><th>Total Qty Jasa</th><th>Total Discount Jasa (Rp)</th><th>Subtotal Jasa</th>
                    <th>Total Qty Sparepart</th><th>Total Discount Sparepart (Rp)</th><th>Subtotal Sparepart</th><th>Grand Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($mechanicRows as $row)
                    @php
                        $mechanicLabel = $row->mechanic_nip ? "{$row->mechanic_nip} - {$row->mechanic_name}" : $row->mechanic_name;
                        $grandTotal = (float) $row->subtotal_jasa + (float) $row->subtotal_sparepart;
                    @endphp
                    <tr>
                        <td>{{ $mechanicLabel }}</td>
                        <td>{{ number_format($row->total_customer, 0, ',', '.') }}</td>
                        <td>{{ number_format($row->total_qty_jasa, 0, ',', '.') }}</td>
                        <td>{{ number_format($row->total_discount_jasa, 0, ',', '.') }}</td>
                        <td>{{ number_format($row->subtotal_jasa, 0, ',', '.') }}</td>
                        <td>{{ number_format($row->total_qty_sparepart, 0, ',', '.') }}</td>
                        <td>{{ number_format($row->total_discount_sparepart, 0, ',', '.') }}</td>
                        <td>{{ number_format($row->subtotal_sparepart, 0, ',', '.') }}</td>
                        <td>{{ number_format($grandTotal, 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        @foreach ($invoices as $invoice)
            @php
                $mechanicLabel = optional(optional($invoice->workOrder)->mechanic)->display_label ?? '-';
                $pairs = \App\Support\WorkshopPerformanceLinePairer::build($invoice);
                $totalJasa = collect($pairs)->sum('jasa_subtotal');
                $totalSparepart = collect($pairs)->sum('sparepart_subtotal');
                $totalLine = $totalJasa + $totalSparepart;
            @endphp
            <table class="print-table" style="margin-bottom: 4px;">
                <tbody>
                    <tr>
                        <td><strong>No. Invoice:</strong> {{ $invoice->number }}</td>
                        <td><strong>Tanggal:</strong> {{ $invoice->invoice_date->format('d/m/Y') }}</td>
                        <td><strong>Status:</strong> {{ $invoice->status }}</td>
                        <td><strong>Customer:</strong> {{ $invoice->customer->name }}</td>
                        <td><strong>Mekanik:</strong> {{ $mechanicLabel }}</td>
                        <td><strong>Cabang:</strong> {{ $invoice->branch->name }}</td>
                    </tr>
                </tbody>
            </table>
            <table class="print-table" style="margin-bottom: 12px;">
                <thead>
                    <tr>
                        <th>Jasa</th><th>Harga Satuan Jasa</th><th>Qty</th><th>Diskon (%)</th><th>Subtotal Jasa</th>
                        <th>Sparepart</th><th>Harga Satuan Sparepart</th><th>Qty</th><th>Diskon (%)</th><th>Subtotal Sparepart</th>
                        <th>Subtotal Line</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($pairs as $pair)
                        <tr>
                            <td>{{ $pair['jasa_desc'] }}</td>
                            <td>{{ number_format($pair['jasa_price'], 0, ',', '.') }}</td>
                            <td>{{ number_format($pair['jasa_qty'], 0, ',', '.') }}</td>
                            <td>{{ number_format($pair['jasa_discount_percent'], 0, ',', '.') }}</td>
                            <td>{{ number_format($pair['jasa_subtotal'], 0, ',', '.') }}</td>
                            <td>{{ $pair['sparepart_desc'] }}</td>
                            <td>{{ number_format($pair['sparepart_price'], 0, ',', '.') }}</td>
                            <td>{{ number_format($pair['sparepart_qty'], 0, ',', '.') }}</td>
                            <td>{{ number_format($pair['sparepart_discount_percent'], 0, ',', '.') }}</td>
                            <td>{{ number_format($pair['sparepart_subtotal'], 0, ',', '.') }}</td>
                            <td>{{ number_format($pair['subtotal_line'], 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="11">&mdash;</td></tr>
                    @endforelse
                    <tr>
                        <td colspan="4"><strong>Total</strong></td>
                        <td><strong>{{ number_format($totalJasa, 0, ',', '.') }}</strong></td>
                        <td colspan="4"></td>
                        <td><strong>{{ number_format($totalSparepart, 0, ',', '.') }}</strong></td>
                        <td><strong>{{ number_format($totalLine, 0, ',', '.') }}</strong></td>
                    </tr>
                </tbody>
            </table>
        @endforeach
    @endif
@endsection
