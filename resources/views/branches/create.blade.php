@extends('layouts.app')
@section('title', 'Tambah Cabang')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-shop"></i></span>
            <div>
                <p class="eyebrow mb-1">Cabang</p>
                <h1 class="h3 mb-1">Tambah Cabang</h1>
                <p class="text-muted mb-0">Tambahkan cabang baru beserta data kontak dan alamatnya.</p>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('branches.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <form method="POST" action="{{ route('branches.store') }}" class="panel">
        <div class="panel-header">
            <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-shop"></i><span>Detail Cabang</span></h2>
                <p class="text-muted mb-0">Lengkapi data cabang di bawah ini.</p>
            </div>
        </div>
        @include('branches._form')
    </form>
@endsection
