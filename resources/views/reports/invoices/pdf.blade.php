@extends('layouts.print')
@section('report-title', 'Laporan Invoice')
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
                <tr><th>No. Invoice</th><th>Cabang</th><th>Tanggal</th><th>Customer</th><th>Mekanik</th><th>Status</th><th>Tipe Item</th><th>Nama Item</th><th>Qty</th><th>Harga Satuan</th><th>Diskon</th><th>Subtotal Line</th></tr>
            </thead>
            <tbody>
                @foreach ($invoices as $invoice)
                    @php $mechanicLabel = optional(optional($invoice->workOrder)->mechanic)->display_label ?? '-'; @endphp
                    @forelse ($invoice->details as $detail)
                        <tr>
                            <td>{{ $invoice->number }}</td><td>{{ $invoice->branch->name }}</td><td>{{ $invoice->invoice_date->format('d/m/Y') }}</td><td>{{ $invoice->customer->name }}</td><td>{{ $mechanicLabel }}</td><td>{{ $invoice->status }}</td>
                            <td>{{ $detail->item_type === \App\Support\InvoiceDetailItemType::SERVICE ? 'Jasa' : 'Sparepart' }}</td>
                            <td>{{ $detail->description }}</td><td>{{ number_format($detail->qty, 0, ',', '.') }}</td>
                            <td>{{ number_format($detail->unit_price, 0, ',', '.') }}</td>
                            <td>{{ $detail->discount_amount > 0 ? number_format($detail->discount_amount, 0, ',', '.') : '-' }}</td>
                            <td>{{ number_format($detail->line_total, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td>{{ $invoice->number }}</td><td>{{ $invoice->branch->name }}</td><td>{{ $invoice->invoice_date->format('d/m/Y') }}</td><td>{{ $invoice->customer->name }}</td><td>{{ $mechanicLabel }}</td><td>{{ $invoice->status }}</td>
                            <td colspan="6">&mdash;</td>
                        </tr>
                    @endforelse
                @endforeach
            </tbody>
        @else
            <thead>
                <tr><th>No. Invoice</th><th>Cabang</th><th>Tanggal</th><th>Customer</th><th>Mekanik</th><th>Subtotal Jasa</th><th>Subtotal Sparepart</th><th>Discount</th><th>Grand Total</th><th>Terbayar</th><th>Sisa Piutang</th><th>Status</th></tr>
            </thead>
            <tbody>
                @foreach ($invoices as $invoice)
                    <tr>
                        <td>{{ $invoice->number }}</td><td>{{ $invoice->branch->name }}</td><td>{{ $invoice->invoice_date->format('d/m/Y') }}</td><td>{{ $invoice->customer->name }}</td>
                        <td>{{ optional(optional($invoice->workOrder)->mechanic)->display_label ?? '-' }}</td>
                        <td>{{ number_format($invoice->subtotal_service, 0, ',', '.') }}</td><td>{{ number_format($invoice->subtotal_sparepart, 0, ',', '.') }}</td>
                        <td>{{ number_format($invoice->discount_amount, 0, ',', '.') }}</td><td>{{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                        <td>{{ number_format($invoice->paid_amount, 0, ',', '.') }}</td><td>{{ number_format($invoice->outstanding_amount, 0, ',', '.') }}</td><td>{{ $invoice->status }}</td>
                    </tr>
                @endforeach
            </tbody>
        @endif
    </table>
@endsection
