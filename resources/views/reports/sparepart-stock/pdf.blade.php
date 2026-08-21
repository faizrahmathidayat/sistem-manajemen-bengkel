@extends('layouts.print')
@section('report-title', 'Laporan Stok')
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
                <tr><th>Kode</th><th>Nama Sparepart</th><th>Cabang</th><th>Rak</th><th>Stok Min</th><th>On-Hand</th><th>Reserved</th><th>Available</th><th>Harga Jual</th><th>Nilai Total</th><th>Status</th></tr>
            </thead>
            <tbody>
                @foreach ($sparepartBranches as $sparepartBranch)
                    @php
                        $onHand = (float) $sparepartBranch->on_hand_qty;
                        $reserved = (float) $sparepartBranch->reserved_qty;
                        $available = $onHand - $reserved;
                        $minimumStock = (float) $sparepartBranch->minimum_stock;
                        $sellingPrice = (float) $sparepartBranch->selling_price;
                        if ($onHand == 0.0) { $status = 'Habis'; }
                        elseif ($minimumStock > 0.0 && $available < $minimumStock) { $status = 'Kritis'; }
                        else { $status = 'Tersedia'; }
                    @endphp
                    <tr>
                        <td>{{ $sparepartBranch->sparepart->code }}</td><td>{{ $sparepartBranch->sparepart->name }}</td><td>{{ $sparepartBranch->branch->name }}</td><td>{{ optional($sparepartBranch->rack)->code ?? '-' }}</td>
                        <td>{{ number_format($minimumStock, 0, ',', '.') }}</td><td>{{ number_format($onHand, 0, ',', '.') }}</td><td>{{ number_format($reserved, 0, ',', '.') }}</td>
                        <td>{{ number_format($available, 0, ',', '.') }}</td><td>{{ number_format($sellingPrice, 0, ',', '.') }}</td><td>{{ number_format($onHand * $sellingPrice, 0, ',', '.') }}</td><td>{{ $status }}</td>
                    </tr>
                @endforeach
            </tbody>
        @else
            <thead>
                <tr><th>Kode</th><th>Nama Sparepart</th><th>Cabang</th><th>Rak</th><th>Stok Min</th><th>Stok On-Hand</th><th>Harga Jual</th><th>Nilai Inventaris</th><th>Status</th></tr>
            </thead>
            <tbody>
                @foreach ($sparepartBranches as $sparepartBranch)
                    @php
                        $onHand = (float) $sparepartBranch->on_hand_qty;
                        $reserved = (float) $sparepartBranch->reserved_qty;
                        $available = $onHand - $reserved;
                        $minimumStock = (float) $sparepartBranch->minimum_stock;
                        $sellingPrice = (float) $sparepartBranch->selling_price;
                        if ($onHand == 0.0) { $status = 'Habis'; }
                        elseif ($minimumStock > 0.0 && $available < $minimumStock) { $status = 'Kritis'; }
                        else { $status = 'Tersedia'; }
                    @endphp
                    <tr>
                        <td>{{ $sparepartBranch->sparepart->code }}</td><td>{{ $sparepartBranch->sparepart->name }}</td><td>{{ $sparepartBranch->branch->name }}</td><td>{{ optional($sparepartBranch->rack)->code ?? '-' }}</td>
                        <td>{{ number_format($minimumStock, 0, ',', '.') }}</td><td>{{ number_format($onHand, 0, ',', '.') }}</td>
                        <td>{{ number_format($sellingPrice, 0, ',', '.') }}</td><td>{{ number_format($onHand * $sellingPrice, 0, ',', '.') }}</td><td>{{ $status }}</td>
                    </tr>
                @endforeach
            </tbody>
        @endif
    </table>
@endsection
