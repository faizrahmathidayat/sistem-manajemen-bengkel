@extends('layouts.app')
@section('title', 'Approval Permintaan')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-patch-check"></i></span>
            <div>
                <p class="eyebrow mb-1">Pembelian</p>
                <h1 class="h3 mb-1">Approval Permintaan</h1>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-body text-center text-muted py-5">
            Anda belum terdaftar sebagai approver. Hubungi admin untuk diatur di Pengaturan Approval.
        </div>
    </div>
@endsection
