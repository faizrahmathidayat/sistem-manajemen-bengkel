@extends('layouts.app')
@section('title', 'Laporan Laba Rugi')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-graph-up-arrow"></i></span>
            <div>
                <p class="eyebrow mb-1">Reporting</p>
                <h1 class="h3 mb-1">Laporan Laba Rugi</h1>
                <p class="text-muted mb-0">Pendapatan dikurangi HPP (weighted average cost) — belum termasuk biaya operasional.</p>
            </div>
        </div>
        <div class="heading-actions">
            @include('partials.report-export-buttons', [
                'excelRoute' => 'reports.gross-profit.export-excel',
                'pdfPreviewRoute' => 'reports.gross-profit.pdf-preview',
                'pdfDownloadRoute' => 'reports.gross-profit.pdf-download',
            ])
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('reports.gross-profit.index') }}" id="grossProfitFilterForm" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Cabang</label>
                    @include('partials.branch-multiselect-filter', ['allowedBranches' => $branches, 'selectedBranchIds' => $selectedBranchIds])
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
                    <select name="view_type" class="form-select form-select-sm">
                        <option value="summary" {{ $viewType === 'summary' ? 'selected' : '' }}>Ringkasan</option>
                        <option value="invoice_detail" {{ $viewType === 'invoice_detail' ? 'selected' : '' }}>Detail per Invoice</option>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-outline-primary btn-sm mt-2">Terapkan Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
        @if ($viewType === 'invoice_detail')
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. Invoice</th><th>Tanggal</th><th>Cabang</th><th>Customer</th>
                        <th>Pendapatan Jasa</th><th>Pendapatan Sparepart</th><th>Total HPP</th><th>Laba Kotor</th><th>Margin %</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        @php
                            $pendapatan = (float) $invoice->pendapatan_jasa + (float) $invoice->pendapatan_sparepart;
                            $labaKotor = $pendapatan - (float) $invoice->total_hpp;
                            $margin = $pendapatan > 0 ? ($labaKotor / $pendapatan) * 100 : 0;
                        @endphp
                        <tr>
                            <td><a href="{{ route('invoices.show', $invoice) }}"><code>{{ $invoice->number }}</code></a></td>
                            <td>{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                            <td>{{ $invoice->branch->name }}</td>
                            <td>{{ $invoice->customer->name }}</td>
                            <td>{{ number_format($invoice->pendapatan_jasa, 0, ',', '.') }}</td>
                            <td>{{ number_format($invoice->pendapatan_sparepart, 0, ',', '.') }}</td>
                            <td>{{ number_format($invoice->total_hpp, 0, ',', '.') }}</td>
                            <td>{{ number_format($labaKotor, 0, ',', '.') }}</td>
                            <td>{{ number_format($margin, 1, ',', '.') }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-graph-up-arrow',
                                    'title' => 'Belum ada data',
                                    'description' => 'Tidak ada invoice yang cocok dengan filter saat ini.',
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
                        <th>Periode</th><th>Cabang</th>
                        <th>Pendapatan Jasa</th><th>Pendapatan Sparepart</th><th>Total HPP</th><th>Laba Kotor</th><th>Margin %</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($summaryRows as $row)
                        @php
                            $pendapatan = (float) $row->pendapatan_jasa + (float) $row->pendapatan_sparepart;
                            $labaKotor = $pendapatan - (float) $row->total_hpp;
                            $margin = $pendapatan > 0 ? ($labaKotor / $pendapatan) * 100 : 0;
                            $branchName = optional($branches->firstWhere('id', $row->branch_id))->name ?? '-';
                        @endphp
                        <tr>
                            <td>{{ $row->period }}</td>
                            <td>{{ $branchName }}</td>
                            <td>{{ number_format($row->pendapatan_jasa, 0, ',', '.') }}</td>
                            <td>{{ number_format($row->pendapatan_sparepart, 0, ',', '.') }}</td>
                            <td>{{ number_format($row->total_hpp, 0, ',', '.') }}</td>
                            <td>{{ number_format($labaKotor, 0, ',', '.') }}</td>
                            <td>{{ number_format($margin, 1, ',', '.') }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-graph-up-arrow',
                                    'title' => 'Belum ada data',
                                    'description' => 'Tidak ada invoice yang cocok dengan filter saat ini.',
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
        {{ $viewType === 'invoice_detail' ? $invoices->links() : $summaryRows->links() }}
    </div>

    @push('scripts')
    <script>
    (function () {
        const menu = document.getElementById('branchFilterMenu');
        const form = document.getElementById('grossProfitFilterForm');
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
