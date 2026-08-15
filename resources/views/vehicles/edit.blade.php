@extends('layouts.app')
@section('title', 'Ubah Kendaraan')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-car-front"></i></span>
            <div>
                <p class="eyebrow mb-1">Kendaraan</p>
                <h1 class="h3 mb-1">Ubah Kendaraan</h1>
                <p class="text-muted mb-0">Perbarui data kendaraan ini.</p>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('vehicles.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <form method="POST" action="{{ route('vehicles.update', $vehicle) }}" class="panel">
        <div class="panel-header">
            <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-car-front"></i><span>Detail Kendaraan</span></h2>
                <p class="text-muted mb-0">Lengkapi data kendaraan di bawah ini.</p>
            </div>
        </div>
        @php($method = 'PUT')
        @include('vehicles._form')
    </form>
@endsection
