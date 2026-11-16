<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ route('users.update', $user) }}">
            @csrf
            @method('PUT')

            <div class="mb-3">
                <label for="name" class="form-label">Nama Lengkap</label>
                <input type="text" name="name" id="name" value="{{ old('name', $user->name) }}" class="form-control @error('name') is-invalid @enderror" maxlength="150" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" name="username" id="username" value="{{ old('username', $user->username) }}" class="form-control @error('username') is-invalid @enderror" maxlength="100" required>
                @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label for="phone_number" class="form-label">Nomor HP</label>
                <input type="text" name="phone_number" id="phone_number" value="{{ old('phone_number', $user->phone_number) }}" class="form-control @error('phone_number') is-invalid @enderror" maxlength="30" placeholder="Contoh: 0812 3456 7890">
                @error('phone_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">Format 08xx otomatis disimpan sebagai 628xx.</div>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Password Baru</label>
                <input type="password" name="password" id="password" class="form-control @error('password') is-invalid @enderror" placeholder="Kosongkan jika tidak ingin mengubah">
                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="form-check form-switch mb-4">
                <input type="checkbox" name="is_active" id="is_active" value="1" class="form-check-input"
                    {{ old('is_active', $user->is_active) ? 'checked' : '' }}
                    {{ $user->id === auth()->id() ? 'disabled' : '' }}>
                <label for="is_active" class="form-check-label">Aktif</label>
                @if ($user->id === auth()->id())
                    <div class="form-text">Anda tidak dapat menonaktifkan akun sendiri.</div>
                @endif
            </div>

            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Simpan</button>
        </form>
    </div>
</div>
