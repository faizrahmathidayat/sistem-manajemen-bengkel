<select name="status" class="form-select form-select-sm" style="min-width: 140px;">
    <option value="">Semua Status</option>
    <option value="active" {{ ($selectedStatus ?? null) === 'active' ? 'selected' : '' }}>Aktif</option>
    <option value="inactive" {{ ($selectedStatus ?? null) === 'inactive' ? 'selected' : '' }}>Nonaktif</option>
</select>
