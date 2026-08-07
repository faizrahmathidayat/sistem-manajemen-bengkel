@extends('layouts.app')
@section('title', 'Laporan Sparepart')
@section('content')
    <div class="mb-4 d-flex justify-content-between align-items-center">
        <h1 class="h4 mb-0"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Laporan Sparepart</h1>
        @include('partials.report-export-buttons', [
            'excelRoute' => 'reports.sparepart-stock.export-excel',
            'pdfPreviewRoute' => 'reports.sparepart-stock.pdf-preview',
            'pdfDownloadRoute' => 'reports.sparepart-stock.pdf-download',
        ])
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('reports.sparepart-stock.index') }}" id="sparepartStockFilterForm" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Cabang</label>
                    @include('partials.branch-multiselect-filter', ['allowedBranches' => $branches, 'selectedBranchIds' => $selectedBranchIds])
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Status Stok</label>
                    <select name="stock_status" class="form-select form-select-sm">
                        <option value="semua" {{ $stockStatus === 'semua' ? 'selected' : '' }}>Semua</option>
                        <option value="kritis" {{ $stockStatus === 'kritis' ? 'selected' : '' }}>Kritis/Minimum</option>
                        <option value="habis" {{ $stockStatus === 'habis' ? 'selected' : '' }}>Habis</option>
                        <option value="tersedia" {{ $stockStatus === 'tersedia' ? 'selected' : '' }}>Tersedia</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Kode / Nama Sparepart</label>
                    <input type="text" name="search" value="{{ $search }}" class="form-control form-control-sm" placeholder="Cari...">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Tampilan</label>
                    <select name="mode" class="form-select form-select-sm">
                        <option value="rekap" {{ $mode === 'rekap' ? 'selected' : '' }}>Rekap</option>
                        <option value="detail" {{ $mode === 'detail' ? 'selected' : '' }}>Detail</option>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-outline-primary btn-sm mt-2">Terapkan Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_jenis_item, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Jenis Item</div>
                </div>
                <i class="bi bi-box-seam stat-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_qty_on_hand, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Qty On-Hand</div>
                </div>
                <i class="bi bi-boxes stat-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_item_kritis, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Item Kritis</div>
                </div>
                <i class="bi bi-exclamation-triangle stat-icon"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_nilai_inventaris, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Nilai Inventaris</div>
                </div>
                <i class="bi bi-cash-stack stat-icon"></i>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
        @if ($mode === 'detail')
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Kode</th>
                        <th>Nama Sparepart</th>
                        <th>Cabang</th>
                        <th>Stok Min</th>
                        <th>On-Hand</th>
                        <th>Reserved</th>
                        <th>Available</th>
                        <th>Harga Satuan</th>
                        <th>Nilai Total</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sparepartBranches as $sparepartBranch)
                        @php
                            $onHand = (float) $sparepartBranch->on_hand_qty;
                            $reserved = (float) $sparepartBranch->reserved_qty;
                            $available = $onHand - $reserved;
                            $minimumStock = (float) $sparepartBranch->minimum_stock;
                            $sellingPrice = (float) $sparepartBranch->selling_price;
                            $nilaiTotal = $onHand * $sellingPrice;

                            if ($onHand == 0.0) {
                                $statusBadge = '<span class="status-dot status-danger">Habis</span>';
                            } elseif ($minimumStock > 0.0 && $available < $minimumStock) {
                                $statusBadge = '<span class="status-dot status-warning">Kritis</span>';
                            } else {
                                $statusBadge = '<span class="status-dot status-active">Tersedia</span>';
                            }
                        @endphp
                        <tr>
                            <td><code>{{ $sparepartBranch->sparepart->code }}</code></td>
                            <td>{{ $sparepartBranch->sparepart->name }}</td>
                            <td>{{ $sparepartBranch->branch->name }}</td>
                            <td>{{ number_format($minimumStock, 0, ',', '.') }}</td>
                            <td>{{ number_format($onHand, 0, ',', '.') }}</td>
                            <td>{{ number_format($reserved, 0, ',', '.') }}</td>
                            <td>{{ number_format($available, 0, ',', '.') }}</td>
                            <td>{{ number_format($sellingPrice, 0, ',', '.') }}</td>
                            <td>{{ number_format($nilaiTotal, 0, ',', '.') }}</td>
                            <td>{!! $statusBadge !!}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-file-earmark-spreadsheet',
                                    'title' => 'Belum ada data sparepart',
                                    'description' => 'Tidak ada sparepart yang cocok dengan filter saat ini.',
                                    'ctaVisible' => false,
                                    'ctaRoute' => '',
                                    'ctaLabel' => '',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        @else
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Kode</th>
                        <th>Nama Sparepart</th>
                        <th>Cabang</th>
                        <th>Stok Min</th>
                        <th>Stok On-Hand</th>
                        <th>Nilai Inventaris</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sparepartBranches as $sparepartBranch)
                        @php
                            $onHand = (float) $sparepartBranch->on_hand_qty;
                            $reserved = (float) $sparepartBranch->reserved_qty;
                            $available = $onHand - $reserved;
                            $minimumStock = (float) $sparepartBranch->minimum_stock;
                            $sellingPrice = (float) $sparepartBranch->selling_price;
                            $nilaiInventaris = $onHand * $sellingPrice;

                            if ($onHand == 0.0) {
                                $statusBadge = '<span class="status-dot status-danger">Habis</span>';
                            } elseif ($minimumStock > 0.0 && $available < $minimumStock) {
                                $statusBadge = '<span class="status-dot status-warning">Kritis</span>';
                            } else {
                                $statusBadge = '<span class="status-dot status-active">Tersedia</span>';
                            }
                        @endphp
                        <tr>
                            <td><code>{{ $sparepartBranch->sparepart->code }}</code></td>
                            <td>{{ $sparepartBranch->sparepart->name }}</td>
                            <td>{{ $sparepartBranch->branch->name }}</td>
                            <td>{{ number_format($minimumStock, 0, ',', '.') }}</td>
                            <td>{{ number_format($onHand, 0, ',', '.') }}</td>
                            <td>{{ number_format($nilaiInventaris, 0, ',', '.') }}</td>
                            <td>{!! $statusBadge !!}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-file-earmark-spreadsheet',
                                    'title' => 'Belum ada data sparepart',
                                    'description' => 'Tidak ada sparepart yang cocok dengan filter saat ini.',
                                    'ctaVisible' => false,
                                    'ctaRoute' => '',
                                    'ctaLabel' => '',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        @endif
        </div>
    </div>

    <div class="mt-3">
        {{ $sparepartBranches->links() }}
    </div>

    @push('scripts')
    <script>
    (function () {
        const menu = document.getElementById('branchFilterMenu');
        const form = document.getElementById('sparepartStockFilterForm');
        if (!menu || !form) return;

        menu.addEventListener('click', function (event) { event.stopPropagation(); });

        const selectAll = document.getElementById('branchFilterSelectAll');
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                document.querySelectorAll('.branch-filter-checkbox').forEach(function (checkbox) {
                    checkbox.checked = selectAll.checked;
                });
            });
        }

        form.addEventListener('submit', function () {
            form.querySelectorAll('input[data-branch-hidden]').forEach(function (el) { el.remove(); });
            document.querySelectorAll('.branch-filter-checkbox:checked').forEach(function (checkbox) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'branch_ids[]';
                hidden.value = checkbox.value;
                hidden.setAttribute('data-branch-hidden', '1');
                form.appendChild(hidden);
            });
        });
    })();
    </script>
    @endpush
@endsection
