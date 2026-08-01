@extends('layouts.app')
@section('title', 'Detail User')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-person-gear me-2"></i>{{ $user->name }}</h1>
        <a href="{{ route('users.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Kembali
        </a>
    </div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#profil-pane" type="button" role="tab">
                <i class="bi bi-person me-1"></i> Profil
            </button>
        </li>
        @can('user_branch.manage')
        <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#cabang-pane" type="button" role="tab">
                <i class="bi bi-shop me-1"></i> Cabang
            </button>
        </li>
        @endcan
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="profil-pane" role="tabpanel">
            @include('users._tab_profil')
        </div>
        @can('user_branch.manage')
        <div class="tab-pane fade" id="cabang-pane" role="tabpanel">
            @include('users._tab_cabang')
        </div>
        @endcan
    </div>
@endsection
