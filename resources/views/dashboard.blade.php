@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <h1 class="h4 mb-1">Dashboard</h1>
    <p class="mb-4" style="color: var(--color-ink-muted);">Selamat datang, {{ auth()->user()->name }}.</p>

    <div class="row g-3">
        <div class="col-sm-6 col-lg-4">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ $activeBranchCount }}</div>
                    <div class="stat-label">Cabang Aktif</div>
                </div>
                <i class="bi bi-shop stat-icon"></i>
            </div>
        </div>
        <div class="col-sm-6 col-lg-4">
            <div class="stat-card">
                <div>
                    <div class="stat-value">{{ $activeUserCount }}</div>
                    <div class="stat-label">User Aktif</div>
                </div>
                <i class="bi bi-people stat-icon"></i>
            </div>
        </div>
    </div>
@endsection
