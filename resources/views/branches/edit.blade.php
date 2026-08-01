@extends('layouts.app')
@section('title', 'Ubah Cabang')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-shop me-2"></i>Ubah Cabang</h1>
    </div>
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('branches.update', $branch) }}">
                @php($method = 'PUT')
                @include('branches._form')
            </form>
        </div>
    </div>
@endsection
