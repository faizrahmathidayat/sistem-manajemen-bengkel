<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div class="row g-2 flex-grow-1">
        <div class="col-md-4">
            <input type="text" class="form-control form-control-sm" id="pkbInvoiceSearch" placeholder="Cari No. PKB/Invoice, Customer, No. Polisi..." value="{{ $pkbInvoiceFilters['q'] ?? '' }}">
        </div>
        <div class="col-md-3">
            <select class="form-select form-select-sm" id="pkbInvoiceStatus">
                <option value="">Semua Status</option>
                <optgroup label="PKB">
                    <option value="pkb:draft" {{ ($pkbInvoiceFilters['status'] ?? '') === 'pkb:draft' ? 'selected' : '' }}>Draft</option>
                    <option value="pkb:open" {{ ($pkbInvoiceFilters['status'] ?? '') === 'pkb:open' ? 'selected' : '' }}>Dikonfirmasi</option>
                    <option value="pkb:shortage" {{ ($pkbInvoiceFilters['status'] ?? '') === 'pkb:shortage' ? 'selected' : '' }}>Kurang Stok</option>
                    <option value="pkb:completed" {{ ($pkbInvoiceFilters['status'] ?? '') === 'pkb:completed' ? 'selected' : '' }}>Selesai</option>
                </optgroup>
                <optgroup label="Invoice">
                    <option value="invoice:draft" {{ ($pkbInvoiceFilters['status'] ?? '') === 'invoice:draft' ? 'selected' : '' }}>Draft</option>
                    <option value="invoice:posted" {{ ($pkbInvoiceFilters['status'] ?? '') === 'invoice:posted' ? 'selected' : '' }}>Diposting</option>
                    <option value="invoice:partially_paid" {{ ($pkbInvoiceFilters['status'] ?? '') === 'invoice:partially_paid' ? 'selected' : '' }}>Dibayar Sebagian</option>
                    <option value="invoice:paid" {{ ($pkbInvoiceFilters['status'] ?? '') === 'invoice:paid' ? 'selected' : '' }}>Lunas</option>
                </optgroup>
            </select>
        </div>
        <div class="col-md-2">
            <input type="date" class="form-control form-control-sm" id="pkbInvoiceDateFrom" value="{{ $pkbInvoiceFilters['dateFrom'] ?? '' }}">
        </div>
        <div class="col-md-2">
            <input type="date" class="form-control form-control-sm" id="pkbInvoiceDateTo" value="{{ $pkbInvoiceFilters['dateTo'] ?? '' }}">
        </div>
    </div>
    <div class="d-flex gap-1">
        <a href="{{ route('work-orders.index') }}" class="btn btn-outline-secondary btn-sm">Semua PKB</a>
        <a href="{{ route('invoices.index') }}" class="btn btn-outline-secondary btn-sm">Semua Invoice</a>
    </div>
</div>
<div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
            <tr>
                <th>No. PKB/Invoice</th>
                <th>Customer &amp; No. Polisi</th>
                <th>Cabang</th>
                <th>Status</th>
                <th class="text-end">Aksi</th>
            </tr>
        </thead>
        <tbody id="pkbInvoiceTabBody">
            @forelse ($pkbInvoiceRows as $row)
                <tr>
                    <td><span class="badge {{ $row['type'] === 'pkb' ? 'bg-primary' : 'bg-success' }} me-1">{{ $row['typeLabel'] }}</span><code>{{ $row['number'] }}</code></td>
                    <td>{{ $row['customer'] }} &middot; {{ $row['plate'] }}</td>
                    <td>{{ $row['branch'] }}</td>
                    <td><span class="status-dot status-active">{{ $row['statusLabel'] }}</span></td>
                    <td class="text-end"><a href="{{ $row['url'] }}" class="btn btn-outline-secondary btn-sm">Lihat</a></td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted text-center py-3">Tidak ada data PKB/Invoice yang cocok.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
