@extends('layouts.print')
@section('report-title', 'Laporan Pembelian Barang')
@section('filter-summary', $filterSummary)
@section('note')
    @if ($truncated)
        <p class="print-note">Data melebihi 1.000 baris, ditampilkan sebagian. Gunakan Export Excel untuk data lengkap.</p>
    @endif
@endsection
@section('table')
    <?php $fmt = function ($value) { return number_format((float) $value, 0, ',', '.'); }; ?>
    <table class="print-table">
        @if ($mode === 'detail')
            <thead>
                <tr><th>No. Pembelian</th><th>Tanggal</th><th>Supplier</th><th>Cabang</th><th>Status</th><th>Kode</th><th>Sparepart</th><th>Qty Pesan</th><th>Qty Terima</th><th>Sisa</th><th>Harga</th><th>Nilai</th></tr>
            </thead>
            <tbody>
                @foreach ($rows as $line)
                    @php($order = $line->purchaseOrder)
                    <tr>
                        <td>{{ $order->number }}</td><td>{{ $order->order_date->format('d/m/Y') }}</td><td>{{ $order->supplier->name }}</td><td>{{ $order->branch->name }}</td>
                        <td>{{ \App\Support\PurchaseOrderStatus::LABELS[$order->status] ?? $order->status }}</td>
                        <td>{{ $line->sparepartBranch->sparepart->code }}</td><td>{{ $line->sparepartBranch->sparepart->name }}</td>
                        <td>{{ $fmt($line->qty_ordered) }}</td><td>{{ $fmt($line->qty_received) }}</td><td>{{ $fmt($line->remainingQty()) }}</td>
                        <td>{{ $fmt($line->unit_price) }}</td><td>{{ $fmt($line->line_total) }}</td>
                    </tr>
                @endforeach
            </tbody>
        @elseif ($mode === 'supplier')
            <thead>
                <tr><th>Supplier</th><th>Jumlah PO</th><th>Nilai Dipesan</th><th>Nilai Diterima</th><th>Outstanding</th></tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row->supplier_name }}</td><td>{{ $fmt($row->po_count) }}</td><td>{{ $fmt($row->ordered_value) }}</td>
                        <td>{{ $fmt($row->received_value) }}</td><td>{{ $fmt((float) $row->ordered_value - (float) $row->received_value) }}</td>
                    </tr>
                @endforeach
            </tbody>
        @else
            <thead>
                <tr><th>No. Pembelian</th><th>Tanggal</th><th>Supplier</th><th>Cabang</th><th>Nilai PO</th><th>Diterima</th><th>Outstanding</th><th>% Terima</th><th>Status</th></tr>
            </thead>
            <tbody>
                @foreach ($rows as $order)
                    @php($ordered = (float) $order->qty_ordered_sum)
                    <tr>
                        <td>{{ $order->number }}</td><td>{{ $order->order_date->format('d/m/Y') }}</td><td>{{ $order->supplier->name }}</td><td>{{ $order->branch->name }}</td>
                        <td>{{ $fmt($order->total_amount) }}</td><td>{{ $fmt($order->received_value) }}</td>
                        <td>{{ $fmt((float) $order->total_amount - (float) $order->received_value) }}</td>
                        <td>{{ $ordered > 0 ? (int) round(((float) $order->qty_received_sum / $ordered) * 100) : 0 }}%</td>
                        <td>{{ \App\Support\PurchaseOrderStatus::LABELS[$order->status] ?? $order->status }}</td>
                    </tr>
                @endforeach
            </tbody>
        @endif
    </table>
@endsection
