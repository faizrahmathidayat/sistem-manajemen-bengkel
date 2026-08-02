<div class="card" style="background: rgba(255, 255, 255, .72); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);">
    <div class="card-body">
        <form method="GET" action="{{ url()->current() }}" id="listFilterBarForm" class="row g-2 align-items-center">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text bg-transparent border-0"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" value="{{ $searchValue }}" class="form-control border-0" placeholder="{{ $searchPlaceholder }}">
                </div>
            </div>
            @if ($branchFilterBranches !== null)
            <div class="col-md-3">
                @include('partials.branch-multiselect-filter', ['allowedBranches' => $branchFilterBranches, 'selectedBranchIds' => $branchFilterSelected])
            </div>
            @endif
            <div class="col-md-4 text-md-end">
                <button type="submit" class="btn btn-outline-primary btn-sm">Terapkan</button>
                {!! $actionsHtml !!}
            </div>
        </form>
    </div>
</div>

@if ($branchFilterBranches !== null)
@push('scripts')
<script>
(function () {
    const menu = document.getElementById('branchFilterMenu');
    const form = document.getElementById('listFilterBarForm');
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
@endif
