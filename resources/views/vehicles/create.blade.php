@extends('layouts.app')
@section('title', 'Tambah Kendaraan')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-car-front me-2"></i>Tambah Kendaraan</h1>
    </div>
    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('vehicles.store') }}">
                @include('vehicles._form')
            </form>
        </div>
    </div>
@endsection
