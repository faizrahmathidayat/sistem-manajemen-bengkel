@extends('layouts.app')
@section('title', 'Tambah Rak')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-grid-3x3-gap"></i></span>
            <div>
                <p class="eyebrow mb-1">Rak</p>
                <h1 class="h3 mb-1">Tambah Rak</h1>
                <p class="text-muted mb-0">Tambahkan rak penyimpanan baru.</p>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('racks.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <form method="POST" action="{{ route('racks.store') }}" class="panel">
        <div class="panel-header">
            <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-grid-3x3-gap"></i><span>Detail Rak</span></h2>
                <p class="text-muted mb-0">Lengkapi kode rak penyimpanan di bawah ini.</p>
            </div>
        </div>
        @include('racks._form')
    </form>
@endsection
