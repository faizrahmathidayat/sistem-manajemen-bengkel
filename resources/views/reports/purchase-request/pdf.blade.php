@extends('layouts.print')
@section('report-title', 'Laporan Permintaan Barang')
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
                <tr><th>No. Permintaan</th><th>Tanggal</th><th>Cabang</th><th>Status</th><th>Kode</th><th>Sparepart</th><th>Qty</th><th>Supplier</th><th>Harga</th><th>Subtotal</th><th>Dipilih</th></tr>
            </thead>
            <tbody>
                @foreach ($rows as $line)
                    @php($request = $line->purchaseRequest)
                    <tr>
                        <td>{{ $request->number }}</td><td>{{ $request->request_date->format('d/m/Y') }}</td><td>{{ $request->branch->name }}</td>
                        <td>{{ \App\Support\PurchaseRequestStatus::LABELS[$request->status] ?? $request->status }}</td>
                        <td>{{ $line->sparepartBranch->sparepart->code }}</td><td>{{ $line->sparepartBranch->sparepart->name }}</td>
                        <td>{{ number_format((float) $line->qty, 0, ',', '.') }}</td>
                        <td>{{ $line->supplier ? $line->supplier->name : '-' }}</td>
                        <td>{{ $line->unit_price !== null ? number_format((float) $line->unit_price, 0, ',', '.') : '-' }}</td>
                        <td>{{ $line->line_total !== null ? number_format((float) $line->line_total, 0, ',', '.') : '-' }}</td>
                        <td>{{ $line->is_selected ? 'Ya' : 'Tidak' }}</td>
                    </tr>
                @endforeach
            </tbody>
        @else
            <thead>
                <tr><th>No. Permintaan</th><th>Tanggal</th><th>Cabang</th><th>Pemohon</th><th>Item</th><th>Total</th><th>Status</th><th>Posisi / Keputusan</th><th>Lama Proses</th></tr>
            </thead>
            <tbody>
                @foreach ($rows as $request)
                    @php($total = $request->total_amount !== null ? (float) $request->total_amount : (float) $request->lines_amount)
                    @php($days = $request->processDays())
                    <tr>
                        <td>{{ $request->number }}</td><td>{{ $request->request_date->format('d/m/Y') }}</td><td>{{ $request->branch->name }}</td>
                        <td>{{ optional($request->creator)->name ?? '-' }}</td><td>{{ number_format($request->lines_count, 0, ',', '.') }}</td>
                        <td>{{ $total > 0 ? number_format($total, 0, ',', '.') : '-' }}</td>
                        <td>{{ \App\Support\PurchaseRequestStatus::LABELS[$request->status] ?? $request->status }}</td>
                        <td>{{ $request->positionLabel() }}</td><td>{{ $days === null ? '-' : $days . ' hari' }}</td>
                    </tr>
                @endforeach
            </tbody>
        @endif
    </table>
@endsection
