@extends('layouts.app')
@section('title', 'Detail Mekanik')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-person-gear"></i></span>
            <div>
                <p class="eyebrow mb-1">Mekanik</p>
                <h1 class="h3 mb-1">{{ $mechanic->name }}</h1>
                @if ($mechanic->is_active)
                    <span class="status-dot status-active">Aktif</span>
                @else
                    <span class="status-dot status-inactive">Nonaktif</span>
                @endif
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('mechanics.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Kembali
            </a>
        </div>
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
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="profil-pane" role="tabpanel">
            @include('mechanics._tab_profil')
        </div>
        <div class="tab-pane fade" id="cabang-pane" role="tabpanel">
            @include('mechanics._tab_cabang')
        </div>
    </div>
@endsection
