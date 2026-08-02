<div class="card">
    <div class="card-body">
        <p class="text-muted small">Centang cabang yang boleh diakses user ini. Pilih salah satu sebagai cabang default.</p>

        <div id="branch-list">
            @foreach ($allBranches as $branch)
                @php($userBranch = $user->userBranches->firstWhere('branch_id', $branch->id))
                <div class="d-flex align-items-center justify-content-between border-bottom py-2" data-branch-row="{{ $branch->id }}">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input branch-toggle" id="branch-{{ $branch->id }}"
                            data-branch-id="{{ $branch->id }}"
                            {{ $userBranch && $userBranch->is_active ? 'checked' : '' }}>
                        <label class="form-check-label" for="branch-{{ $branch->id }}">{{ $branch->name }}</label>
                    </div>
                    <div class="form-check">
                        <input type="radio" class="form-check-input branch-default" name="default_branch"
                            data-branch-id="{{ $branch->id }}"
                            {{ $userBranch && $userBranch->is_default ? 'checked' : '' }}
                            {{ ! ($userBranch && $userBranch->is_active) ? 'disabled' : '' }}>
                        <label class="form-check-label small text-muted">Default</label>
                    </div>
                </div>
            @endforeach
        </div>

        <div id="branch-feedback" class="small mt-3"></div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const userId = {{ $user->id }};
    const feedback = document.getElementById('branch-feedback');

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

    document.querySelectorAll('.branch-toggle').forEach(function (checkbox) {
        checkbox.addEventListener('change', async function () {
            const branchId = this.dataset.branchId;
            const row = document.querySelector('[data-branch-row="' + branchId + '"]');
            const defaultRadio = row.querySelector('.branch-default');
            try {
                if (this.checked) {
                    const data = await send(`/users/${userId}/branches/${branchId}`, 'POST');
                    defaultRadio.disabled = false;
                    showFeedback(data.message, false);
                } else {
                    const data = await send(`/users/${userId}/branches/${branchId}`, 'DELETE');
                    defaultRadio.disabled = true;
                    defaultRadio.checked = false;
                    showFeedback(data.message, false);
                }
            } catch (error) {
                this.checked = !this.checked;
                showFeedback(error.message, true);
            }
        });
    });

    document.querySelectorAll('.branch-default').forEach(function (radio) {
        radio.addEventListener('change', async function () {
            const branchId = this.dataset.branchId;
            try {
                const data = await send(`/users/${userId}/branches/${branchId}/default`, 'PUT');
                showFeedback(data.message, false);
            } catch (error) {
                this.checked = false;
                showFeedback(error.message, true);
            }
        });
    });
})();
</script>
@endpush
