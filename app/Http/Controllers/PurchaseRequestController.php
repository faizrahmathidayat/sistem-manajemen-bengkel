<?php

namespace App\Http\Controllers;

use App\Exceptions\ApprovalChainException;
use App\Http\Requests\ApprovePurchaseRequestRequest;
use App\Http\Requests\AssignPurchaseRequestSuppliersRequest;
use App\Http\Requests\RejectPurchaseRequestRequest;
use App\Http\Requests\StorePurchaseRequestRequest;
use App\Http\Requests\UpdatePurchaseRequestRequest;
use App\Models\Branch;
use App\Models\PurchaseRequest;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Services\ApprovalChainResolver;
use App\Services\AuditLogger;
use App\Services\DocumentNumberGenerator;
use App\Services\PurchaseRequestService;
use App\Support\AuditEvent;
use App\Support\PurchaseRequestStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseRequestController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $branches = $this->viewableBranches($user);

        if ($branches->isEmpty()) {
            return view('purchase-requests.no-access');
        }

        $branchIds = collect(request('branch_ids', []))
            ->filter(fn ($id) => is_scalar($id))
            ->map(fn ($id) => (int) $id)
            ->intersect($branches->pluck('id'))
            ->values()->all();

        $search = is_string(request('q')) ? trim(request('q')) : null;
        $status = is_string(request('status')) && array_key_exists(request('status'), PurchaseRequestStatus::LABELS)
            ? request('status') : null;

        $purchaseRequests = PurchaseRequest::with(['branch', 'creator'])
            ->withCount('lines')
            ->withSum('lines as lines_amount', 'line_total')
            ->whereIn('branch_id', $branches->pluck('id'))
            ->when($branchIds, fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->when($status, fn ($query, $s) => $query->where('status', $s))
            ->when($search, function ($query, $q) {
                $query->where('number', 'like', '%' . addcslashes($q, '%_\\') . '%');
            })
            ->orderByDesc('request_date')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        return view('purchase-requests.index', compact('purchaseRequests'))
            ->with('branches', $branches)
            ->with('selectedBranchIds', $branchIds)
            ->with('search', $search)
            ->with('selectedStatus', $status);
    }

    public function create()
    {
        $branches = auth()->user()->branchesWithPermission('purchase_request.create');

        if ($branches->isEmpty()) {
            return view('purchase-requests.no-access');
        }

        return view('purchase-requests.create', compact('branches'));
    }

    public function store(StorePurchaseRequestRequest $request)
    {
        $data = $request->validated();
        $branch = Branch::findOrFail($data['branch_id']);

        $purchaseRequest = DB::transaction(function () use ($data, $branch) {
            $purchaseRequest = PurchaseRequest::create([
                'number' => (new DocumentNumberGenerator())->next($branch, 'RQ'),
                'branch_id' => $branch->id,
                'request_date' => $data['request_date'],
                'notes' => $data['notes'] ?? null,
                'status' => PurchaseRequestStatus::WAITING_SUPPLIER,
            ]);

            $this->syncLines($purchaseRequest, $data['lines']);

            return $purchaseRequest;
        });

        return redirect()->route('purchase-requests.show', $purchaseRequest)
            ->with('status', 'Permintaan barang berhasil dibuat.');
    }

    public function show(PurchaseRequest $purchaseRequest, ApprovalChainResolver $resolver)
    {
        $this->authorize('view', $purchaseRequest);

        $purchaseRequest->load([
            'branch', 'creator', 'lines.sparepartBranch.sparepart', 'lines.supplier',
            'approvals.users', 'approvals.actor', 'purchaseOrders.supplier',
        ]);

        // For the warehouse head: which chain "Ajukan Approval" would create right now.
        $submitPreview = null;
        if (auth()->user()->can('submit', $purchaseRequest) && $purchaseRequest->isAssignmentComplete()) {
            try {
                $submitPreview = [
                    'total' => $purchaseRequest->computeTotal(),
                    'steps' => $resolver->resolve($purchaseRequest->computeTotal()),
                    'userNames' => \App\Models\User::pluck('name', 'id'),
                ];
            } catch (ApprovalChainException $e) {
                $submitPreview = ['error' => $e->getMessage()];
            }
        }

        return view('purchase-requests.show', [
            'purchaseRequest' => $purchaseRequest,
            'submitPreview' => $submitPreview,
        ]);
    }

    public function edit(PurchaseRequest $purchaseRequest)
    {
        $this->authorize('update', $purchaseRequest);

        $purchaseRequest->load(['branch', 'lines']);

        $existingLines = $purchaseRequest->lines->map(function ($line) {
            return [
                'sparepart_branch_id' => $line->sparepart_branch_id,
                'qty' => (int) $line->qty,
            ];
        })->values()->all();

        return view('purchase-requests.edit', compact('purchaseRequest', 'existingLines'));
    }

    public function update(UpdatePurchaseRequestRequest $request, PurchaseRequest $purchaseRequest)
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $purchaseRequest) {
            $fresh = $this->lockEditable($purchaseRequest);

            $fresh->update([
                'request_date' => $data['request_date'],
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncLines($fresh, $data['lines']);
        });

        return redirect()->route('purchase-requests.show', $purchaseRequest)
            ->with('status', 'Permintaan barang berhasil diperbarui.');
    }

    public function cancel(PurchaseRequest $purchaseRequest)
    {
        $this->authorize('cancel', $purchaseRequest);

        DB::transaction(function () use ($purchaseRequest) {
            $fresh = $this->lockEditable($purchaseRequest);

            $fresh->status = PurchaseRequestStatus::CANCELLED;
            $fresh->save();

            (new AuditLogger())->log(
                AuditEvent::PURCHASE_REQUEST_CANCELLED,
                $fresh->branch_id,
                $fresh,
                ['status' => PurchaseRequestStatus::WAITING_SUPPLIER],
                ['status' => PurchaseRequestStatus::CANCELLED]
            );
        });

        return redirect()->route('purchase-requests.show', $purchaseRequest)
            ->with('status', 'Permintaan barang berhasil dibatalkan.');
    }

    public function submit(PurchaseRequest $purchaseRequest, PurchaseRequestService $service)
    {
        $this->authorize('submit', $purchaseRequest);

        try {
            $service->submit($purchaseRequest, auth()->user());
        } catch (ApprovalChainException | \DomainException $e) {
            return redirect()->route('purchase-requests.show', $purchaseRequest)->with('error', $e->getMessage());
        }

        return redirect()->route('purchase-requests.show', $purchaseRequest)
            ->with('status', 'Permintaan berhasil diajukan untuk approval.');
    }

    public function approve(ApprovePurchaseRequestRequest $request, PurchaseRequest $purchaseRequest, PurchaseRequestService $service)
    {
        try {
            $service->approve($purchaseRequest, $request->user(), $request->input('note'), $this->expectedStep($request));
        } catch (\DomainException $e) {
            return redirect()->route('purchase-requests.show', $purchaseRequest)->with('error', $e->getMessage());
        } catch (AuthorizationException $e) {
            return $this->alreadyDecided($purchaseRequest);
        }

        return redirect()->route('purchase-requests.show', $purchaseRequest)
            ->with('status', 'Persetujuan Anda berhasil dicatat.');
    }

    public function reject(RejectPurchaseRequestRequest $request, PurchaseRequest $purchaseRequest, PurchaseRequestService $service)
    {
        try {
            $service->reject($purchaseRequest, $request->user(), $request->input('reason'), $this->expectedStep($request));
        } catch (\DomainException $e) {
            return redirect()->route('purchase-requests.show', $purchaseRequest)->with('error', $e->getMessage());
        } catch (AuthorizationException $e) {
            return $this->alreadyDecided($purchaseRequest);
        }

        return redirect()->route('purchase-requests.show', $purchaseRequest)
            ->with('status', 'Permintaan telah ditolak.');
    }

    public function reloadApprovers(PurchaseRequest $purchaseRequest, PurchaseRequestService $service)
    {
        $this->authorize('reloadApprovers', $purchaseRequest);

        try {
            $service->reloadApprovers($purchaseRequest, auth()->user());
        } catch (ApprovalChainException | \DomainException $e) {
            return redirect()->route('purchase-requests.show', $purchaseRequest)->with('error', $e->getMessage());
        }

        return redirect()->route('purchase-requests.show', $purchaseRequest)
            ->with('status', 'Approver langkah saat ini berhasil dimuat ulang.');
    }

    /**
     * Warehouse head fills supplier and unit price per line. Saving is partial-friendly
     * ("Simpan Draft"); line_total is always recomputed here, never taken from the client.
     */
    public function assignSuppliers(AssignPurchaseRequestSuppliersRequest $request, PurchaseRequest $purchaseRequest)
    {
        $payload = $request->validated()['lines'];

        DB::transaction(function () use ($payload, $purchaseRequest) {
            $fresh = PurchaseRequest::whereKey($purchaseRequest->id)->lockForUpdate()->firstOrFail();
            abort_unless($fresh->status === PurchaseRequestStatus::WAITING_SUPPLIER, 409, 'Permintaan sudah diproses.');

            foreach ($fresh->lines as $line) {
                if (! array_key_exists($line->id, $payload)) {
                    continue;
                }
                $input = $payload[$line->id];
                $supplierId = isset($input['supplier_id']) && $input['supplier_id'] !== '' ? (int) $input['supplier_id'] : null;
                $price = isset($input['unit_price']) && $input['unit_price'] !== '' ? (int) $input['unit_price'] : null;

                $line->supplier_id = $supplierId;
                $line->unit_price = $price;
                $line->line_total = $price === null ? null : round((float) $line->qty * $price, 2);
                $line->save();
            }
        });

        return redirect()->route('purchase-requests.show', $purchaseRequest)
            ->with('status', 'Supplier dan harga berhasil disimpan.');
    }

    /**
     * Spareparts of one branch for the request form (Select2). Requesters need not hold
     * sparepart.view; purchase_request.create in that branch is enough.
     */
    public function lookupSpareparts(Request $request)
    {
        $branchId = (int) $request->query('branch_id');
        abort_if($branchId <= 0, 400, 'branch_id is required.');
        abort_unless(auth()->user()->hasPermissionToInBranch('purchase_request.create', $branchId), 403);

        $query = SparepartBranch::with(['sparepart', 'stock'])->where('branch_id', $branchId);
        $ids = array_map('intval', (array) $request->query('ids', []));

        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        } else {
            $term = $request->query('q');
            $term = is_string($term) ? trim($term) : '';
            if (mb_strlen($term) < 3) {
                return response()->json([]);
            }
            $escaped = addcslashes($term, '%_\\');
            $query->where('is_active', true)
                ->whereHas('sparepart', function ($inner) use ($escaped) {
                    $inner->where('name', 'like', "%{$escaped}%")->orWhere('code', 'like', "%{$escaped}%");
                });
        }

        return response()->json(
            $query->get()
                ->sortBy(fn (SparepartBranch $sb) => $sb->sparepart->name)
                ->take(20)
                ->map(fn (SparepartBranch $sb) => [
                    'id' => $sb->id,
                    'text' => $sb->sparepart->code . ' — ' . $sb->sparepart->name,
                    'code' => $sb->sparepart->code,
                    'on_hand_qty' => (float) ($sb->stock ? $sb->stock->on_hand_qty : 0),
                ])
                ->values()
        );
    }

    protected function expectedStep(Request $request): ?int
    {
        $step = $request->input('step_no');

        return is_numeric($step) ? (int) $step : null;
    }

    /** The decision lost a race (someone else decided first) — explain instead of a bare 403. */
    protected function alreadyDecided(PurchaseRequest $purchaseRequest)
    {
        return redirect()->route('purchase-requests.show', $purchaseRequest)
            ->with('error', 'Permintaan ini sudah diproses oleh pengguna lain atau langkah approval sudah berpindah.');
    }

    protected function viewableBranches(User $user)
    {
        if ($user->hasPermissionTo('purchase_request.view_all')
            || $user->hasPermissionTo('purchase_request.assign_supplier')
            || $user->hasPermissionTo('approval_setting.manage')) {
            return Branch::orderBy('name')->get();
        }

        return $user->branchesWithPermission('purchase_request.view');
    }

    /**
     * Lock the header and re-check that the requester may still change it; the policy check
     * ran before the transaction, so a concurrent supplier assignment must be caught here.
     */
    protected function lockEditable(PurchaseRequest $purchaseRequest): PurchaseRequest
    {
        $fresh = PurchaseRequest::whereKey($purchaseRequest->id)->lockForUpdate()->firstOrFail();

        abort_unless(
            $fresh->status === PurchaseRequestStatus::WAITING_SUPPLIER
                && ! $fresh->lines()->whereNotNull('supplier_id')->exists(),
            409,
            'Permintaan sudah diproses dan tidak dapat diubah.'
        );

        return $fresh;
    }

    protected function syncLines(PurchaseRequest $purchaseRequest, array $lines): void
    {
        $purchaseRequest->lines()->delete();

        foreach (array_values($lines) as $index => $line) {
            $purchaseRequest->lines()->create([
                'sparepart_branch_id' => $line['sparepart_branch_id'],
                'qty' => (float) $line['qty'],
                'sort_order' => $index,
            ]);
        }
    }
}
