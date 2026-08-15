@extends('layouts.app')
@section('title', 'Referensi Kendaraan')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-diagram-3"></i></span>
            <div>
                <p class="eyebrow mb-1">Master Data</p>
                <h1 class="h3 mb-1">Referensi Kendaraan</h1>
                <p class="text-muted mb-0">Kelola kategori, merk, dan tipe kendaraan.</p>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-4 mb-3">
            <div class="card h-100">
                <div class="card-header bg-white fw-semibold">Kategori</div>
                <ul class="list-group list-group-flush" id="category-list"></ul>
                @can('vehicle_reference.manage')
                <div class="card-body border-top" id="category-add-row">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="category-add-toggle">
                        <i class="bi bi-plus-lg"></i> Tambah Kategori
                    </button>
                    <form id="category-add-form" class="d-none mt-2 d-flex gap-2">
                        <input type="text" class="form-control form-control-sm" id="category-add-name" maxlength="150" required>
                        <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
                    </form>
                </div>
                @endcan
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card h-100">
                <div class="card-header bg-white fw-semibold">Merk</div>
                <ul class="list-group list-group-flush" id="brand-list"></ul>
                @can('vehicle_reference.manage')
                <div class="card-body border-top" id="brand-add-row" style="display:none;">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="brand-add-toggle">
                        <i class="bi bi-plus-lg"></i> Tambah Merk
                    </button>
                    <form id="brand-add-form" class="d-none mt-2 d-flex gap-2">
                        <input type="text" class="form-control form-control-sm" id="brand-add-name" maxlength="150" required>
                        <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
                    </form>
                </div>
                @endcan
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card h-100">
                <div class="card-header bg-white fw-semibold">Tipe</div>
                <ul class="list-group list-group-flush" id="type-list"></ul>
                @can('vehicle_reference.manage')
                <div class="card-body border-top" id="type-add-row" style="display:none;">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="type-add-toggle">
                        <i class="bi bi-plus-lg"></i> Tambah Tipe
                    </button>
                    <form id="type-add-form" class="d-none mt-2 d-flex gap-2">
                        <input type="text" class="form-control form-control-sm" id="type-add-name" maxlength="150" required>
                        <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
                    </form>
                </div>
                @endcan
            </div>
        </div>
    </div>

    <div id="reference-feedback" class="small mt-2"></div>
@endsection

