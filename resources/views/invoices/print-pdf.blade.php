<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $invoice->number }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; padding: 24px; }
        .invoice-header { width: 100%; margin-bottom: 14px; border-bottom: 2px solid #1a1a1a; padding-bottom: 10px; }
        .invoice-header table { width: 100%; }
        .invoice-header .branch-name { font-size: 15px; font-weight: bold; margin: 0 0 2px; }
        .invoice-header .branch-detail { font-size: 9px; color: #555; }
        .invoice-header .doc-title { font-size: 18px; font-weight: bold; text-align: right; }
        .invoice-header .doc-number { font-size: 10px; text-align: right; color: #444; }

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

        table.payment-table { width: 100%; border-collapse: collapse; font-size: 9px; margin-bottom: 14px; }
        table.payment-table th, table.payment-table td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
        table.payment-table th { background: #eee; font-weight: bold; }

        .signature-table { width: 100%; margin-top: 40px; font-size: 10px; }
        .signature-table td { width: 50%; text-align: center; }
        .signature-space { height: 50px; }
    </style>
</head>
<body>
    <div class="invoice-header">
        <table>
            <tr>
                <td>
                    <p class="branch-name">{{ $invoice->branch->name }}</p>
                    <p class="branch-detail">
                        {{ $invoice->branch->address ?? '-' }}<br>
                        {{ $invoice->branch->phone ?? '-' }}
                    </p>
                </td>
                <td>
                    <div class="doc-title">INVOICE</div>
                    <div class="doc-number">No. {{ $invoice->number }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="info-table">
        <tr>
            <td>
                <h3>Data Customer &amp; Kendaraan</h3>
                <div><span class="label">Nama:</span> {{ $invoice->customer->name }}</div>
                <div><span class="label">Alamat:</span> {{ $invoice->customer->address ?? '-' }}</div>
                <div><span class="label">No. Polisi:</span> {{ $invoice->workOrder->vehicle->plate_number }}</div>
                <div><span class="label">Kendaraan:</span> {{ optional($invoice->workOrder->vehicle->brand)->name }} {{ optional($invoice->workOrder->vehicle->type)->name }}</div>
            </td>
            <td>
                <h3>Info Invoice</h3>
                <div><span class="label">No. PKB:</span> {{ $invoice->workOrder->number }}</div>
                <div><span class="label">Tanggal Invoice:</span> {{ $invoice->invoice_date->format('d/m/Y') }}</div>
                <div><span class="label">Jatuh Tempo:</span> {{ optional($invoice->due_date)->format('d/m/Y') ?? '-' }}</div>
            </td>
        </tr>
    </table>

    <table class="line-table">
        <thead>
            <tr><th>Tipe</th><th>Kode</th><th>Deskripsi</th><th>Qty</th><th>Harga Satuan</th><th>Diskon</th><th>Total</th></tr>
        </thead>
        <tbody>
            @foreach ($invoice->details as $detail)
                <tr>
                    <td>{{ $detail->item_type === \App\Support\InvoiceDetailItemType::SERVICE ? 'Jasa' : 'Sparepart' }}</td>
                    <td>{{ $detail->item_code_snapshot ?? '-' }}</td>
                    <td>{{ $detail->description }}</td>
                    <td class="num">{{ number_format($detail->qty, 0, ',', '.') }}</td>
                    <td class="num">{{ number_format($detail->unit_price, 0, ',', '.') }}</td>
                    <td class="num">{{ $detail->discount_amount > 0 ? number_format($detail->discount_amount, 0, ',', '.') : '-' }}</td>
                    <td class="num">{{ number_format($detail->line_total, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="summary-table">
        <tr><td>Subtotal Jasa</td><td class="num">{{ number_format($invoice->subtotal_service, 0, ',', '.') }}</td></tr>
        <tr><td>Subtotal Sparepart</td><td class="num">{{ number_format($invoice->subtotal_sparepart, 0, ',', '.') }}</td></tr>
        <tr><td>Diskon ({{ number_format($invoice->discount_percent, 2, ',', '.') }}%)</td><td class="num">{{ number_format($invoice->discount_amount, 0, ',', '.') }}</td></tr>
        <tr><td>PPN ({{ number_format($invoice->tax_percent, 2, ',', '.') }}%)</td><td class="num">{{ number_format($invoice->tax_amount, 0, ',', '.') }}</td></tr>
        <tr class="grand-total"><td>Grand Total</td><td class="num">{{ number_format($invoice->grand_total, 0, ',', '.') }}</td></tr>
        <tr><td>Sudah Dibayar</td><td class="num">{{ number_format($invoice->paid_amount, 0, ',', '.') }}</td></tr>
        <tr><td>Sisa Piutang</td><td class="num">{{ number_format($invoice->outstanding_amount, 0, ',', '.') }}</td></tr>
    </table>

    @if ($invoice->allocations->isNotEmpty())
        <table class="payment-table">
            <thead>
                <tr><th>No. Pembayaran</th><th>Tanggal</th><th>Nominal Dialokasikan</th></tr>
            </thead>
            <tbody>
                @foreach ($invoice->allocations as $allocation)
                    <tr>
                        <td>{{ $allocation->paymentReceipt->number }}</td>
                        <td>{{ $allocation->paymentReceipt->payment_date->format('d/m/Y') }}</td>
                        <td>{{ number_format($allocation->allocated_amount, 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="signature-table">
        <tr>
            <td>
                Hormat kami,
                <div class="signature-space"></div>
                ( ................................. )
            </td>
            <td>
                Penerima,
                <div class="signature-space"></div>
                ( ................................. )
            </td>
        </tr>
    </table>
</body>
</html>
