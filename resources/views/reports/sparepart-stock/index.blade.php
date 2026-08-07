@extends('layouts.app')
@section('title', 'Laporan Sparepart')
@section('content')
    <div class="mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Laporan Sparepart</h1>
    </div>
    @foreach ($sparepartBranches as $sparepartBranch)
        <div>
            {{ $sparepartBranch->sparepart->code }}
            {{ $sparepartBranch->sparepart->name }}
            {{ number_format((float) $sparepartBranch->on_hand_qty, 0, ',', '.') }}
        </div>
    @endforeach
@endsection
