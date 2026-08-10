@extends('layouts.print')
@section('report-title', 'Laporan PKB')
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
                <tr>
                    <th>No. PKB</th><th>Cabang</th><th>Tanggal</th><th>Customer & Kendaraan</th>
                    <th>Mekanik</th><th>Tahun Motor</th><th>Kilometer</th>
                    <th>Tipe Item</th><th>Nama Item/Jasa</th><th>Qty</th><th>Harga Satuan</th><th>Subtotal Line</th><th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($workOrders as $workOrder)
                    @php
                        $customerVehicle = $workOrder->customer->name . ($workOrder->vehicle ? ' / ' . $workOrder->vehicle->plate_number : '');
                        $mechanicLabel = $workOrder->mechanic->display_label;
                        $vehicleYear = $workOrder->vehicle->year ?? '-';
                        $odometerKm = $workOrder->odometer_km ?? '-';
                    @endphp
                    @foreach ($workOrder->serviceLines as $line)
                        <tr>
                            <td>{{ $workOrder->number }}</td><td>{{ $workOrder->branch->name }}</td><td>{{ $workOrder->work_order_date->format('d/m/Y') }}</td><td>{{ $customerVehicle }}</td>
                            <td>{{ $mechanicLabel }}</td><td>{{ $vehicleYear }}</td><td>{{ $odometerKm }}</td>
                            <td>Jasa</td><td>{{ $line->description }}</td><td>{{ number_format($line->qty, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->unit_price, 0, ',', '.') }}</td><td>{{ number_format($line->line_total, 0, ',', '.') }}</td><td>{{ $workOrder->status }}</td>
                        </tr>
                    @endforeach
                    @foreach ($workOrder->sparepartLines as $line)
                        <tr>
                            <td>{{ $workOrder->number }}</td><td>{{ $workOrder->branch->name }}</td><td>{{ $workOrder->work_order_date->format('d/m/Y') }}</td><td>{{ $customerVehicle }}</td>
                            <td>{{ $mechanicLabel }}</td><td>{{ $vehicleYear }}</td><td>{{ $odometerKm }}</td>
                            <td>Sparepart</td><td>{{ $line->item_name_snapshot }}</td><td>{{ number_format($line->qty, 0, ',', '.') }}</td>
                            <td>{{ number_format($line->unit_price, 0, ',', '.') }}</td><td>{{ number_format($line->line_total, 0, ',', '.') }}</td><td>{{ $workOrder->status }}</td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        @else
            <thead>
                <tr><th>No. PKB</th><th>Cabang</th><th>Tanggal</th><th>Customer & Kendaraan</th><th>Mekanik</th><th>Tahun Motor</th><th>Kilometer</th><th>Subtotal Jasa</th><th>Subtotal Sparepart</th><th>Grand Total</th><th>Status</th></tr>
            </thead>
            <tbody>
                @foreach ($workOrders as $workOrder)
                    @php
                        $subtotalService = (float) $workOrder->serviceLines->sum('line_total');
                        $subtotalSparepart = (float) $workOrder->sparepartLines->sum('line_total');
                    @endphp
                    <tr>
                        <td>{{ $workOrder->number }}</td>
                        <td>{{ $workOrder->branch->name }}</td>
                        <td>{{ $workOrder->work_order_date->format('d/m/Y') }}</td>
                        <td>{{ $workOrder->customer->name }}{{ $workOrder->vehicle ? ' / ' . $workOrder->vehicle->plate_number : '' }}</td>
                        <td>{{ $workOrder->mechanic->display_label }}</td>
                        <td>{{ $workOrder->vehicle->year ?? '-' }}</td>
                        <td>{{ $workOrder->odometer_km ?? '-' }}</td>
                        <td>{{ number_format($subtotalService, 0, ',', '.') }}</td>
                        <td>{{ number_format($subtotalSparepart, 0, ',', '.') }}</td>
                        <td>{{ number_format($subtotalService + $subtotalSparepart, 0, ',', '.') }}</td>
                        <td>{{ $workOrder->status }}</td>
                    </tr>
                @endforeach
            </tbody>
        @endif
    </table>
@endsection
