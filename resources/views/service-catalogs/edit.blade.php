@extends('layouts.app')
@section('title', 'Ubah Jasa Service')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-tools"></i></span>
            <div>
                <p class="eyebrow mb-1">Jasa Service</p>
                <h1 class="h3 mb-1">Ubah Jasa Service</h1>
                <p class="text-muted mb-0">Perbarui nama dan harga default jasa service ini.</p>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('service-catalogs.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <form method="POST" action="{{ route('service-catalogs.update', $serviceCatalog) }}" class="panel">
        <div class="panel-header">
            <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-tools"></i><span>Detail Jasa</span></h2>
                <p class="text-muted mb-0">Lengkapi data jasa service di bawah ini.</p>
            </div>
        </div>
        @php($method = 'PUT')
        @include('service-catalogs._form')
    </form>
@endsection
