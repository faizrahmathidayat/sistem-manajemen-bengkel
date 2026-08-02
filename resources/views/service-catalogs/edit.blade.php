@extends('layouts.app')
@section('title', 'Ubah Jasa Service')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-tools me-2"></i>Ubah Jasa Service</h1>
    </div>
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('service-catalogs.update', $serviceCatalog) }}">
                @php($method = 'PUT')
                @include('service-catalogs._form')
            </form>
        </div>
    </div>
@endsection
