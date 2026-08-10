<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>PKB {{ $workOrder->number }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; padding: 24px; }
        .pkb-header { width: 100%; margin-bottom: 14px; border-bottom: 2px solid #1a1a1a; padding-bottom: 10px; }
        .pkb-header table { width: 100%; }
        .pkb-header .branch-name { font-size: 15px; font-weight: bold; margin: 0 0 2px; }
        .pkb-header .branch-detail { font-size: 9px; color: #555; }
        .pkb-header .doc-title { font-size: 18px; font-weight: bold; text-align: right; }
        .pkb-header .doc-number { font-size: 10px; text-align: right; color: #444; }

        .info-table { width: 100%; margin-bottom: 14px; }
        .info-table td { vertical-align: top; padding: 0; font-size: 10px; width: 50%; }
        .info-table .label { color: #666; }
        .info-table h3 { font-size: 10px; margin: 0 0 4px; text-transform: uppercase; color: #444; }

        table.line-table { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 10px; }
        table.line-table th, table.line-table td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
        table.line-table th { background: #eee; font-weight: bold; }
        table.line-table td.num { text-align: right; }

        table.summary-table { width: 45%; margin-left: 55%; border-collapse: collapse; font-size: 10px; margin-bottom: 14px; }
        table.summary-table td { padding: 3px 6px; }
        table.summary-table td.num { text-align: right; }
        table.summary-table tr.grand-total td { font-weight: bold; font-size: 12px; border-top: 1px solid #1a1a1a; }

        .signature-table { width: 100%; margin-top: 40px; font-size: 10px; }
        .signature-table td { width: 50%; text-align: center; }
        .signature-space { height: 50px; }
    </style>
</head>
<body>
    <div class="pkb-header">
        <table>
            <tr>
                <td>
                    <p class="branch-name">{{ $workOrder->branch->name }}</p>
                    <p class="branch-detail">
                        {{ $workOrder->branch->address ?? '-' }}<br>
                        {{ $workOrder->branch->phone ?? '-' }}
                    </p>
                </td>
                <td>
                    <div class="doc-title">PERINTAH KERJA BENGKEL</div>
                    <div class="doc-number">No. {{ $workOrder->number }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="info-table">
        <tr>
            <td>
                <h3>Data Customer &amp; Kendaraan</h3>
                <div><span class="label">Nama:</span> {{ $workOrder->customer->name }}</div>
                <div><span class="label">No. Polisi:</span> {{ $workOrder->vehicle->plate_number ?? '-' }}</div>
                <div><span class="label">Kendaraan:</span> {{ optional($workOrder->vehicle->brand)->name }} {{ optional($workOrder->vehicle->type)->name }}{{ $workOrder->vehicle->year ? " ({$workOrder->vehicle->year})" : '' }}</div>
                <div><span class="label">Kilometer:</span> {{ $workOrder->odometer_km ?? '-' }}</div>
            </td>
            <td>
                <h3>Info PKB</h3>
                <div><span class="label">Tanggal:</span> {{ $workOrder->work_order_date->format('d/m/Y') }}</div>
                <div><span class="label">Mekanik:</span> {{ $workOrder->mechanic->name }}</div>
                <div><span class="label">Keluhan:</span> {{ $workOrder->notes ?? '-' }}</div>
            </td>
        </tr>
    </table>

    <table class="line-table">
        <thead>
            <tr><th>Deskripsi Jasa</th><th>Qty</th><th>Harga</th><th>Total</th></tr>
        </thead>
        <tbody>
            @forelse ($workOrder->serviceLines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="num">{{ number_format($line->qty, 0, ',', '.') }}</td>
                    <td class="num">{{ number_format($line->unit_price, 0, ',', '.') }}</td>
                    <td class="num">{{ number_format($line->line_total, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="4">Tidak ada baris jasa.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="line-table">
        <thead>
            <tr><th>Kode</th><th>Nama Sparepart</th><th>Qty</th><th>Harga</th><th>Total</th></tr>
        </thead>
        <tbody>
            @forelse ($workOrder->sparepartLines as $line)
                <tr>
                    <td>{{ $line->item_code_snapshot }}</td>
                    <td>{{ $line->item_name_snapshot }}</td>
                    <td class="num">{{ number_format($line->qty, 0, ',', '.') }}</td>
                    <td class="num">{{ number_format($line->unit_price, 0, ',', '.') }}</td>
                    <td class="num">{{ number_format($line->line_total, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="5">Tidak ada baris sparepart.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="summary-table">
        <tr><td>Total Jasa</td><td class="num">{{ number_format($workOrder->serviceLines->sum('line_total'), 0, ',', '.') }}</td></tr>
        <tr><td>Total Sparepart</td><td class="num">{{ number_format($workOrder->sparepartLines->sum('line_total'), 0, ',', '.') }}</td></tr>
        <tr class="grand-total"><td>Total Keseluruhan</td><td class="num">{{ number_format($workOrder->serviceLines->sum('line_total') + $workOrder->sparepartLines->sum('line_total'), 0, ',', '.') }}</td></tr>
    </table>

    <table class="signature-table">
        <tr>
            <td>
                Mekanik,
                <div class="signature-space"></div>
                ( {{ $workOrder->mechanic->name }} )
            </td>
            <td>
                Customer,
                <div class="signature-space"></div>
                ( ................................. )
            </td>
        </tr>
    </table>
</body>
</html>
