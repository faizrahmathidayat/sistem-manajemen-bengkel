@extends('layouts.app')
@section('title', 'Ubah Penerimaan Barang')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-truck"></i></span>
            <div>
                <p class="eyebrow mb-1">Penerimaan Barang</p>
                <h1 class="h3 mb-1">Ubah {{ $goodsReceipt->number }}</h1>
                <p class="text-muted mb-0">{{ $goodsReceipt->branch->name }} · {{ $goodsReceipt->purchaseOrder->supplier->name }} · {{ $goodsReceipt->purchaseOrder->number }}</p>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('goods-receipts.show', $goodsReceipt) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <form method="POST" action="{{ route('goods-receipts.update', $goodsReceipt) }}" id="goodsReceiptForm">
        @csrf
        @method('PUT')
        <div class="panel mb-3">
            <div class="panel-header">
                <h2 class="h5 mb-1 section-title"><i class="bi bi-info-circle"></i><span>Informasi Penerimaan</span></h2>
            </div>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Tanggal Penerimaan</label>
                    <input type="date" name="receipt_date" value="{{ old('receipt_date', $goodsReceipt->receipt_date->format('Y-m-d')) }}" class="form-control @error('receipt_date') is-invalid @enderror" required>
                    @error('receipt_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">No. Surat Jalan Supplier</label>
                    <input type="text" name="reference_number" value="{{ old('reference_number', $goodsReceipt->reference_number) }}" class="form-control @error('reference_number') is-invalid @enderror" maxlength="100">
                    @error('reference_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Catatan</label>
                    <input type="text" name="notes" value="{{ old('notes', $goodsReceipt->notes) }}" class="form-control @error('notes') is-invalid @enderror">
                    @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        <div class="panel mb-3">
            <div class="panel-header">
                <h2 class="h5 mb-1 section-title"><i class="bi bi-nut"></i><span>Barang yang Diterima</span></h2>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Sparepart</th>
                            <th class="text-end">Dipesan</th>
                            <th class="text-end">Sudah Diterima</th>
                            <th class="text-end">Sisa</th>
                            <th style="width: 150px;">Qty Diterima Sekarang</th>
                            <th class="text-end">Harga (dari PO)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($orderLines as $index => $line)
                            <tr class="{{ $line['remaining'] <= 0 ? 'text-muted' : '' }}">
                                <td><code>{{ $line['code'] }}</code></td>
                                <td>{{ $line['name'] }}</td>
                                <td class="text-end">{{ number_format($line['ordered'], 0, ',', '.') }}</td>
                                <td class="text-end">{{ number_format($line['received'], 0, ',', '.') }}</td>
                                <td class="text-end fw-bold">{{ number_format($line['remaining'], 0, ',', '.') }}</td>
                                <td>
                                    <input type="hidden" name="lines[{{ $index }}][purchase_order_line_id]" value="{{ $line['purchase_order_line_id'] }}">
                                    <input type="number" data-integer step="1" min="0" max="{{ $line['remaining'] }}" name="lines[{{ $index }}][qty]" value="{{ old("lines.{$index}.qty", $line['qty']) }}" class="form-control form-control-sm text-end @error("lines.{$index}.qty") is-invalid @enderror" {{ $line['remaining'] <= 0 && $line['qty'] <= 0 ? 'readonly' : '' }}>
                                    @error("lines.{$index}.qty")<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </td>
                                <td class="text-end">{{ number_format($line['unit_price'], 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @error('lines')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
        </div>

        <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
            <a href="{{ route('goods-receipts.show', $goodsReceipt) }}" class="btn btn-outline-secondary">Batal</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        </div>
    </form>
@endsection
