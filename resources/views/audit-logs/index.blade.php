@extends('layouts.app')
@section('title', 'Audit Log')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-journal-text"></i></span>
            <div>
                <p class="eyebrow mb-1">Administrasi</p>
                <h1 class="h3 mb-1">Audit Log</h1>
                <p class="text-muted mb-0">Riwayat aktivitas pengguna di sistem.</p>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('audit-logs.index') }}" id="auditLogFilterForm" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Cabang</label>
                    @include('partials.branch-multiselect-filter', ['allowedBranches' => $branches, 'selectedBranchIds' => $selectedBranchIds])
                </div>
                <div class="col-md-3">
                    <label class="form-label small">User</label>
                    <input type="text" name="user" value="{{ $userSearch }}" class="form-control form-control-sm" placeholder="Cari nama user...">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Modul/Event</label>
                    <select name="event" class="form-select form-select-sm">
                        <option value="">-- Semua --</option>
                        @foreach (\App\Support\AuditEvent::LABELS as $value => $label)
                            <option value="{{ $value }}" {{ $event === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Tanggal Dari</label>
                    <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Sampai</label>
                    <input type="date" name="date_to" value="{{ $dateTo }}" class="form-control form-control-sm">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-outline-primary btn-sm mt-2">Terapkan Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Waktu</th>
                        <th>User</th>
                        <th>Cabang</th>
                        <th>Event</th>
                        <th>Perubahan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td>{{ $log->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ optional($log->user)->name ?? 'Sistem' }}</td>
                            <td>{{ optional($log->branch)->name ?? '-' }}</td>
                            <td>{{ \App\Support\AuditEvent::LABELS[$log->event] ?? $log->event }}</td>
                            <td>
                                @php($keys = array_unique(array_merge(array_keys($log->old_values ?? []), array_keys($log->new_values ?? []))))
                                @if (count($keys))
                                    <table class="table table-sm table-borderless mb-0">
                                        <thead><tr><th>Field</th><th>Sebelum</th><th>Sesudah</th></tr></thead>
                                        <tbody>
                                            @foreach ($keys as $key)
                                                <tr>
                                                    <td>{{ $key }}</td>
                                                    <td>{{ $log->old_values[$key] ?? '-' }}</td>
                                                    <td>{{ $log->new_values[$key] ?? '-' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-journal-text',
                                    'title' => 'Belum ada audit log',
                                    'description' => 'Tidak ada aktivitas yang cocok dengan filter saat ini.',
                                    'ctaVisible' => false,
                                    'ctaRoute' => '',
                                    'ctaLabel' => '',
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $logs->links() }}
    </div>

    @push('scripts')
    <script>
    (function () {
        const menu = document.getElementById('branchFilterMenu');
        const form = document.getElementById('auditLogFilterForm');
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
