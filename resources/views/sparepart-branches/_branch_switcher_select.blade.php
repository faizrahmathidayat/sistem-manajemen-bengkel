<select name="branch_id" class="form-select form-select-sm" onchange="this.form.submit()">
    @foreach ($allowedBranches as $branch)
        <option value="{{ $branch->id }}" {{ $branch->id === $currentBranch->id ? 'selected' : '' }}>
            {{ $branch->name }}
        </option>
    @endforeach
</select>
