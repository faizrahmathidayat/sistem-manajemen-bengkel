@php
    $incompleteCount = $purchaseRequest->lines->filter(function ($line) {
        return $line->supplier_id === null || $line->unit_price === null || (float) $line->unit_price <= 0;
    })->count();
@endphp

<form method="POST" action="{{ route('purchase-requests.assign-suppliers', $purchaseRequest) }}" id="assignSuppliersForm">
    @csrf
    @method('PATCH')

    <div class="panel">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <h2 class="section-title"><i class="bi bi-person-lines-fill"></i><span>Supplier &amp; Harga per Barang</span></h2>
            @if ($incompleteCount > 0)
                <div class="small px-2 py-1 rounded" style="background:#FFFBEB;border:1px solid #FDE68A;color:#92400E;">
                    <i class="bi bi-exclamation-triangle"></i>
                    {{ $incompleteCount }} dari {{ $purchaseRequest->lines->count() }} baris belum lengkap — lengkapi supplier &amp; harga untuk mengajukan approval
                </div>
            @endif
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Sparepart</th>
                        <th class="text-end">Qty</th>
                        <th style="min-width: 240px;">Supplier</th>
                        <th style="min-width: 180px;">No. Rekening</th>
                        <th style="width: 150px;" class="text-end">Harga Beli (Rp)</th>
                        <th class="text-end">Subtotal (Rp)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($purchaseRequest->lines as $line)
                        <tr class="assign-line" data-qty="{{ (int) $line->qty }}">
                            <td><code>{{ $line->sparepartBranch->sparepart->code }}</code></td>
                            <td>{{ $line->sparepartBranch->sparepart->name }}</td>
                            <td style="width: 110px;">
                                <input type="number" data-integer step="1" min="1" name="lines[{{ $line->id }}][qty]" value="{{ old("lines.{$line->id}.qty", (int) $line->qty) }}" class="form-control form-control-sm text-end assign-qty">
                                @error("lines.{$line->id}.qty")<div class="text-danger small">{{ $message }}</div>@enderror
                            </td>
                            <td>
                                <select name="lines[{{ $line->id }}][supplier_id]" class="form-select form-select-sm assign-supplier-select">
                                    <option value=""></option>
                                    @if ($line->supplier)
                                        <option value="{{ $line->supplier->id }}" selected>{{ $line->supplier->label }}</option>
                                    @endif
                                </select>
                                @error("lines.{$line->id}.supplier_id")<div class="text-danger small">{{ $message }}</div>@enderror
                            </td>
                            <td>
                                <input type="text" name="lines[{{ $line->id }}][supplier_account_number]" value="{{ old("lines.{$line->id}.supplier_account_number", $line->supplier_account_number) }}" class="form-control form-control-sm assign-account" maxlength="60" placeholder="No. rekening supplier">
                                @error("lines.{$line->id}.supplier_account_number")<div class="text-danger small">{{ $message }}</div>@enderror
                            </td>
                            <td>
                                <input type="number" data-integer step="1" min="0" name="lines[{{ $line->id }}][unit_price]" value="{{ old("lines.{$line->id}.unit_price", $line->unit_price !== null ? (int) $line->unit_price : '') }}" class="form-control form-control-sm text-end assign-price" placeholder="0">
                                @error("lines.{$line->id}.unit_price")<div class="text-danger small">{{ $message }}</div>@enderror
                            </td>
                            <td class="text-end assign-subtotal">{{ $line->line_total !== null ? number_format($line->line_total, 0, ',', '.') : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="6" class="text-end fw-bold">Total saat ini</td>
                        <td class="text-end fw-bold" id="assignTotal">{{ number_format((float) $purchaseRequest->lines->sum('line_total'), 0, ',', '.') }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        @error('lines')<div class="text-danger small mt-2">{{ $message }}</div>@enderror

        <div class="d-flex justify-content-end gap-2 mt-3">
            <button type="submit" class="btn btn-outline-primary"><i class="bi bi-save"></i> Simpan Draft</button>
        </div>
    </div>
</form>

@if (isset($submitPreview) && $submitPreview)
    <div class="panel mt-3">
        <h2 class="section-title mb-2"><i class="bi bi-patch-check"></i><span>Pratinjau Rantai Approval</span></h2>
        @if (isset($submitPreview['error']))
            <div class="alert alert-danger mb-0">{{ $submitPreview['error'] }} Permintaan belum dapat diajukan.</div>
        @else
            <div class="small text-muted mb-1">Total Rp {{ number_format($submitPreview['total'], 0, ',', '.') }}. Saat Ajukan Approval ditekan, rantai berikut dibentuk:</div>
            <div>
                @foreach ($submitPreview['steps'] as $step)
                    <b>{{ $step['level_name'] }}</b>
                    <span class="text-muted">({{ collect($step['user_ids'])->map(function ($id) use ($submitPreview) { return $submitPreview['userNames'][$id] ?? ('#' . $id); })->implode(', ') }})</span>
                    @if (! $loop->last) <i class="bi bi-arrow-right mx-1"></i> @endif
                @endforeach
            </div>
        @endif
    </div>
@endif

@push('scripts')
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="{{ asset('js/select2-ajax-picker.js') }}"></script>
<script>
(function () {
    const supplierEndpoint = @json(route('lookup.suppliers'));
    const totalEl = document.getElementById('assignTotal');

    function format(value) { return Number(value).toLocaleString('id-ID'); }

    function recalc() {
        let total = 0;
        document.querySelectorAll('.assign-line').forEach(function (row) {
            const qty = Number(row.querySelector('.assign-qty').value);
            const price = Number(row.querySelector('.assign-price').value);
            const cell = row.querySelector('.assign-subtotal');
            if (price > 0) {
                const sub = qty * price;
                total += sub;
                cell.textContent = format(sub);
            } else {
                cell.textContent = '—';
            }
        });
        totalEl.textContent = format(total);
    }

    document.querySelectorAll('.assign-supplier-select').forEach(function (select) {
        const row = select.closest('tr');
        initAjaxSelect(select, {
            endpoint: supplierEndpoint,
            placeholder: 'Pilih supplier…',
            onSelect: function (data) {
                const account = row.querySelector('.assign-account');
                // Prefill from the supplier master; the user may still overwrite it.
                if (account && data && data.account_number) { account.value = data.account_number; }
            },
        });
    });
    document.querySelectorAll('.assign-price, .assign-qty').forEach(function (input) {
        input.addEventListener('input', recalc);
    });
})();
</script>
@endpush
