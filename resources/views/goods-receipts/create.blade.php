@extends('layouts.app')
@section('title', 'Penerimaan Barang Baru')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-truck"></i></span>
            <div>
                <p class="eyebrow mb-1">Penerimaan Barang</p>
                <h1 class="h3 mb-1">Penerimaan Barang Baru</h1>
                <p class="text-muted mb-0">Penerimaan dibuat berdasarkan pembelian yang sudah diterbitkan.</p>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('goods-receipts.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <form method="POST" action="{{ route('goods-receipts.store') }}" id="goodsReceiptForm">
        @csrf
        <div class="panel mb-3">
            <div class="panel-header">
                <h2 class="h5 mb-1 section-title"><i class="bi bi-info-circle"></i><span>Informasi Penerimaan</span></h2>
            </div>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Pembelian (PO) <span class="text-danger">*</span></label>
                    <select name="purchase_order_id" id="poSelect" class="form-select @error('purchase_order_id') is-invalid @enderror" required>
                        @if ($preselectedOrder)
                            <option value="{{ $preselectedOrder->id }}" selected>{{ $preselectedOrder->number }} — {{ $preselectedOrder->supplier->name }}</option>
                        @endif
                    </select>
                    @error('purchase_order_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    <div class="form-text">Hanya PO Diterbitkan / Diterima Sebagian di cabang Anda.</div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Supplier</label>
                    <input type="text" id="poSupplier" class="form-control" value="{{ $preselectedOrder ? $preselectedOrder->supplier->name : '' }}" disabled>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Cabang</label>
                    <input type="text" id="poBranch" class="form-control" value="{{ $preselectedOrder ? $preselectedOrder->branch->name : '' }}" disabled>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Tanggal Penerimaan</label>
                    <input type="date" name="receipt_date" value="{{ old('receipt_date', now()->format('Y-m-d')) }}" class="form-control @error('receipt_date') is-invalid @enderror" required>
                    @error('receipt_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2">
                    <label class="form-label">No. Surat Jalan Supplier</label>
                    <input type="text" name="reference_number" value="{{ old('reference_number') }}" class="form-control @error('reference_number') is-invalid @enderror" maxlength="100">
                    @error('reference_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-12">
                    <label class="form-label">Catatan</label>
                    <input type="text" name="notes" value="{{ old('notes') }}" class="form-control @error('notes') is-invalid @enderror" placeholder="Opsional">
                    @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        <div class="panel mb-3">
            <div class="panel-header">
                <h2 class="h5 mb-1 section-title"><i class="bi bi-nut"></i><span>Barang yang Diterima</span></h2>
            </div>
            <div id="poLinesLoading" class="small text-muted d-none mb-2">
                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Memuat baris pembelian...
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
                            <th class="text-end">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody id="poLines">
                        <tr id="poLinesEmpty"><td colspan="8" class="text-muted">Pilih pembelian terlebih dahulu.</td></tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="7" class="text-end fw-bold">Total Penerimaan</td>
                            <td class="text-end fw-bold" id="poTotal">0</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="small text-muted mt-2">Qty diterima tidak boleh melebihi <b>Sisa</b>. Baris tidak bisa ditambah/dihapus dan harga tidak bisa diubah — keduanya berasal dari PO.</div>
            @error('lines')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            @foreach ($errors->get('lines.*') as $messages)
                @foreach ($messages as $message)
                    <div class="text-danger small mt-1">{{ $message }}</div>
                @endforeach
            @endforeach
        </div>

        <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
            <a href="{{ route('goods-receipts.index') }}" class="btn btn-outline-secondary">Batal</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan Draft</button>
        </div>
    </form>

    @php($poLinesUrl = route('goods-receipts.po-lines', ['purchaseOrder' => '__ID__']))
    @php($oldQtyByLine = collect(old('lines', []))->pluck('qty', 'purchase_order_line_id')->all())
    @php($preselectedOrderId = $preselectedOrder ? $preselectedOrder->id : null)

    @push('scripts')
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="{{ asset('js/select2-ajax-picker.js') }}"></script>
    <script>
    (function () {
        const poLinesUrl = @json($poLinesUrl);
        const oldQty = @json($oldQtyByLine);
        const preselectedOrderId = @json($preselectedOrderId);
        const select = document.getElementById('poSelect');
        const body = document.getElementById('poLines');
        const loading = document.getElementById('poLinesLoading');
        const totalEl = document.getElementById('poTotal');

        function fmt(value) { return Number(value).toLocaleString('id-ID'); }

        function cell(text, className) {
            const td = document.createElement('td');
            if (className) td.className = className;
            td.textContent = text;
            return td;
        }

        function recalc() {
            let total = 0;
            body.querySelectorAll('tr[data-price]').forEach(function (row) {
                const qty = Number(row.querySelector('.po-qty').value) || 0;
                const subtotal = qty * Number(row.dataset.price);
                row.querySelector('.po-subtotal').textContent = qty > 0 ? fmt(subtotal) : '—';
                total += subtotal;
            });
            totalEl.textContent = fmt(total);
        }

        function clearLines() {
            body.innerHTML = '<tr id="poLinesEmpty"><td colspan="8" class="text-muted">Pilih pembelian terlebih dahulu.</td></tr>';
            totalEl.textContent = '0';
        }

        function renderLines(lines) {
            body.innerHTML = '';
            lines.forEach(function (line, index) {
                const row = document.createElement('tr');
                row.dataset.price = line.unit_price;
                const code = document.createElement('td');
                const codeTag = document.createElement('code');
                codeTag.textContent = line.code;
                code.appendChild(codeTag);
                row.appendChild(code);
                row.appendChild(cell(line.name));
                row.appendChild(cell(fmt(line.ordered), 'text-end'));
                row.appendChild(cell(fmt(line.received), 'text-end'));
                row.appendChild(cell(fmt(line.remaining), 'text-end fw-bold'));

                const qtyCell = document.createElement('td');
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'lines[' + index + '][purchase_order_line_id]';
                hidden.value = line.purchase_order_line_id;
                const input = document.createElement('input');
                input.type = 'number';
                input.step = '1';
                input.min = '0';
                input.max = String(line.remaining);
                input.className = 'form-control form-control-sm text-end po-qty';
                input.setAttribute('data-integer', '');
                input.name = 'lines[' + index + '][qty]';
                const remembered = oldQty[line.purchase_order_line_id];
                input.value = line.remaining > 0 ? (remembered !== undefined ? remembered : line.remaining) : 0;
                if (line.remaining <= 0) {
                    input.readOnly = true;
                    row.className = 'text-muted';
                }
                input.addEventListener('input', recalc);
                qtyCell.appendChild(hidden);
                qtyCell.appendChild(input);
                row.appendChild(qtyCell);

                row.appendChild(cell(fmt(line.unit_price), 'text-end'));
                row.appendChild(cell('—', 'text-end po-subtotal'));
                body.appendChild(row);
            });
            recalc();
        }

        async function loadLines(orderId) {
            clearLines();
            if (!orderId) return;
            loading.classList.remove('d-none');
            try {
                const response = await fetch(poLinesUrl.replace('__ID__', orderId), { headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('failed');
                renderLines(await response.json());
            } catch (error) {
                body.innerHTML = '<tr><td colspan="8" class="text-danger">Gagal memuat baris pembelian. Silakan coba lagi.</td></tr>';
            } finally {
                loading.classList.add('d-none');
            }
        }

        initAjaxSelect(select, {
            endpoint: @json(route('goods-receipts.lookup.purchase-orders')),
            placeholder: 'Cari nomor pembelian…',
            onSelect: function (item) {
                document.getElementById('poSupplier').value = item.supplier || '';
                document.getElementById('poBranch').value = item.branch || '';
                loadLines(item.id);
            },
        });
        $(select).on('select2:clear', function () {
            document.getElementById('poSupplier').value = '';
            document.getElementById('poBranch').value = '';
            clearLines();
        });

        if (preselectedOrderId) {
            loadLines(preselectedOrderId);
        }
    })();
    </script>
    @endpush
@endsection
