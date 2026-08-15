@extends('layouts.guest')

@section('content')
    <main class="auth-page">
        <section class="auth-card">
            <a class="auth-brand" href="{{ route('login') }}">
                <span class="brand-icon"><img src="{{ asset('images/logo.png') }}" alt="" style="width:100%;height:100%;object-fit:contain;border-radius:inherit;"></span>
                <span>
                    <strong>JMS MOTOR</strong>
                    <small>Sistem Manajemen Bengkel</small>
                </span>
            </a>

            @if ($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0 ps-3">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}">
                @csrf
                <div class="mb-4">
                    <p class="eyebrow mb-1">Akses Aman</p>
                    <h1 class="h3 mb-1">Masuk</h1>
                    <p class="text-muted mb-0">Masuk untuk melanjutkan ke workspace Anda.</p>
                </div>

                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input id="username" type="text" name="username" value="{{ old('username') }}" class="form-control" required autofocus>
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label">Password</label>
                    <input id="password" type="password" name="password" class="form-control" required>
                </div>

                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right"></i> Masuk</button>
            </form>
        </section>
    </main>
@endsection
