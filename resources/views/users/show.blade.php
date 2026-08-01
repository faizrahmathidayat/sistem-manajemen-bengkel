@extends('layouts.app')
@section('title', 'Detail User')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-person-gear me-2"></i>{{ $user->name }}</h1>
        <a href="{{ route('users.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Kembali
        </a>
    </div>

    @include('users._tab_profil')
@endsection
