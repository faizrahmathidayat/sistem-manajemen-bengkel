@extends('layouts.print')
@section('report-title', 'Laporan Gap Invoice vs PKB')
@section('filter-summary', $filterSummary)
@section('note')
    @if ($truncated)
        <p class="print-note">Data melebihi 1.000 baris, ditampilkan sebagian. Gunakan Export Excel untuk data lengkap.</p>
    @endif
@endsection
@section('table')
    <table class="print-table">
        @if ($mode === 'detail')
            <thead>
                <tr><th>No. PKB</th><th>No. Invoice</th><th>Tanggal</th><th>Customer</th><th>Tipe Item</th><th>Nama Item</th><th>Qty PKB</th><th>Harga PKB</th><th>Qty Invoice</th><th>Harga Invoice</th><th>Kategori</th></tr>
            </thead>
            <tbody>
                @foreach ($invoices as $invoice)
                    @php $categoryLabels = ['sesuai' => 'Sesuai', 'changed' => 'Berubah', 'removed' => 'Dihapus', 'added' => 'Ditambahkan']; @endphp
                    @forelse ($invoice->comparisonLines as $line)
                        <tr>
                            <td>{{ $invoice->workOrder->number }}</td><td>{{ $invoice->number }}</td><td>{{ $invoice->invoice_date->format('d/m/Y') }}</td><td>{{ $invoice->customer->name }}</td>
                            <td>{{ $line['item_type'] }}</td><td>{{ $line['item_name'] }}</td>
                            <td>{{ $line['pkb_qty'] !== null ? number_format($line['pkb_qty'], 0, ',', '.') : '—' }}</td>
                            <td>{{ $line['pkb_price'] !== null ? number_format($line['pkb_price'], 0, ',', '.') : '—' }}</td>
                            <td>{{ $line['invoice_qty'] !== null ? number_format($line['invoice_qty'], 0, ',', '.') : '—' }}</td>
                            <td>{{ $line['invoice_price'] !== null ? number_format($line['invoice_price'], 0, ',', '.') : '—' }}</td>
                            <td>{{ $categoryLabels[$line['category']] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td>{{ $invoice->workOrder->number }}</td><td>{{ $invoice->number }}</td><td>{{ $invoice->invoice_date->format('d/m/Y') }}</td><td>{{ $invoice->customer->name }}</td>
                            <td colspan="7">&mdash;</td>
                        </tr>
                    @endforelse
                @endforeach
            </tbody>
        @else
            <thead>
                <tr><th>No. PKB</th><th>No. Invoice</th><th>Tanggal</th><th>Customer</th><th>Total PKB</th><th>Total Invoice</th><th>Selisih (Rp)</th><th>Status Gap</th></tr>
            </thead>
            <tbody>
                @foreach ($invoices as $invoice)
                    @php
                        $pkbTotal = (float) $invoice->pkb_total;
                        $grandTotal = (float) $invoice->grand_total;
                        $selisih = $grandTotal - $pkbTotal;
                        $statusGap = $selisih == 0.0 ? 'Sesuai' : ($selisih > 0 ? 'Invoice > PKB' : 'Invoice < PKB');
                    @endphp
                    <tr>
                        <td>{{ $invoice->workOrder->number }}</td><td>{{ $invoice->number }}</td><td>{{ $invoice->invoice_date->format('d/m/Y') }}</td><td>{{ $invoice->customer->name }}</td>
                        <td>{{ number_format($pkbTotal, 0, ',', '.') }}</td><td>{{ number_format($grandTotal, 0, ',', '.') }}</td>
                        <td>{{ ($selisih >= 0 ? '+' : '') . number_format($selisih, 0, ',', '.') }}</td><td>{{ $statusGap }}</td>
                    </tr>
                @endforeach
            </tbody>
        @endif
    </table>
@endsection
