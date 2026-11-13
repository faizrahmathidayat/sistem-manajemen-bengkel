@php($firstPending = $purchaseRequest->approvals->firstWhere('status', \App\Support\PurchaseRequestApprovalStatus::PENDING))

<div class="panel mb-3">
    <h2 class="section-title mb-3"><i class="bi bi-patch-check"></i><span>Progres Approval</span></h2>

    <div class="stepper">
        @foreach ($purchaseRequest->approvals as $step)
            @php($stateClass = $step->status === 'approved' ? 'done' : ($step->status === 'rejected' ? 'bad' : ($step->status === 'void' ? 'void' : ($firstPending && $firstPending->id === $step->id ? 'now' : ''))))
            <div class="stp {{ $stateClass }}">
                <div class="dot">
                    @if ($step->status === 'approved')
                        <i class="bi bi-check-lg"></i>
                    @elseif ($step->status === 'rejected')
                        <i class="bi bi-x-lg"></i>
                    @else
                        {{ $step->step_no }}
                    @endif
                </div>
                <b>{{ $step->level_name }}</b>
                <small>
                    @if (in_array($step->status, ['approved', 'rejected'], true))
                        {{ optional($step->actor)->name ?? '-' }}<br>{{ optional($step->acted_at)->format('d/m/Y H:i') }}
                    @elseif ($step->status === 'void')
                        tidak diproses
                    @elseif ($stateClass === 'now')
                        menunggu: {{ $step->users->pluck('name')->implode(', ') }}
                    @else
                        menunggu giliran
                    @endif
                </small>
            </div>
        @endforeach
    </div>

    @foreach ($purchaseRequest->approvals->filter(function ($step) { return $step->notes && in_array($step->status, ['approved', 'rejected'], true); }) as $step)
        <div class="small mt-3 p-2 rounded" style="background: var(--color-surface-soft);">
            <i class="bi bi-chat-left-text"></i> <b>{{ $step->level_name }}:</b> “{{ $step->notes }}”
        </div>
    @endforeach

    @if ($purchaseRequest->total_amount !== null)
        <div class="small text-muted mt-3">Total Rp {{ number_format($purchaseRequest->total_amount, 0, ',', '.') }} — rantai dibentuk saat pengajuan.</div>
    @endif

    @if ($firstPending && app(\App\Services\PurchaseRequestService::class)->isStuck($purchaseRequest))
        <div class="alert alert-warning py-2 small mt-3 mb-2">
            Tidak ada approver langkah {{ $firstPending->level_name }} yang dapat memutuskan (nonaktif, pemohon/pengaju, atau sudah memproses langkah lain), sehingga permintaan ini tertahan. Hubungi admin.
        </div>
        @can('reloadApprovers', $purchaseRequest)
            <form method="POST" action="{{ route('purchase-requests.reload-approvers', $purchaseRequest) }}">
                @csrf
                @method('PATCH')
                <button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-repeat"></i> Muat Ulang Approver</button>
            </form>
        @endcan
    @endif
</div>
