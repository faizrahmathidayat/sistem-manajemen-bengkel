@extends('layouts.app')
@section('title', 'Tambah Rak')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-grid-3x3-gap me-2"></i>Tambah Rak</h1>
    </div>
    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('racks.store') }}">
                @include('racks._form')
            </form>
        </div>
    </div>
@endsection
