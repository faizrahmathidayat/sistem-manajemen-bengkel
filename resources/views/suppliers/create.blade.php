@extends('layouts.app')
@section('title', 'Supplier Baru')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-shop-window"></i></span>
            <div>
                <p class="eyebrow mb-1">Supplier</p>
                <h1 class="h3 mb-1">Supplier Baru</h1>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('suppliers.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <form method="POST" action="{{ route('suppliers.store') }}" class="panel">
        <div class="panel-header">
            <h2 class="h5 mb-1 section-title"><i class="bi bi-info-circle"></i><span>Data Supplier</span></h2>
        </div>
        @include('suppliers._form')
    </form>
@endsection
