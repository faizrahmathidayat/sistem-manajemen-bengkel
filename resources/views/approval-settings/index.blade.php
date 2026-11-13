@extends('layouts.app')
@section('title', 'Pengaturan Approval')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-sliders"></i></span>
            <div>
                <p class="eyebrow mb-1">Administrasi</p>
                <h1 class="h3 mb-1">Pengaturan Approval</h1>
                <p class="text-muted mb-0">Atur level approval, siapa approvernya, dan aturan nominal yang menentukan rantai persetujuan.</p>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'levels' ? 'active' : '' }}" href="{{ route('approval-settings.index', ['tab' => 'levels']) }}">
                Level &amp; Approver <span class="badge bg-secondary">{{ $levels->count() }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'rules' ? 'active' : '' }}" href="{{ route('approval-settings.index', ['tab' => 'rules']) }}">
                Aturan Nominal <span class="badge bg-secondary">{{ $rules->count() }}</span>
            </a>
        </li>
    </ul>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($tab === 'rules')
        @include('approval-settings._tab_rules')
    @else
        @include('approval-settings._tab_levels')
    @endif
@endsection
