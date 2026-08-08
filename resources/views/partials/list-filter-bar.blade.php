@php($extraFilterHtml = $extraFilterHtml ?? '')
<div class="card">
    <div class="card-body">
        <form method="GET" action="{{ url()->current() }}" id="listFilterBarForm" class="row g-2 align-items-center">
            <div class="col-12 col-md">
                <div class="input-group">
                    <span class="input-group-text bg-transparent border-0"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" value="{{ $searchValue }}" class="form-control border-0" placeholder="{{ $searchPlaceholder }}">
                </div>
            </div>
            @if ($branchFilterBranches !== null)
            <div class="col-auto">
                @include('partials.branch-multiselect-filter', ['allowedBranches' => $branchFilterBranches, 'selectedBranchIds' => $branchFilterSelected])
            </div>
            @endif
            @if ($extraFilterHtml !== '')
            <div class="col-auto">
                {{-- extraFilterHtml is always caller-authored (a rendered Blade partial), never
                     request/user-controlled data — if a future caller ever interpolates
                     user input into this string, it MUST be escaped before being passed in,
                     since this echoes raw. --}}
                {!! $extraFilterHtml !!}
            </div>
            @endif
            <div class="col-12 col-md-auto d-flex gap-2">
                <button type="submit" class="btn btn-outline-primary btn-sm">Terapkan</button>
                {{-- actionsHtml is always caller-authored (a static string + route()), never
                     request/user-controlled data — if a future caller ever interpolates
                     user input into this string, it MUST be escaped before being passed in,
                     since this echoes raw. --}}
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
