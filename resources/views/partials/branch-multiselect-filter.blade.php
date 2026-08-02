@if ($allowedBranches->isEmpty())
    <p class="text-muted small mb-0">Anda belum ditugaskan ke cabang manapun.</p>
@else
    <div class="dropdown" id="branchMultiselectFilter">
        <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button" id="branchFilterToggle" data-bs-toggle="dropdown" aria-expanded="false">
            <span id="branchFilterLabel">
                @if (count($selectedBranchIds) === $allowedBranches->count())
                    Semua Cabang Saya
                @elseif (count($selectedBranchIds) === 1)
                    {{ $allowedBranches->firstWhere('id', $selectedBranchIds[0])->name ?? '1 Cabang Terpilih' }}
                @else
                    {{ count($selectedBranchIds) }} Cabang Terpilih
                @endif
            </span>
        </button>
        <div class="dropdown-menu p-3" aria-labelledby="branchFilterToggle" id="branchFilterMenu" style="min-width: 240px;">
            <div class="form-check mb-2">
                <input type="checkbox" class="form-check-input" id="branchFilterSelectAll" {{ count($selectedBranchIds) === $allowedBranches->count() ? 'checked' : '' }}>
                <label class="form-check-label fw-semibold" for="branchFilterSelectAll">Pilih Semua Cabang Saya</label>
            </div>
            <hr>
            @foreach ($allowedBranches as $branch)
                <div class="form-check">
                    <input type="checkbox" class="form-check-input branch-filter-checkbox" id="branchFilter-{{ $branch->id }}" value="{{ $branch->id }}" {{ in_array($branch->id, $selectedBranchIds) ? 'checked' : '' }}>
                    <label class="form-check-label" for="branchFilter-{{ $branch->id }}">{{ $branch->name }}</label>
                </div>
            @endforeach
        </div>
    </div>
@endif
