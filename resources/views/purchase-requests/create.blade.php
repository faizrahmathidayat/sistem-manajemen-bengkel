@extends('layouts.app')
@section('title', 'Permintaan Barang Baru')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-clipboard-plus"></i></span>
            <div>
                <p class="eyebrow mb-1">Permintaan Barang</p>
                <h1 class="h3 mb-1">Permintaan Barang Baru</h1>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('purchase-requests.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <form method="POST" action="{{ route('purchase-requests.store') }}">
        @include('purchase-requests._form')
    </form>
@endsection
