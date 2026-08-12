@extends('layouts.guest')
@section('content')
    <div class="container">
        <div class="row justify-content-center align-items-center" style="min-height: 100vh;">
            <div class="col-12 col-sm-8 col-md-6 col-lg-4">
                <div class="card">
                    <div class="card-body p-4">
                        <h1 class="h5 mb-1 text-center">Invoice {{ $invoice->number }}</h1>
                        <p class="text-center mb-4" style="color: var(--color-ink-muted);">
                            Masukkan 6 digit PIN yang dikirimkan ke Anda.
                        </p>

                        @if ($errors->any())
                            <div class="alert alert-danger">{{ $errors->first() }}</div>
                        @endif
                        @if (session('error'))
                            <div class="alert alert-danger">{{ session('error') }}</div>
                        @endif

                        <form method="POST" action="{{ route('public-invoices.verify', $invoice) }}">
                            @csrf
                            <div class="mb-3">
                                <input type="text" name="pin" inputmode="numeric" pattern="\d{6}" maxlength="6"
                                    class="form-control form-control-lg text-center @error('pin') is-invalid @enderror"
                                    autofocus required>
                                @error('pin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Lihat Invoice</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
