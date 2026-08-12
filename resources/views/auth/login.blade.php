@extends('layouts.guest')

@section('content')
    <div class="container">
        <div class="row justify-content-center align-items-center" style="min-height: 100vh;">
            <div class="col-12 col-sm-8 col-md-6 col-lg-4">
                <div class="card">
                    <div class="card-body p-4">
                        <div class="text-center mb-3">
                            <img src="{{ asset('images/logo.png') }}" alt="JMS MOTOR" style="width: 96px; height: 96px; object-fit: contain;">
                        </div>
                        <h1 class="h4 mb-1 text-center">JMS MOTOR</h1>
                        <p class="text-center mb-4" style="color: var(--color-ink-muted);">Masuk untuk melanjutkan</p>

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
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input id="username" type="text" name="username" value="{{ old('username') }}" class="form-control" required autofocus>
                            </div>

                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input id="password" type="password" name="password" class="form-control" required>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">Masuk</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
