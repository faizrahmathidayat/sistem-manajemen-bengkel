@extends('layouts.app')
@section('title', 'Tambah Cabang')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-shop me-2"></i>Tambah Cabang</h1>
    </div>
    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('branches.store') }}">
                @include('branches._form')
            </form>
        </div>
    </div>
@endsection
