@extends('layouts.app')
@section('title', 'Ubah Kendaraan')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-car-front me-2"></i>Ubah Kendaraan</h1>
    </div>
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('vehicles.update', $vehicle) }}">
                @php($method = 'PUT')
                @include('vehicles._form')
            </form>
        </div>
    </div>
@endsection
