<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>PO {{ $purchaseOrder->number }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; padding: 24px; }
        .po-header { width: 100%; margin-bottom: 14px; border-bottom: 2px solid #1a1a1a; padding-bottom: 10px; }
        .po-header table { width: 100%; }
        .po-header .branch-name { font-size: 15px; font-weight: bold; margin: 0 0 2px; }
        .po-header .branch-detail { font-size: 9px; color: #555; }
        .po-header .doc-title { font-size: 18px; font-weight: bold; text-align: right; }
        .po-header .doc-number { font-size: 10px; text-align: right; color: #444; }
        .info-table { width: 100%; margin-bottom: 14px; }
        .info-table td { vertical-align: top; padding: 0; font-size: 10px; width: 50%; }
        .info-table .label { color: #666; }
        .info-table h3 { font-size: 10px; margin: 0 0 4px; text-transform: uppercase; color: #444; }
        table.line-table { width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 10px; }
        table.line-table th, table.line-table td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
        table.line-table th { background: #eee; font-weight: bold; }
        table.line-table td.num { text-align: right; }
        .total-row td { font-weight: bold; }
        .signature-table { width: 100%; margin-top: 40px; font-size: 10px; }
        .signature-table td { width: 50%; text-align: center; }
        .signature-space { height: 50px; }
    </style>
</head>
<body>
    <div class="po-header">
        <table>
            <tr>
                <td>
                    <p class="branch-name">{{ $purchaseOrder->branch->name }}</p>
                    <p class="branch-detail">{{ $purchaseOrder->branch->address ?? '-' }}<br>{{ $purchaseOrder->branch->phone ?? '-' }}</p>
                </td>
                <td>
                    <div class="doc-title">PURCHASE ORDER</div>
                    <div class="doc-number">No. {{ $purchaseOrder->number }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="info-table">
        <tr>
            <td>
                <h3>Kepada Supplier</h3>
                <div>{{ $purchaseOrder->supplier->name }}</div>
                <div><span class="label">Kontak:</span> {{ $purchaseOrder->supplier->contact_person ?? '-' }}</div>
                <div><span class="label">Telepon:</span> {{ $purchaseOrder->supplier->phone ?? '-' }}</div>
                <div><span class="label">Alamat:</span> {{ $purchaseOrder->supplier->address ?? '-' }}</div>
            </td>
            <td>
                <h3>Info Pembelian</h3>
                <div><span class="label">Tanggal:</span> {{ $purchaseOrder->order_date->format('d/m/Y') }}</div>
                <div><span class="label">Dikirim ke:</span> {{ $purchaseOrder->branch->name }}</div>
                <div><span class="label">Status:</span> {{ \App\Support\PurchaseOrderStatus::LABELS[$purchaseOrder->status] ?? $purchaseOrder->status }}</div>
            </td>
        </tr>
    </table>

    <table class="line-table">
        <thead>
            <tr><th>No</th><th>Kode</th><th>Sparepart</th><th>Qty</th><th>Harga</th><th>Subtotal</th></tr>
        </thead>
        <tbody>
            @foreach ($purchaseOrder->lines as $line)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $line->sparepartBranch->sparepart->code }}</td>
                    <td>{{ $line->sparepartBranch->sparepart->name }}</td>
                    <td class="num">{{ number_format($line->qty_ordered, 0, ',', '.') }}</td>
                    <td class="num">{{ number_format($line->unit_price, 0, ',', '.') }}</td>
                    <td class="num">{{ number_format($line->line_total, 0, ',', '.') }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="5" class="num">Total</td>
                <td class="num">{{ number_format($purchaseOrder->total_amount, 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    <table class="signature-table">
        <tr>
            <td>Dibuat oleh<div class="signature-space"></div>( ............................ )</td>
            <td>Disetujui Supplier<div class="signature-space"></div>( ............................ )</td>
        </tr>
    </table>
</body>
</html>
