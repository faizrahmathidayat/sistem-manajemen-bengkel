{{-- One amount rule row. $rule is null for the template; $index is the form array key; $activeLevels sorted by sort_order. --}}
<tr class="rule-row">
    <td class="rule-no">{{ is_numeric($index) ? $index + 1 : '+' }}</td>
    <td class="small rule-scope">—</td>
    <td>
        <input type="text" inputmode="numeric" class="form-control form-control-sm text-end rule-max" name="rules[{{ $index }}][max_amount]" value="{{ $rule && $rule->max_amount !== null ? number_format($rule->max_amount, 0, ',', '.') : '' }}" placeholder="0" autocomplete="off">
    </td>
    <td>
        @foreach ($activeLevels as $level)
            <label class="me-2 mb-1 d-inline-flex align-items-center gap-1 small">
                <input type="checkbox" class="form-check-input mt-0 rule-level" data-name="{{ $level->name }}" name="rules[{{ $index }}][level_ids][]" value="{{ $level->id }}" {{ $rule && $rule->levels->contains('id', $level->id) ? 'checked' : '' }}>
                {{ $level->name }}
            </label>
        @endforeach
    </td>
    <td class="small rule-chain">—</td>
    <td class="text-end">
        <button type="button" class="btn btn-outline-danger btn-sm rule-remove" aria-label="Hapus aturan"><i class="bi bi-trash"></i></button>
    </td>
</tr>
