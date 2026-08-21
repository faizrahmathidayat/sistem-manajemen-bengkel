@extends('layouts.app')
@section('title', 'Master Sparepart')
@section('content')
    <div class="page-heading">
        <div class="page-heading-copy">
            <span class="page-icon"><i class="bi bi-box-seam"></i></span>
            <div>
                <p class="eyebrow mb-1">Persediaan</p>
                <h1 class="h3 mb-1">Master Sparepart</h1>
                <p class="text-muted mb-0">Kelola konfigurasi sparepart per cabang.</p>
            </div>
        </div>
        <div class="heading-actions">
            @if (auth()->user()->hasPermissionToInBranch('sparepart.create', $currentBranch->id))
                <a href="{{ route('sparepart-branches.createExisting') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-link-45deg"></i> Tambah dari Cabang Lain
                </a>
            @endif
            @if (auth()->user()->branchesWithPermission('sparepart.create')->isNotEmpty())
                <a href="{{ route('sparepart-branches.import') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-file-earmark-arrow-up"></i> Import Sparepart
                </a>
                <a href="{{ route('sparepart-branches.create') }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg"></i> Sparepart Baru
                </a>
            @endif
        </div>
    </div>

    @include('partials.list-filter-bar', [
        'searchPlaceholder' => 'Kode atau nama sparepart...',
        'searchValue' => $search,
        'branchFilterBranches' => null,
        'branchFilterSelected' => [],
        'extraFilterHtml' => view('sparepart-branches._branch_switcher_select', ['allowedBranches' => $allowedBranches, 'currentBranch' => $currentBranch])->render(),
        'actionsHtml' => '',
    ])

    <div class="card mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Rak</th>
                        <th>Harga Jual</th>
                        <th>Stok Min</th>
                        <th>Stok Tersedia</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sparepartBranches as $sparepartBranch)
                        <tr>
                            <td><code>{{ $sparepartBranch->sparepart->code }}</code></td>
                            <td>{{ $sparepartBranch->sparepart->name }}</td>
                            <td>{{ optional($sparepartBranch->rack)->code ?? '-' }}</td>
                            <td>{{ number_format($sparepartBranch->selling_price, 0, ',', '.') }}</td>
                            <td>{{ number_format($sparepartBranch->minimum_stock, 0, ',', '.') }}</td>
                            <td>{{ number_format($sparepartBranch->stock->available_qty, 0, ',', '.') }}</td>
                            <td>
                                @if ($sparepartBranch->is_active)
                                    <span class="status-dot status-active">Aktif</span>
                                @else
                                    <span class="status-dot status-inactive">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @can('update', $sparepartBranch)
                                    <a href="{{ route('sparepart-branches.edit', $sparepartBranch) }}" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-pencil"></i> Ubah
                                    </a>
                                @endcan
                                @can('delete', $sparepartBranch)
                                    @if ($sparepartBranch->is_active)
                                        <form method="POST" action="{{ route('sparepart-branches.deactivate', $sparepartBranch) }}" class="d-inline">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-outline-danger btn-sm">Nonaktifkan</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('sparepart-branches.activate', $sparepartBranch) }}" class="d-inline">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-outline-success btn-sm">Aktifkan</button>
                                        </form>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="p-0">
                                @include('partials.empty-state', [
                                    'icon' => 'bi-box-seam',
                                    'title' => 'Belum ada sparepart di cabang ini',
                                    'description' => 'Mulai dengan menambahkan sparepart pertama di cabang ini.',
                                    'ctaRoute' => 'sparepart-branches.create',
                                    'ctaLabel' => '+ Sparepart Baru',
                                    'ctaVisible' => auth()->user()->branchesWithPermission('sparepart.create')->isNotEmpty(),
                                ])
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $sparepartBranches->links() }}
    </div>
@endsection
