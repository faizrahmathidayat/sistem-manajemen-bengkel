@extends('layouts.app')
@section('title', 'Ubah Permintaan Barang')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-clipboard-plus"></i></span>
            <div>
                <p class="eyebrow mb-1">Permintaan Barang</p>
                <h1 class="h3 mb-1">Ubah {{ $purchaseRequest->number }}</h1>
            </div>
        </div>
        <div class="heading-actions">
            <a href="{{ route('purchase-requests.show', $purchaseRequest) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Kembali</a>
        </div>
    </div>

    <form method="POST" action="{{ route('purchase-requests.update', $purchaseRequest) }}">
        @include('purchase-requests._form', ['method' => 'PUT'])
    </form>
@endsection
