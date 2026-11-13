<?php

namespace App\Http\Controllers;

use App\Models\PurchaseRequestApproval;
use App\Services\PurchaseRequestService;
use App\Support\PurchaseRequestApprovalStatus;
use Illuminate\Http\Request;

/**
 * Approver inbox. Access is by being configured as an approver (no permission code): a user
 * sees only what is theirs to decide ("Menunggu Saya") and what they already decided.
 */
class ApprovalController extends Controller
{
    public function index(Request $request, PurchaseRequestService $service)
    {
        $user = $request->user();

        if (! $service->isApprover($user)) {
            return view('approvals.no-access');
        }

        $tab = $request->query('tab') === 'history' ? 'history' : 'pending';
        $pendingCount = $service->waitingFor($user)->count();

        if ($tab === 'history') {
            $decisions = PurchaseRequestApproval::with(['purchaseRequest.branch'])
                ->where('acted_by', $user->id)
                ->whereIn('status', [PurchaseRequestApprovalStatus::APPROVED, PurchaseRequestApprovalStatus::REJECTED])
                ->orderByDesc('acted_at')->orderByDesc('id')
                ->paginate(10)->withQueryString();

            return view('approvals.index', compact('tab', 'pendingCount', 'decisions'));
        }

        $waiting = $service->waitingFor($user)
            ->with(['branch', 'creator', 'approvals'])
            ->orderBy('submitted_at')->orderBy('id')
            ->paginate(10)->withQueryString();

        return view('approvals.index', compact('tab', 'pendingCount', 'waiting'));
    }
}
