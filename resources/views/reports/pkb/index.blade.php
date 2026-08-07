@extends('layouts.app')
@section('title', 'Laporan PKB')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-file-earmark-bar-graph me-2"></i>Laporan PKB</h1>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('reports.pkb.index') }}" id="pkbReportFilterForm" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Cabang</label>
                    @include('partials.branch-multiselect-filter', ['allowedBranches' => $branches, 'selectedBranchIds' => $selectedBranchIds])
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Status PKB</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">Semua Status</option>
                        <option value="{{ \App\Support\WorkOrderStatus::DRAFT }}" {{ $status === \App\Support\WorkOrderStatus::DRAFT ? 'selected' : '' }}>Draft</option>
                        <option value="{{ \App\Support\WorkOrderStatus::OPEN }}" {{ $status === \App\Support\WorkOrderStatus::OPEN ? 'selected' : '' }}>Dikonfirmasi</option>
                        <option value="{{ \App\Support\WorkOrderStatus::SHORTAGE }}" {{ $status === \App\Support\WorkOrderStatus::SHORTAGE ? 'selected' : '' }}>Kurang Stok</option>
                        <option value="{{ \App\Support\WorkOrderStatus::COMPLETED }}" {{ $status === \App\Support\WorkOrderStatus::COMPLETED ? 'selected' : '' }}>Selesai</option>
                        <option value="{{ \App\Support\WorkOrderStatus::CANCELLED }}" {{ $status === \App\Support\WorkOrderStatus::CANCELLED ? 'selected' : '' }}>Dibatalkan</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Mekanik</label>
                    <input type="text" name="mechanic" value="{{ $mechanicSearch }}" class="form-control form-control-sm" placeholder="Cari nama mekanik...">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Tanggal Dari</label>
                    <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Sampai</label>
                    <input type="date" name="date_to" value="{{ $dateTo }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
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
        <div class="col-md-4">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_pkb, 0, ',', '.') }}</div>
                    <div class="stat-label">Total PKB</div>
                </div>
                <i class="bi bi-file-earmark-bar-graph stat-icon"></i>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_value, 0, ',', '.') }}</div>
                    <div class="stat-label">Total Nilai PKB</div>
                </div>
                <i class="bi bi-cash-stack stat-icon"></i>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ number_format($summary->total_completed, 0, ',', '.') }}</div>
                    <div class="stat-label">Total PKB Selesai</div>
                </div>
                <i class="bi bi-check-circle stat-icon"></i>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
        @if ($mode === 'detail')
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. PKB</th>
                        <th>Tanggal</th>
                        <th>Customer &amp; Kendaraan</th>
                        <th>Tipe Item</th>
                        <th>Nama Item/Jasa</th>
                        <th>Qty</th>
                        <th>Harga Satuan</th>
                        <th>Subtotal Line</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($workOrders as $workOrder)
                        @php
                            switch ($workOrder->status) {
                                case \App\Support\WorkOrderStatus::DRAFT:
                                    $statusBadge = '<span class="status-dot status-inactive">Draft</span>';
                                    break;
                                case \App\Support\WorkOrderStatus::OPEN:
                                    $statusBadge = '<span class="status-dot status-active">Dikonfirmasi</span>';
                                    break;
                                case \App\Support\WorkOrderStatus::SHORTAGE:
                                    $statusBadge = '<span class="status-dot status-warning">Kurang Stok</span>';
                                    break;
                                case \App\Support\WorkOrderStatus::COMPLETED:
                                    $statusBadge = '<span class="status-dot status-active">Selesai</span>';
                                    break;
                                default:
                                    $statusBadge = '<span class="status-dot status-danger">Dibatalkan</span>';
                            }
                            $lines = $workOrder->serviceLines->map(function ($line) {
                                return ['type' => 'Jasa', 'name' => $line->description, 'qty' => $line->qty, 'price' => $line->unit_price, 'total' => $line->line_total];
                            })->concat($workOrder->sparepartLines->map(function ($line) {
                                return ['type' => 'Sparepart', 'name' => $line->item_name_snapshot, 'qty' => $line->qty, 'price' => $line->unit_price, 'total' => $line->line_total];
                            }));
                        @endphp
                        @if ($lines->isEmpty())
                            <tr>
                                <td><a href="{{ route('work-orders.show', $workOrder) }}"><code>{{ $workOrder->number }}</code></a></td>
                                <td>{{ $workOrder->work_order_date->format('d/m/Y') }}</td>
                                <td>
                                    {{ $workOrder->customer->name }}<br>
                                    <span class="text-muted small">{{ $workOrder->vehicle->plate_number }}</span>
                                </td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>&mdash;</td>
                                <td>{!! $statusBadge !!}</td>
                            </tr>
                        @else
                            @foreach ($lines as $line)
                                <tr>
                                    <td><a href="{{ route('work-orders.show', $workOrder) }}"><code>{{ $workOrder->number }}</code></a></td>
                                    <td>{{ $workOrder->work_order_date->format('d/m/Y') }}</td>
                                    <td>
                                        {{ $workOrder->customer->name }}<br>
                                        <span class="text-muted small">{{ $workOrder->vehicle->plate_number }}</span>
                                    </td>
                                    <td>{{ $line['type'] }}</td>
                                    <td>{{ $line['name'] }}</td>
                                    <td>{{ number_format($line['qty'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($line['price'], 0, ',', '.') }}</td>
                                    <td>{{ number_format($line['total'], 0, ',', '.') }}</td>
                                    <td>{!! $statusBadge !!}</td>
                                </tr>
                            @endforeach
                        @endif
                    @empty
                        <tr>
                            <td colspan="9" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-file-earmark-bar-graph',
                                    'title' => 'Belum ada data PKB',
                                    'description' => 'Tidak ada PKB yang cocok dengan filter saat ini.',
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
                        <th>No. PKB</th>
                        <th>Tanggal</th>
                        <th>Customer &amp; Kendaraan</th>
                        <th>Mekanik</th>
                        <th>Subtotal Jasa</th>
                        <th>Subtotal Sparepart</th>
                        <th>Grand Total</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($workOrders as $workOrder)
                        @php($subtotalService = $workOrder->subtotal_service ?? 0)
                        @php($subtotalSparepart = $workOrder->subtotal_sparepart ?? 0)
                        <tr>
                            <td><a href="{{ route('work-orders.show', $workOrder) }}"><code>{{ $workOrder->number }}</code></a></td>
                            <td>{{ $workOrder->work_order_date->format('d/m/Y') }}</td>
                            <td>
                                {{ $workOrder->customer->name }}<br>
                                <span class="text-muted small">{{ $workOrder->vehicle->plate_number }}</span>
                            </td>
                            <td>{{ $workOrder->mechanic->name }}</td>
                            <td>{{ number_format($subtotalService, 0, ',', '.') }}</td>
                            <td>{{ number_format($subtotalSparepart, 0, ',', '.') }}</td>
                            <td>{{ number_format($subtotalService + $subtotalSparepart, 0, ',', '.') }}</td>
                            <td>
                                @if ($workOrder->status === \App\Support\WorkOrderStatus::DRAFT)
                                    <span class="status-dot status-inactive">Draft</span>
                                @elseif ($workOrder->status === \App\Support\WorkOrderStatus::OPEN)
                                    <span class="status-dot status-active">Dikonfirmasi</span>
                                @elseif ($workOrder->status === \App\Support\WorkOrderStatus::SHORTAGE)
                                    <span class="status-dot status-warning">Kurang Stok</span>
                                @elseif ($workOrder->status === \App\Support\WorkOrderStatus::COMPLETED)
                                    <span class="status-dot status-active">Selesai</span>
                                @else
                                    <span class="status-dot status-danger">Dibatalkan</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-file-earmark-bar-graph',
                                    'title' => 'Belum ada data PKB',
                                    'description' => 'Tidak ada PKB yang cocok dengan filter saat ini.',
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
        {{ $workOrders->links() }}
    </div>

    @push('scripts')
    <script>
    (function () {
        const menu = document.getElementById('branchFilterMenu');
        const form = document.getElementById('pkbReportFilterForm');
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
