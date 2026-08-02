<div class="card shadow-sm">
    <div class="card-body">
        <p class="text-muted small">Centang cabang yang dapat menugaskan mekanik ini.</p>

        <div id="mechanic-branch-list">
            @foreach ($allBranches as $branch)
                @php($mechanicBranch = $mechanic->mechanicBranches->firstWhere('branch_id', $branch->id))
                <div class="d-flex align-items-center justify-content-between border-bottom py-2" data-branch-row="{{ $branch->id }}">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input mechanic-branch-toggle" id="branch-{{ $branch->id }}"
                            data-branch-id="{{ $branch->id }}"
                            {{ $mechanicBranch && $mechanicBranch->is_active ? 'checked' : '' }}>
                        <label class="form-check-label" for="branch-{{ $branch->id }}">{{ $branch->name }}</label>
                    </div>
                </div>
            @endforeach
        </div>

        <div id="mechanic-branch-feedback" class="small mt-3"></div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const mechanicId = {{ $mechanic->id }};
    const feedback = document.getElementById('mechanic-branch-feedback');

    function showFeedback(message, isError) {
        feedback.textContent = message;
        feedback.className = 'small mt-3 ' + (isError ? 'text-danger' : 'text-success');
    }

    async function send(url, method) {
        const response = await fetch(url, {
            method,
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
        });
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.message || 'Terjadi kesalahan.');
        }
        return data;
    }

    document.querySelectorAll('.mechanic-branch-toggle').forEach(function (checkbox) {
        checkbox.addEventListener('change', async function () {
            const branchId = this.dataset.branchId;
            try {
                if (this.checked) {
                    const data = await send(`/mechanics/${mechanicId}/branches/${branchId}`, 'POST');
                    showFeedback(data.message, false);
                } else {
                    const data = await send(`/mechanics/${mechanicId}/branches/${branchId}`, 'DELETE');
                    showFeedback(data.message, false);
                }
            } catch (error) {
                this.checked = !this.checked;
                showFeedback(error.message, true);
            }
        });
    });
})();
</script>
@endpush
