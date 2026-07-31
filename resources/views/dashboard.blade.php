@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <h1 class="h3 mb-4">Dashboard</h1>
    <div class="card">
        <div class="card-body">
            <p class="mb-0">Selamat datang, {{ auth()->user()->name }}.</p>
        </div>
    </div>
@endsection
