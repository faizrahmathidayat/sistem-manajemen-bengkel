@php($activeLevels = $levels->where('is_active', true)->sortBy('sort_order')->values())

<form method="POST" action="{{ route('approval-settings.save-rules') }}" id="rulesForm">
    @csrf
    @method('PUT')
    <fieldset {{ $canManage ? '' : 'disabled' }}>
        <div class="panel mb-3">
            <h2 class="section-title mb-2"><i class="bi bi-list-ol"></i><span>Aturan Berdasarkan Total Permintaan</span></h2>
            <div class="small text-muted mb-2">
                Aturan dibaca dari atas ke bawah. Batas atas bersifat <b>inklusif</b>. Aturan terakhir otomatis berlaku untuk "di atas batas sebelumnya".
                Urutan persetujuan selalu mengikuti urutan level, bukan urutan centang.
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 30px;">#</th>
                            <th style="width: 220px;">Cakupan Total (Rp)</th>
                            <th style="width: 180px;">Batas Atas (Rp)</th>
                            <th>Level yang Wajib Menyetujui</th>
                            <th>Rantai</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="ruleRows">
                        @foreach ($rules as $rule)
                            @include('approval-settings._rule_row', ['rule' => $rule, 'index' => $loop->index])
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="d-flex flex-wrap justify-content-between gap-2 mt-3">
                <button type="button" class="btn btn-outline-primary btn-sm" id="addRuleButton"><i class="bi bi-plus-lg"></i> Tambah Aturan</button>
                @if ($canManage)
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
                @endif
            </div>
        </div>
    </fieldset>
</form>

<div class="panel">
    <h2 class="section-title mb-2"><i class="bi bi-calculator"></i><span>Uji Aturan</span></h2>
    <div class="small text-muted mb-2">Mencoba nominal terhadap aturan yang sudah <b>tersimpan</b> (bukan perubahan yang belum disimpan).</div>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="small">Total permintaan</span>
        <input type="text" inputmode="numeric" class="form-control form-control-sm text-end" style="width: 170px;" id="previewTotal" value="38.320.000">
        <button type="button" class="btn btn-outline-primary btn-sm" id="previewButton">
            <span class="spinner-border spinner-border-sm d-none" id="previewSpinner" role="status" aria-hidden="true"></span> Uji
        </button>
    </div>
    <div class="mt-2 small" id="previewResult"></div>
</div>

<template id="ruleRowTemplate">
    <table><tbody>
        @include('approval-settings._rule_row', ['rule' => null, 'index' => '__INDEX__'])
    </tbody></table>
</template>

@push('scripts')
<script>
(function () {
    const previewEndpoint = @json(route('approval-settings.preview'));
    const rowsEl = document.getElementById('ruleRows');
    let nextIndex = rowsEl.querySelectorAll('.rule-row').length;

    function digits(value) { return String(value || '').replace(/\D/g, ''); }
    function format(value) { return Number(value).toLocaleString('id-ID'); }

    function refresh() {
        const rows = Array.from(rowsEl.querySelectorAll('.rule-row'));
        let previous = null;
        rows.forEach(function (row, i) {
            const isLast = i === rows.length - 1;
            const input = row.querySelector('.rule-max');
            row.querySelector('.rule-no').textContent = i + 1;

            if (isLast) {
                input.value = '';
                input.disabled = true;
                input.placeholder = 'tanpa batas';
            } else {
                input.disabled = false;
                input.placeholder = '0';
            }

            const bound = isLast ? null : Number(digits(input.value) || 0);
            let scope;
            if (isLast) {
                scope = previous === null ? 'semua nominal' : 'di atas ' + format(previous);
            } else {
                scope = (previous === null ? '0' : format(previous + 1)) + ' – ' + (bound ? format(bound) : '…');
            }
            row.querySelector('.rule-scope').textContent = scope;
            if (!isLast) previous = bound;

            const names = Array.from(row.querySelectorAll('.rule-level:checked')).map(function (c) { return c.dataset.name; });
            row.querySelector('.rule-chain').textContent = names.length ? names.join(' → ') : '—';
            row.querySelector('.rule-remove').disabled = rows.length === 1;
        });
    }

    rowsEl.addEventListener('input', refresh);
    rowsEl.addEventListener('change', refresh);
    rowsEl.addEventListener('focusout', function (event) {
        if (event.target.classList.contains('rule-max') && digits(event.target.value) !== '') {
            event.target.value = format(digits(event.target.value));
            refresh();
        }
    });
    rowsEl.addEventListener('click', function (event) {
        const button = event.target.closest('.rule-remove');
        if (button && !button.disabled) {
            button.closest('.rule-row').remove();
            refresh();
        }
    });

    document.getElementById('addRuleButton').addEventListener('click', function () {
        const html = document.getElementById('ruleRowTemplate').innerHTML.replace(/__INDEX__/g, String(nextIndex++));
        const holder = document.createElement('table');
        holder.innerHTML = html;
        const newRow = holder.querySelector('.rule-row');
        // New rules go before the last (unbounded) one so the open-ended rule stays last.
        const last = rowsEl.lastElementChild;
        if (last) { rowsEl.insertBefore(newRow, last); } else { rowsEl.appendChild(newRow); }
        refresh();
        newRow.querySelector('.rule-max').focus();
    });

    document.getElementById('previewButton').addEventListener('click', async function () {
        const spinner = document.getElementById('previewSpinner');
        const result = document.getElementById('previewResult');
        spinner.classList.remove('d-none');
        result.textContent = '';
        try {
            const response = await fetch(previewEndpoint + '?total=' + encodeURIComponent(digits(document.getElementById('previewTotal').value)), { headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (data.ok) {
                result.innerHTML = data.steps.map(function (step) {
                    const approvers = document.createElement('span');
                    approvers.textContent = '(' + step.users.join(', ') + ')';
                    const name = document.createElement('b');
                    name.textContent = step.level_name;
                    return name.outerHTML + ' ' + approvers.outerHTML;
                }).join(' → ');
            } else {
                result.textContent = data.message;
                result.className = 'mt-2 small text-danger';
                return;
            }
            result.className = 'mt-2 small';
        } catch (error) {
            result.textContent = 'Gagal menghubungi server.';
            result.className = 'mt-2 small text-danger';
        } finally {
            spinner.classList.add('d-none');
        }
    });

    refresh();
})();
</script>
@endpush
