@extends('layouts.app')
@section('title', 'Ubah Rak')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-grid-3x3-gap me-2"></i>Ubah Rak</h1>
    </div>
    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('racks.update', $rack) }}">
                @php($method = 'PUT')
                @include('racks._form')
            </form>
        </div>
    </div>
@endsection
