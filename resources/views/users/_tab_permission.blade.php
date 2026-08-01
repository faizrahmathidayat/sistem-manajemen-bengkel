<div class="card shadow-sm">
    <div class="card-body">
        <p class="text-muted small">Centang permission yang diberikan ke user ini, dikelompokkan per menu.</p>

        <div class="accordion" id="permissionAccordion">
            @foreach ($menus as $menu)
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#menu-{{ $menu->id }}">
                            {{ $menu->name }}
                            <span class="badge bg-secondary ms-2 menu-count" data-menu-id="{{ $menu->id }}">
                                {{ $menu->permissions->whereIn('id', $grantedPermissionIds)->count() }}/{{ $menu->permissions->count() }}
                            </span>
                        </button>
                    </h2>
                    <div id="menu-{{ $menu->id }}" class="accordion-collapse collapse" data-bs-parent="#permissionAccordion">
                        <div class="accordion-body">
                            <div class="form-check mb-2">
                                <input type="checkbox" class="form-check-input menu-select-all" id="menu-all-{{ $menu->id }}" data-menu-id="{{ $menu->id }}">
                                <label class="form-check-label fw-semibold" for="menu-all-{{ $menu->id }}">Pilih semua</label>
                            </div>
                            <hr>
                            @foreach ($menu->permissions as $permission)
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input permission-toggle" id="permission-{{ $permission->id }}"
                                        data-permission-id="{{ $permission->id }}" data-menu-id="{{ $menu->id }}"
                                        {{ in_array($permission->id, $grantedPermissionIds) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="permission-{{ $permission->id }}">
                                        {{ $permission->description }}
                                        <code class="text-muted small">{{ $permission->code }}</code>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div id="permission-feedback" class="small mt-3"></div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const userId = {{ $user->id }};
    const feedback = document.getElementById('permission-feedback');

    function showFeedback(message, isError) {
        feedback.textContent = message;
        feedback.className = 'small mt-3 ' + (isError ? 'text-danger' : 'text-success');
    }

    function updateMenuCount(menuId) {
        const badge = document.querySelector('.menu-count[data-menu-id="' + menuId + '"]');
        const checkboxes = document.querySelectorAll('.permission-toggle[data-menu-id="' + menuId + '"]');
        const checked = document.querySelectorAll('.permission-toggle[data-menu-id="' + menuId + '"]:checked');
        badge.textContent = checked.length + '/' + checkboxes.length;
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

    document.querySelectorAll('.permission-toggle').forEach(function (checkbox) {
        checkbox.addEventListener('change', async function () {
            const permissionId = this.dataset.permissionId;
            const menuId = this.dataset.menuId;
            try {
                const data = this.checked
                    ? await send(`/users/${userId}/permissions/${permissionId}`, 'POST')
                    : await send(`/users/${userId}/permissions/${permissionId}`, 'DELETE');
                showFeedback(data.message, false);
                updateMenuCount(menuId);
            } catch (error) {
                this.checked = !this.checked;
                showFeedback(error.message, true);
            }
        });
    });

    document.querySelectorAll('.menu-select-all').forEach(function (selectAll) {
        selectAll.addEventListener('change', function () {
            const menuId = this.dataset.menuId;
            document.querySelectorAll('.permission-toggle[data-menu-id="' + menuId + '"]').forEach(function (checkbox) {
                if (checkbox.checked !== selectAll.checked) {
                    checkbox.checked = selectAll.checked;
                    checkbox.dispatchEvent(new Event('change'));
                }
            });
        });
    });
})();
</script>
@endpush
