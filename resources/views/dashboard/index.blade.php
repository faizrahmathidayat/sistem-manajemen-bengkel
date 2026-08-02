@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
        <div>
            <h1 class="h4 mb-1">Dashboard</h1>
            <p class="mb-0" style="color: var(--color-ink-muted);">Selamat datang kembali, {{ auth()->user()->name }}.</p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            @include('partials.branch-multiselect-filter')
            @if (auth()->user()->branchesWithPermission('sparepart.view')->isNotEmpty())
                <a href="{{ route('sparepart-branches.create') }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg"></i> Sparepart Baru
                </a>
            @endif
            <span class="btn btn-outline-secondary btn-sm disabled" style="cursor: not-allowed;" aria-disabled="true">
                <i class="bi bi-clipboard-plus"></i> Buat PKB Baru
                <span class="badge-soon">Segera Hadir</span>
            </span>
        </div>
    </div>

    <div id="dashboardContent">
        <p class="text-muted">Data ringkasan akan tampil di sini.</p>
    </div>
@endsection

@push('scripts')
<script>
(function () {
    const selectAll = document.getElementById('branchFilterSelectAll');
    const menu = document.getElementById('branchFilterMenu');

    if (menu) {
        menu.addEventListener('click', function (event) {
            event.stopPropagation();
        });
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.branch-filter-checkbox').forEach(function (checkbox) {
                checkbox.checked = selectAll.checked;
            });
            applyBranchFilter();
        });
    }

    document.querySelectorAll('.branch-filter-checkbox').forEach(function (checkbox) {
        checkbox.addEventListener('change', applyBranchFilter);
    });

    function applyBranchFilter() {
        const selected = Array.from(document.querySelectorAll('.branch-filter-checkbox:checked')).map(function (cb) {
            return cb.value;
        });
        const params = new URLSearchParams();
        selected.forEach(function (id) { params.append('branch_ids[]', id); });
        window.location.search = params.toString();
    }
})();
</script>
@endpush
