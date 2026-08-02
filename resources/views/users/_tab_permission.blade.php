<div class="card mb-4">
    <div class="card-body">
        <h2 class="h6 mb-3"><i class="bi bi-shop me-1"></i> Permission Operasional per Cabang</h2>

        @if ($assignedBranches->isEmpty())
            <p class="text-muted small mb-0">Tetapkan cabang dulu di tab Cabang sebelum mengatur permission operasional.</p>
        @else
            <ul class="nav nav-tabs mb-3" role="tablist">
                @foreach ($assignedBranches as $index => $branch)
                    <li class="nav-item" role="presentation">
                        <button class="nav-link {{ $index === 0 ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#branch-perm-{{ $branch->id }}" type="button" role="tab">
                            {{ $branch->name }}
                        </button>
                    </li>
                @endforeach
            </ul>

            <div class="tab-content">
                @foreach ($assignedBranches as $index => $branch)
                    @php($grantedForBranch = $grantedBranchPermissionIds->get($branch->id, []))
                    <div class="tab-pane fade {{ $index === 0 ? 'show active' : '' }}" id="branch-perm-{{ $branch->id }}" role="tabpanel">
                        <div class="accordion" id="branchPermAccordion{{ $branch->id }}">
                            @foreach ($branchScopedMenus as $menu)
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#branch-{{ $branch->id }}-menu-{{ $menu->id }}">
                                            {{ $menu->name }}
                                            <span class="badge bg-secondary ms-2 menu-count" data-menu-id="{{ $branch->id }}-{{ $menu->id }}">
                                                {{ $menu->permissions->whereIn('id', $grantedForBranch)->count() }}/{{ $menu->permissions->count() }}
                                            </span>
                                        </button>
                                    </h2>
                                    <div id="branch-{{ $branch->id }}-menu-{{ $menu->id }}" class="accordion-collapse collapse" data-bs-parent="#branchPermAccordion{{ $branch->id }}">
                                        <div class="accordion-body">
                                            <div class="form-check mb-2">
                                                <input type="checkbox" class="form-check-input branch-menu-select-all" id="branch-{{ $branch->id }}-menu-all-{{ $menu->id }}" data-menu-key="{{ $branch->id }}-{{ $menu->id }}">
                                                <label class="form-check-label fw-semibold" for="branch-{{ $branch->id }}-menu-all-{{ $menu->id }}">Pilih semua</label>
                                            </div>
                                            <hr>
                                            @foreach ($menu->permissions as $permission)
                                                <div class="form-check">
                                                    <input type="checkbox" class="form-check-input branch-permission-toggle" id="branch-{{ $branch->id }}-permission-{{ $permission->id }}"
                                                        data-branch-id="{{ $branch->id }}" data-permission-id="{{ $permission->id }}" data-menu-key="{{ $branch->id }}-{{ $menu->id }}"
                                                        {{ in_array($permission->id, $grantedForBranch) ? 'checked' : '' }}>
                                                    <label class="form-check-label" for="branch-{{ $branch->id }}-permission-{{ $permission->id }}">
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
                    </div>
                @endforeach
            </div>
        @endif

        <div id="branch-permission-feedback" class="small mt-3"></div>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h2 class="h6 mb-3"><i class="bi bi-shield-check me-1"></i> Permission Administrasi &amp; Master Data</h2>
        <p class="text-muted small">Permission ini berlaku global, tidak tergantung cabang.</p>

        <div class="accordion" id="permissionAccordion">
            @foreach ($globalMenus as $menu)
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

    function send(url, method) {
        return fetch(url, {
            method,
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
        }).then(async (response) => {
            const data = await response.json();
            if (!response.ok) {
                throw new Error(data.message || 'Terjadi kesalahan.');
            }
            return data;
        });
    }

    // Global (Administrasi/Master Data) permissions — unchanged mechanism.
    const globalFeedback = document.getElementById('permission-feedback');

    function showGlobalFeedback(message, isError) {
        globalFeedback.textContent = message;
        globalFeedback.className = 'small mt-3 ' + (isError ? 'text-danger' : 'text-success');
    }

    function updateMenuCount(menuId) {
        const badge = document.querySelector('.menu-count[data-menu-id="' + menuId + '"]');
        const checkboxes = document.querySelectorAll('.permission-toggle[data-menu-id="' + menuId + '"]');
        const checked = document.querySelectorAll('.permission-toggle[data-menu-id="' + menuId + '"]:checked');
        badge.textContent = checked.length + '/' + checkboxes.length;
    }

    document.querySelectorAll('.permission-toggle').forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            const permissionId = this.dataset.permissionId;
            const menuId = this.dataset.menuId;
            const request = this.checked
                ? send(`/users/${userId}/permissions/${permissionId}`, 'POST')
                : send(`/users/${userId}/permissions/${permissionId}`, 'DELETE');
            request.then((data) => {
                showGlobalFeedback(data.message, false);
                updateMenuCount(menuId);
            }).catch((error) => {
                this.checked = !this.checked;
                showGlobalFeedback(error.message, true);
            });
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

    // Branch-scoped (Operasional/Persediaan/Laporan) permissions.
    const branchFeedback = document.getElementById('branch-permission-feedback');

    function showBranchFeedback(message, isError) {
        branchFeedback.textContent = message;
        branchFeedback.className = 'small mt-3 ' + (isError ? 'text-danger' : 'text-success');
    }

    function updateBranchMenuCount(menuKey) {
        const badge = document.querySelector('.menu-count[data-menu-id="' + menuKey + '"]');
        const checkboxes = document.querySelectorAll('.branch-permission-toggle[data-menu-key="' + menuKey + '"]');
        const checked = document.querySelectorAll('.branch-permission-toggle[data-menu-key="' + menuKey + '"]:checked');
        badge.textContent = checked.length + '/' + checkboxes.length;
    }

    document.querySelectorAll('.branch-permission-toggle').forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            const branchId = this.dataset.branchId;
            const permissionId = this.dataset.permissionId;
            const menuKey = this.dataset.menuKey;
            const request = this.checked
                ? send(`/users/${userId}/branches/${branchId}/permissions/${permissionId}`, 'POST')
                : send(`/users/${userId}/branches/${branchId}/permissions/${permissionId}`, 'DELETE');
            request.then((data) => {
                showBranchFeedback(data.message, false);
                updateBranchMenuCount(menuKey);
            }).catch((error) => {
                this.checked = !this.checked;
                showBranchFeedback(error.message, true);
            });
        });
    });

    document.querySelectorAll('.branch-menu-select-all').forEach(function (selectAll) {
        selectAll.addEventListener('change', function () {
            const menuKey = this.dataset.menuKey;
            document.querySelectorAll('.branch-permission-toggle[data-menu-key="' + menuKey + '"]').forEach(function (checkbox) {
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
