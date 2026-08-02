<div class="row g-2 mb-3">
    <div class="col-md-4">
        <input type="text" class="form-control form-control-sm" placeholder="Cari No. PKB/Invoice, Customer, No. Polisi..." disabled>
    </div>
    <div class="col-md-3">
        <select class="form-select form-select-sm" disabled>
            <option>Semua Status</option>
        </select>
    </div>
    <div class="col-md-3">
        <input type="text" class="form-control form-control-sm" placeholder="Rentang Tanggal" disabled>
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
            @foreach ($pkbInvoiceRows as $row)
                <tr>
                    <td><code>{{ $row['number'] }}</code></td>
                    <td>{{ $row['customer'] }} &middot; {{ $row['plate'] }}</td>
                    <td>{{ $row['branch'] }}</td>
                    <td><span class="status-dot status-active">{{ $row['status'] }}</span></td>
                    <td class="text-end text-muted small">&mdash;</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
