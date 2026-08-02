@extends('layouts.app')
@section('title', 'Detail Customer')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h4 mb-1"><i class="bi bi-person-badge me-2"></i>{{ $customer->name }}</h1>
            <div class="d-flex align-items-center gap-3">
                <span class="text-muted small">{{ $customer->customer_type === 'COMPANY' ? 'Perusahaan' : 'Perorangan' }}</span>
                @if ($customer->is_active)
                    <span class="status-dot status-active">Aktif</span>
                @else
                    <span class="status-dot status-inactive">Nonaktif</span>
                @endif
            </div>
        </div>
        <a href="{{ route('customers.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Kembali
        </a>
    </div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#profil-pane" type="button" role="tab">
                <i class="bi bi-person me-1"></i> Profil
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#cabang-pane" type="button" role="tab">
                <i class="bi bi-shop me-1"></i> Cabang
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#kendaraan-pane" type="button" role="tab">
                <i class="bi bi-car-front me-1"></i> Kendaraan
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="profil-pane" role="tabpanel">
            @include('customers._tab_profil')
        </div>
        <div class="tab-pane fade" id="cabang-pane" role="tabpanel">
            @include('customers._tab_cabang')
        </div>
        <div class="tab-pane fade" id="kendaraan-pane" role="tabpanel">
            @include('customers._tab_kendaraan')
        </div>
    </div>
@endsection
