@extends('layouts.app')
@section('title', 'Mekanik')
@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0"><i class="bi bi-person-gear me-2"></i>Mekanik</h1>
        @can('mechanic.create')
            <a href="{{ route('mechanics.create') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg"></i> Tambah Mekanik
            </a>
        @endcan
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nama</th>
                        <th>Telepon</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($mechanics as $mechanic)
                        <tr>
                            <td>{{ $mechanic->name }}</td>
                            <td>{{ $mechanic->phone ?? '-' }}</td>
                            <td>
                                @if ($mechanic->is_active)
                                    <span class="status-dot status-active">Aktif</span>
                                @else
                                    <span class="status-dot status-inactive">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('mechanics.show', $mechanic) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-gear"></i> Kelola
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-4">Belum ada mekanik.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $mechanics->links() }}
    </div>
@endsection
