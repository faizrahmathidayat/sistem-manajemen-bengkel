<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; font-size: 14px; color: #212529;">
    <p>Salam {{ $invoice->customer->name }},</p>
    <p>
        Terlampir invoice <strong>{{ $invoice->number }}</strong> dari {{ $invoice->branch->name }}
        sebesar <strong>Rp {{ number_format($invoice->grand_total, 0, ',', '.') }}</strong>
        tertanggal {{ $invoice->invoice_date->format('d/m/Y') }}.
    </p>
    @if ($invoice->outstanding_amount > 0)
        <p>Sisa tagihan: <strong>Rp {{ number_format($invoice->outstanding_amount, 0, ',', '.') }}</strong>.</p>
    @endif
    <p>Terima kasih.</p>
</body>
</html>
