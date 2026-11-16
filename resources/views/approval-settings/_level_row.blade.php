{{-- One approval level row. $level is null for the "new level" template; $index is the form array key. --}}
<div class="panel mb-2 level-row" style="padding: .8rem 1rem;">
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <div class="d-flex flex-column">
            <button type="button" class="btn btn-light btn-sm py-0 px-1 level-up" aria-label="Naikkan"><i class="bi bi-chevron-up"></i></button>
            <button type="button" class="btn btn-light btn-sm py-0 px-1 level-down" aria-label="Turunkan"><i class="bi bi-chevron-down"></i></button>
        </div>
        <span class="tag level-no">{{ is_numeric($index) ? $index + 1 : '+' }}</span>
        <input type="hidden" name="levels[{{ $index }}][id]" value="{{ $level ? $level->id : '' }}">
        <input type="hidden" class="level-delete" name="levels[{{ $index }}][delete]" value="0">
        <div style="width: 170px;">
            <input type="text" class="form-control form-control-sm" name="levels[{{ $index }}][name]" value="{{ $level ? $level->name : '' }}" maxlength="100" placeholder="Nama level" required>
        </div>
        <div class="flex-fill" style="min-width: 240px;">
            <select multiple class="form-select form-select-sm level-users" name="levels[{{ $index }}][user_ids][]">
                @if ($level)
                    @foreach ($level->users as $user)
                        <option value="{{ $user->id }}" selected>{{ $user->name }} ({{ $user->username }})</option>
                    @endforeach
                @endif
            </select>
        </div>
        <span class="small text-muted" style="width: 110px;">
            @if ($level && $level->rules_count > 0)
                dipakai di {{ $level->rules_count }} aturan
            @else
                belum dipakai
            @endif
        </span>
        <div class="form-check mb-0" style="width: 150px;" title="Bila dicentang, approver level ini boleh membatalkan centang barang saat menyetujui; pembelian hanya dibuat untuk barang yang tetap dicentang.">
            <input type="hidden" name="levels[{{ $index }}][can_select_items]" value="0">
            <input type="checkbox" class="form-check-input" id="levelSelect{{ $index }}" name="levels[{{ $index }}][can_select_items]" value="1" {{ $level && $level->can_select_items ? 'checked' : '' }}>
            <label class="form-check-label small" for="levelSelect{{ $index }}">Boleh pilih item</label>
        </div>
        <div class="form-check form-switch mb-0">
            <input type="hidden" name="levels[{{ $index }}][is_active]" value="0">
            <input type="checkbox" class="form-check-input" name="levels[{{ $index }}][is_active]" value="1" {{ ! $level || $level->is_active ? 'checked' : '' }}>
        </div>
        <button type="button" class="btn btn-outline-danger btn-sm level-remove" {{ $level && $level->rules_count > 0 ? 'disabled' : '' }} aria-label="Hapus level">
            <i class="bi bi-trash"></i>
        </button>
    </div>
    @if ($level && $level->users->isEmpty())
        <div class="form-text text-danger mt-1"><i class="bi bi-x-circle"></i> Belum ada approver — permintaan yang membutuhkan level ini tidak dapat diajukan.</div>
    @endif
    @if ($level && isset($runningByLevel[$level->id]))
        <div class="alert alert-warning py-1 px-2 small mt-2 mb-0">
            <i class="bi bi-exclamation-triangle"></i> {{ $runningByLevel[$level->id] }} permintaan berjalan sedang memakai level ini. Perubahan di sini tidak memengaruhi permintaan yang sudah diajukan.
        </div>
    @endif
</div>
