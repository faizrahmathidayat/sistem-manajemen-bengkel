<form method="POST" action="{{ route('approval-settings.save-levels') }}" id="levelsForm">
    @csrf
    @method('PUT')
    <fieldset {{ $canManage ? '' : 'disabled' }}>
        <div class="small text-muted mb-2">
            <i class="bi bi-arrow-down-up"></i> Urutan level = urutan persetujuan dalam rantai. Aturan nominal (tab sebelah) memilih level mana yang dipakai.
        </div>

        <div id="levelRows">
            @foreach ($levels as $level)
                @include('approval-settings._level_row', ['level' => $level, 'index' => $loop->index])
            @endforeach
        </div>

        <div class="d-flex flex-wrap justify-content-between gap-2 mt-3">
            <button type="button" class="btn btn-outline-primary btn-sm" id="addLevelButton"><i class="bi bi-plus-lg"></i> Tambah Level</button>
            @if ($canManage)
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
            @endif
        </div>
    </fieldset>
</form>

<template id="levelRowTemplate">
    @include('approval-settings._level_row', ['level' => null, 'index' => '__INDEX__'])
</template>

@push('scripts')
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="{{ asset('js/select2-ajax-picker.js') }}"></script>
<script>
(function () {
    const userEndpoint = @json(route('approval-settings.lookup-users'));
    const rowsEl = document.getElementById('levelRows');
    let nextIndex = rowsEl.querySelectorAll('.level-row').length;

    function initUsers(row) {
        initAjaxSelect(row.querySelector('.level-users'), { endpoint: userEndpoint, placeholder: 'Pilih user…' });
    }

    function renumber() {
        let n = 1;
        rowsEl.querySelectorAll('.level-row').forEach(function (row) {
            row.querySelector('.level-no').textContent = row.classList.contains('opacity-50') ? '–' : n++;
        });
    }

    rowsEl.querySelectorAll('.level-row').forEach(initUsers);

    document.getElementById('addLevelButton').addEventListener('click', function () {
        const html = document.getElementById('levelRowTemplate').innerHTML.replace(/__INDEX__/g, String(nextIndex++));
        rowsEl.insertAdjacentHTML('beforeend', html);
        const row = rowsEl.lastElementChild;
        initUsers(row);
        renumber();
        row.querySelector('input[type=text]').focus();
    });

    rowsEl.addEventListener('click', function (event) {
        const row = event.target.closest('.level-row');
        if (!row) return;

        if (event.target.closest('.level-up') && row.previousElementSibling) {
            rowsEl.insertBefore(row, row.previousElementSibling);
            renumber();
        } else if (event.target.closest('.level-down') && row.nextElementSibling) {
            rowsEl.insertBefore(row.nextElementSibling, row);
            renumber();
        } else if (event.target.closest('.level-remove')) {
            const idInput = row.querySelector('input[name$="[id]"]');
            if (!idInput.value) {
                const select = row.querySelector('.level-users');
                if ($(select).data('select2')) $(select).select2('destroy');
                row.remove();
            } else {
                const flag = row.querySelector('.level-delete');
                const marked = flag.value === '1';
                flag.value = marked ? '0' : '1';
                row.classList.toggle('opacity-50', !marked);
            }
            renumber();
        }
    });
})();
</script>
@endpush
