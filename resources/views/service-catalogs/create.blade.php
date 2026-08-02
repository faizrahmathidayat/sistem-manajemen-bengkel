@extends('layouts.app')
@section('title', 'Tambah Jasa Service')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-tools me-2"></i>Tambah Jasa Service</h1>
    </div>
    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('service-catalogs.store') }}">
                @include('service-catalogs._form')
            </form>
        </div>
    </div>
@endsection
