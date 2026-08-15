@extends('layouts.app')
@section('title', 'Ubah Rak')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-grid-3x3-gap"></i></span>
            <div>
                <p class="eyebrow mb-1">Rak</p>
                <h1 class="h3 mb-1">Ubah Rak</h1>
                <p class="text-muted mb-0">Perbarui kode rak penyimpanan ini.</p>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('racks.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <form method="POST" action="{{ route('racks.update', $rack) }}" class="panel">
        <div class="panel-header">
            <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-grid-3x3-gap"></i><span>Detail Rak</span></h2>
                <p class="text-muted mb-0">Lengkapi kode rak penyimpanan di bawah ini.</p>
            </div>
        </div>
        @php($method = 'PUT')
        @include('racks._form')
    </form>
@endsection