@push('scripts')
<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const canManage = @json(auth()->user()->can('vehicle_reference.manage'));
    let categories = @json($categories);
    let selectedCategoryId = null;
    let selectedBrandId = null;

    const feedback = document.getElementById('reference-feedback');
    function showFeedback(message, isError) {
        feedback.textContent = message;
        feedback.className = 'small mt-2 ' + (isError ? 'text-danger' : 'text-success');
    }

    async function send(url, method, body) {
        const response = await fetch(url, {
            method,
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: body ? JSON.stringify(body) : undefined,
        });
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.message || 'Terjadi kesalahan.');
        }
        return data;
    }

    function renderList(listEl, items, activeId, onSelect, onToggle) {
        listEl.innerHTML = '';
        items.forEach(function (item) {
            const li = document.createElement('li');
            li.className = 'list-group-item d-flex justify-content-between align-items-center' + (item.id === activeId ? ' active' : '');
            li.style.cursor = 'pointer';

            const label = document.createElement('span');
            label.textContent = item.name + (item.is_active ? '' : ' (nonaktif)');
            label.addEventListener('click', function () { onSelect(item.id); });
            li.appendChild(label);

            if (canManage && onToggle) {
                const toggleBtn = document.createElement('button');
                toggleBtn.type = 'button';
                toggleBtn.className = 'btn btn-sm btn-outline-secondary';
                toggleBtn.textContent = item.is_active ? 'Nonaktifkan' : 'Aktifkan';
                toggleBtn.addEventListener('click', function (e) { e.stopPropagation(); onToggle(item); });
                li.appendChild(toggleBtn);
            }

            listEl.appendChild(li);
        });
    }

    function renderCategories() {
        renderList(document.getElementById('category-list'), categories, selectedCategoryId, selectCategory, canManage ? toggleCategory : null);
    }

    function renderBrands() {
        const category = categories.find(function (c) { return c.id === selectedCategoryId; });
        const brands = category ? category.brands : [];
        renderList(document.getElementById('brand-list'), brands, selectedBrandId, selectBrand, canManage ? toggleBrand : null);
        document.getElementById('brand-add-row').style.display = category ? '' : 'none';
    }

    function renderTypes() {
        const category = categories.find(function (c) { return c.id === selectedCategoryId; });
        const brand = category ? category.brands.find(function (b) { return b.id === selectedBrandId; }) : null;
        const types = brand ? brand.types : [];
        renderList(document.getElementById('type-list'), types, null, function () {}, canManage ? toggleType : null);
        document.getElementById('type-add-row').style.display = brand ? '' : 'none';
    }

    function selectCategory(id) {
        selectedCategoryId = id;
        selectedBrandId = null;
        renderCategories();
        renderBrands();
        renderTypes();
    }

    function selectBrand(id) {
        selectedBrandId = id;
        renderBrands();
        renderTypes();
    }

    async function toggleCategory(item) {
        try {
            const data = await send(`/vehicle-references/categories/${item.id}`, 'PUT', { name: item.name, is_active: !item.is_active });
            item.is_active = data.category.is_active;
            renderCategories();
            showFeedback(data.message, false);
        } catch (error) {
            showFeedback(error.message, true);
        }
    }

    async function toggleBrand(item) {
        try {
            const data = await send(`/vehicle-references/brands/${item.id}`, 'PUT', { name: item.name, is_active: !item.is_active });
            item.is_active = data.brand.is_active;
            renderBrands();
            showFeedback(data.message, false);
        } catch (error) {
            showFeedback(error.message, true);
        }
    }

    async function toggleType(item) {
        try {
            const data = await send(`/vehicle-references/types/${item.id}`, 'PUT', { name: item.name, is_active: !item.is_active });
            item.is_active = data.type.is_active;
            renderTypes();
            showFeedback(data.message, false);
        } catch (error) {
            showFeedback(error.message, true);
        }
    }

    if (canManage) {
        document.getElementById('category-add-toggle').addEventListener('click', function () {
            document.getElementById('category-add-form').classList.toggle('d-none');
        });
        document.getElementById('category-add-form').addEventListener('submit', async function (e) {
            e.preventDefault();
            const input = document.getElementById('category-add-name');
            try {
                const data = await send('/vehicle-references/categories', 'POST', { name: input.value });
                categories.push(Object.assign(data.category, { brands: [] }));
                input.value = '';
                renderCategories();
                showFeedback(data.message, false);
            } catch (error) {
                showFeedback(error.message, true);
            }
        });

        document.getElementById('brand-add-toggle').addEventListener('click', function () {
            document.getElementById('brand-add-form').classList.toggle('d-none');
        });
        document.getElementById('brand-add-form').addEventListener('submit', async function (e) {
            e.preventDefault();
            const input = document.getElementById('brand-add-name');
            try {
                const data = await send('/vehicle-references/brands', 'POST', { category_id: selectedCategoryId, name: input.value });
                const category = categories.find(function (c) { return c.id === selectedCategoryId; });
                category.brands.push(Object.assign(data.brand, { types: [] }));
                input.value = '';
                renderBrands();
                showFeedback(data.message, false);
            } catch (error) {
                showFeedback(error.message, true);
            }
        });

        document.getElementById('type-add-toggle').addEventListener('click', function () {
            document.getElementById('type-add-form').classList.toggle('d-none');
        });
        document.getElementById('type-add-form').addEventListener('submit', async function (e) {
            e.preventDefault();
            const input = document.getElementById('type-add-name');
            try {
                const data = await send('/vehicle-references/types', 'POST', { brand_id: selectedBrandId, name: input.value });
                const category = categories.find(function (c) { return c.id === selectedCategoryId; });
                const brand = category.brands.find(function (b) { return b.id === selectedBrandId; });
                brand.types.push(data.type);
                input.value = '';
                renderTypes();
                showFeedback(data.message, false);
            } catch (error) {
                showFeedback(error.message, true);
            }
        });
    }

    renderCategories();
    renderBrands();
    renderTypes();
})();
</script>
@endpush
