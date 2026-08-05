@extends('layouts.app')
@section('title', 'Ubah Invoice')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-receipt me-2"></i>Ubah {{ $invoice->number }}</h1>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <p class="text-muted">Subtotal Jasa: {{ number_format($invoice->subtotal_service, 0, ',', '.') }} &middot; Subtotal Sparepart: {{ number_format($invoice->subtotal_sparepart, 0, ',', '.') }}</p>
            <form method="POST" action="{{ route('invoices.update', $invoice) }}">
                @csrf
                @method('PUT')
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="discount_percent" class="form-label">Diskon (%)</label>
                        <input type="number" step="0.01" min="0" max="100" name="discount_percent" id="discount_percent"
                            class="form-control @error('discount_percent') is-invalid @enderror"
                            value="{{ old('discount_percent', $invoice->discount_percent) }}" required>
                        @error('discount_percent')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label for="tax_percent" class="form-label">PPN (%)</label>
                        <input type="number" step="0.01" min="0" name="tax_percent" id="tax_percent"
                            class="form-control @error('tax_percent') is-invalid @enderror"
                            value="{{ old('tax_percent', $invoice->tax_percent) }}" required>
                        @error('tax_percent')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-12">
                        <label for="notes" class="form-label">Catatan</label>
                        <textarea name="notes" id="notes" class="form-control @error('notes') is-invalid @enderror" rows="2">{{ old('notes', $invoice->notes) }}</textarea>
                        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="mt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
                    <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-outline-secondary btn-sm">Batal</a>
                </div>
            </form>
        </div>
    </div>
@endsection
