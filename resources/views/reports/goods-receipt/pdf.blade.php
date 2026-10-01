@extends('layouts.print')
@section('report-title', 'Laporan Penerimaan Barang')
@section('filter-summary', $filterSummary)
@section('note')
    @if ($truncated)
        <p class="print-note">Data melebihi 1.000 baris, ditampilkan sebagian. Gunakan Export Excel untuk data lengkap.</p>
    @endif
@endsection
@section('table')
    @php $statusLabels = ['draft' => 'Draft', 'posted' => 'Diposting', 'cancelled' => 'Dibatalkan']; @endphp
    <table class="print-table">
        @if ($mode === 'detail')
            <thead>
                <tr><th>No. Dokumen</th><th>Tanggal</th><th>Cabang</th><th>Status</th><th>Kode</th><th>Nama Sparepart</th><th>Qty</th><th>Harga Beli</th><th>Total</th></tr>
            </thead>
            <tbody>
                @foreach ($rows as $line)
                    @php $receipt = $line->goodsReceipt; @endphp
                    <tr>
                        <td>{{ $receipt->number }}</td><td>{{ $receipt->receipt_date->format('d/m/Y') }}</td><td>{{ $receipt->branch->name }}</td><td>{{ $statusLabels[$receipt->status] ?? $receipt->status }}</td>
                        <td>{{ $line->sparepartBranch->sparepart->code }}</td><td>{{ $line->sparepartBranch->sparepart->name }}</td>
                        <td>{{ number_format((float) $line->qty, 0, ',', '.') }}</td><td>{{ number_format((float) $line->purchase_price, 0, ',', '.') }}</td><td>{{ number_format((float) $line->line_total, 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        @else
            <thead>
                <tr><th>No. Dokumen</th><th>Tanggal</th><th>Cabang</th><th>No. Referensi</th><th>Status</th><th>Jumlah Item</th><th>Total Qty</th><th>Total Nilai</th></tr>
            </thead>
            <tbody>
                @foreach ($rows as $receipt)
                    <tr>
                        <td>{{ $receipt->number }}</td><td>{{ $receipt->receipt_date->format('d/m/Y') }}</td><td>{{ $receipt->branch->name }}</td><td>{{ $receipt->reference_number ?? '-' }}</td>
                        <td>{{ $statusLabels[$receipt->status] ?? $receipt->status }}</td><td>{{ number_format($receipt->lines_count, 0, ',', '.') }}</td>
                        <td>{{ number_format((float) $receipt->total_qty, 0, ',', '.') }}</td><td>{{ number_format((float) $receipt->total_nilai, 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        @endif
    </table>
@endsection
