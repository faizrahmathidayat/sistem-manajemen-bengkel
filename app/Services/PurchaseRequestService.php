<?php

namespace App\Services;

use App\Exceptions\ApprovalChainException;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestApproval;
use App\Models\User;
use App\Support\AuditEvent;
use App\Support\PurchaseRequestApprovalStatus;
use App\Support\PurchaseRequestStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * State transitions of a purchase request after the requester is done with it: submit for
 * approval, approve/reject step by step, and the admin's "reload approvers" repair.
 *
 * Every transition locks the request header first and re-checks the status inside the
 * transaction, so a double click or two approvers of the same level acting together can
 * only take effect once.
 */
class PurchaseRequestService
{
    /**
     * @throws \DomainException      request is not in a submittable state / not fully assigned
     * @throws ApprovalChainException no chain can be built for the total
     */
    public function submit(PurchaseRequest $purchaseRequest, User $actor): void
    {
        DB::transaction(function () use ($purchaseRequest, $actor) {
            $fresh = PurchaseRequest::whereKey($purchaseRequest->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== PurchaseRequestStatus::WAITING_SUPPLIER) {
                throw new \DomainException('Permintaan tidak berada pada status menunggu supplier.');
            }

            $fresh->load('lines');
            if (! $fresh->isAssignmentComplete()) {
                throw new \DomainException('Lengkapi supplier dan harga pada semua baris sebelum mengajukan approval.');
            }

            $total = $fresh->computeTotal();
            $steps = (new ApprovalChainResolver())->resolve($total);
            $this->assertChainCompletable($steps, $fresh, $actor);

            foreach ($steps as $index => $step) {
                $approval = $fresh->approvals()->create([
                    'step_no' => $index + 1,
                    'approval_level_id' => $step['level_id'],
                    'level_name' => $step['level_name'],
                    'status' => PurchaseRequestApprovalStatus::PENDING,
                ]);
                $approval->users()->sync($step['user_ids']);
            }

            $fresh->update([
                'status' => PurchaseRequestStatus::WAITING_APPROVAL,
                'total_amount' => $total,
                'submitted_at' => now(),
                'submitted_by' => $actor->id,
            ]);

            (new AuditLogger())->log(
                AuditEvent::PURCHASE_REQUEST_SUBMITTED, $fresh->branch_id, $fresh,
                ['status' => PurchaseRequestStatus::WAITING_SUPPLIER],
                ['status' => PurchaseRequestStatus::WAITING_APPROVAL, 'total_amount' => $total, 'steps' => array_column($steps, 'level_name')]
            );
        });
    }

    /**
     * @throws AuthorizationException the actor may not decide the current step
     * @throws \DomainException       the request is not waiting for approval
     */
    public function approve(PurchaseRequest $purchaseRequest, User $actor, ?string $note = null, ?int $expectedStep = null): void
    {
        DB::transaction(function () use ($purchaseRequest, $actor, $note, $expectedStep) {
            [$fresh, $step] = $this->lockCurrentStep($purchaseRequest, $actor, $expectedStep);

            $step->update([
                'status' => PurchaseRequestApprovalStatus::APPROVED,
                'acted_by' => $actor->id,
                'acted_at' => now(),
                'notes' => $note !== null && trim($note) !== '' ? trim($note) : null,
            ]);

            $isFinal = ! $fresh->approvals()->where('status', PurchaseRequestApprovalStatus::PENDING)->exists();
            if ($isFinal) {
                $fresh->update(['status' => PurchaseRequestStatus::APPROVED]);
                (new PurchaseOrderGenerator())->generateFor($fresh);
            }

            (new AuditLogger())->log(
                AuditEvent::PURCHASE_REQUEST_APPROVED, $fresh->branch_id, $fresh,
                ['status' => PurchaseRequestStatus::WAITING_APPROVAL],
                ['step' => $step->step_no, 'level' => $step->level_name, 'final' => $isFinal]
            );
        });
    }

    public function reject(PurchaseRequest $purchaseRequest, User $actor, string $reason, ?int $expectedStep = null): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Alasan penolakan wajib diisi.');
        }

        DB::transaction(function () use ($purchaseRequest, $actor, $reason, $expectedStep) {
            [$fresh, $step] = $this->lockCurrentStep($purchaseRequest, $actor, $expectedStep);

            $step->update([
                'status' => PurchaseRequestApprovalStatus::REJECTED,
                'acted_by' => $actor->id,
                'acted_at' => now(),
                'notes' => $reason,
            ]);
            $fresh->approvals()
                ->where('status', PurchaseRequestApprovalStatus::PENDING)
                ->update(['status' => PurchaseRequestApprovalStatus::VOID]);
            $fresh->update(['status' => PurchaseRequestStatus::REJECTED, 'rejected_reason' => $reason]);

            (new AuditLogger())->log(
                AuditEvent::PURCHASE_REQUEST_REJECTED, $fresh->branch_id, $fresh,
                ['status' => PurchaseRequestStatus::WAITING_APPROVAL],
                ['status' => PurchaseRequestStatus::REJECTED, 'step' => $step->step_no, 'level' => $step->level_name, 'reason' => $reason]
            );
        });
    }

    /**
     * Repair path for a stuck request: every approver snapshotted on the current step has
     * been deactivated. Copies the level's current active users onto that step.
     *
     * @throws AuthorizationException actor lacks approval_setting.manage
     * @throws \DomainException       request/step cannot be repaired
     * @throws ApprovalChainException the level currently has no active user either
     */
    public function reloadApprovers(PurchaseRequest $purchaseRequest, User $actor): void
    {
        if (! $actor->hasPermissionTo('approval_setting.manage')) {
            throw new AuthorizationException('Tidak berwenang memuat ulang approver.');
        }

        DB::transaction(function () use ($purchaseRequest, $actor) {
            $fresh = PurchaseRequest::whereKey($purchaseRequest->id)->lockForUpdate()->firstOrFail();
            if ($fresh->status !== PurchaseRequestStatus::WAITING_APPROVAL) {
                throw new \DomainException('Permintaan tidak sedang menunggu approval.');
            }

            $step = $this->currentStep($fresh, true);
            if (! $step) {
                throw new \DomainException('Tidak ada langkah approval yang menunggu.');
            }
            if (! $step->approval_level_id) {
                throw new \DomainException("Level {$step->level_name} sudah tidak ada pada pengaturan.");
            }

            $userIds = User::whereIn('id', function ($query) use ($step) {
                $query->select('user_id')->from('approval_level_users')->where('approval_level_id', $step->approval_level_id);
            })->where('is_active', true)->pluck('id')->all();

            if (empty($userIds)) {
                throw new ApprovalChainException("Approver level {$step->level_name} belum diatur.");
            }

            $step->users()->sync($userIds);

            (new AuditLogger())->log(
                AuditEvent::APPROVAL_SETTING_CHANGED, $fresh->branch_id, $fresh,
                [], ['reload_approvers' => ['step' => $step->step_no, 'level' => $step->level_name, 'user_ids' => $userIds]]
            );
        });
    }

    /**
     * Non-locking check used by the policy and by views: may this user decide the request's
     * current step right now?
     */
    public function canAct(PurchaseRequest $purchaseRequest, User $user): bool
    {
        if ($purchaseRequest->status !== PurchaseRequestStatus::WAITING_APPROVAL) {
            return false;
        }

        $step = $this->currentStep($purchaseRequest, false);

        return $step !== null && $this->mayDecide($purchaseRequest, $step, $user);
    }

    /**
     * True when nobody can decide the current step any more (every approver snapshotted on it
     * is inactive, is the requester/submitter, or already decided another step).
     */
    public function isStuck(PurchaseRequest $purchaseRequest): bool
    {
        if ($purchaseRequest->status !== PurchaseRequestStatus::WAITING_APPROVAL) {
            return false;
        }

        $step = $this->currentStep($purchaseRequest, false);
        if (! $step) {
            return false;
        }

        foreach ($step->users()->get() as $user) {
            if ($this->mayDecide($purchaseRequest, $step, $user)) {
                return false;
            }
        }

        return true;
    }

    /** True when the user is configured as an approver anywhere (level setting or a request snapshot). */
    public function isApprover(User $user): bool
    {
        return DB::table('approval_level_users')->where('user_id', $user->id)->exists()
            || DB::table('purchase_request_approval_users')->where('user_id', $user->id)->exists();
    }

    /** Requests whose current step this user may decide ("Menunggu Saya"). */
    public function waitingFor(User $user): Builder
    {
        $userId = (int) $user->id;

        return PurchaseRequest::query()
            ->where('status', PurchaseRequestStatus::WAITING_APPROVAL)
            ->where(function ($query) use ($userId) {
                $query->whereNull('created_by')->orWhere('created_by', '!=', $userId);
            })
            ->where(function ($query) use ($userId) {
                $query->whereNull('submitted_by')->orWhere('submitted_by', '!=', $userId);
            })
            ->whereDoesntHave('approvals', function ($query) use ($userId) {
                $query->where('acted_by', $userId);
            })
            ->whereHas('approvals', function ($query) use ($userId) {
                $query->where('status', PurchaseRequestApprovalStatus::PENDING)
                    ->whereRaw(
                        'step_no = (select min(pra2.step_no) from purchase_request_approvals pra2 '
                        . 'where pra2.purchase_request_id = purchase_request_approvals.purchase_request_id and pra2.status = ?)',
                        [PurchaseRequestApprovalStatus::PENDING]
                    )
                    ->whereHas('users', function ($users) use ($userId) {
                        $users->where('users.id', $userId);
                    });
            });
    }

    /**
     * Lock the request, then its current pending step, and verify the actor may decide it.
     *
     * @return array{0: PurchaseRequest, 1: PurchaseRequestApproval}
     */
    protected function lockCurrentStep(PurchaseRequest $purchaseRequest, User $actor, ?int $expectedStep = null): array
    {
        $fresh = PurchaseRequest::whereKey($purchaseRequest->id)->lockForUpdate()->firstOrFail();

        if ($fresh->status !== PurchaseRequestStatus::WAITING_APPROVAL) {
            throw new \DomainException('Permintaan tidak sedang menunggu approval.');
        }

        $step = $this->currentStep($fresh, true);

        // The decision form names the step it was rendered for. If someone else decided that
        // step meanwhile, refuse instead of silently deciding the next step on the user's behalf.
        if ($step && $expectedStep !== null && (int) $step->step_no !== $expectedStep) {
            throw new \DomainException('Langkah approval ini sudah diproses oleh pengguna lain. Muat ulang halaman untuk melihat status terbaru.');
        }

        if (! $step || ! $this->mayDecide($fresh, $step, $actor)) {
            throw new AuthorizationException('Anda tidak berwenang memutuskan langkah approval ini.');
        }

        return [$fresh, $step];
    }

    /**
     * A chain is only worth creating if distinct people can actually complete it: the requester
     * and the submitter may not decide, and one person may decide only one step. Without this a
     * request could sit in "waiting approval" with no way forward and no way to cancel it.
     *
     * @param array<int, array{level_id:int, level_name:string, user_ids:int[]}> $steps
     *
     * @throws ApprovalChainException
     */
    protected function assertChainCompletable(array $steps, PurchaseRequest $purchaseRequest, User $submitter): void
    {
        $excluded = array_filter([(int) $purchaseRequest->created_by, (int) $submitter->id]);
        $eligible = [];

        foreach ($steps as $step) {
            $users = array_values(array_diff($step['user_ids'], $excluded));
            if (empty($users)) {
                throw new ApprovalChainException(
                    "Level {$step['level_name']} hanya memiliki approver yang tidak boleh memproses permintaan ini "
                    . '(pemohon atau pengaju). Tambahkan approver lain di Pengaturan Approval.'
                );
            }
            $eligible[] = $users;
        }

        if (! $this->hasDistinctAssignment($eligible, 0, [])) {
            throw new ApprovalChainException(
                'Rantai approval tidak dapat diselesaikan: satu orang tidak boleh memproses lebih dari satu level. '
                . 'Tambahkan approver lain pada level yang berbagi approver di Pengaturan Approval.'
            );
        }
    }

    /** Can every step get a different user? (small backtracking search; chains have a handful of steps) */
    protected function hasDistinctAssignment(array $eligible, int $index, array $used): bool
    {
        if ($index === count($eligible)) {
            return true;
        }

        foreach ($eligible[$index] as $userId) {
            if (isset($used[$userId])) {
                continue;
            }
            $used[$userId] = true;
            if ($this->hasDistinctAssignment($eligible, $index + 1, $used)) {
                return true;
            }
            unset($used[$userId]);
        }

        return false;
    }

    protected function currentStep(PurchaseRequest $purchaseRequest, bool $lock): ?PurchaseRequestApproval
    {
        $query = $purchaseRequest->approvals()
            ->where('status', PurchaseRequestApprovalStatus::PENDING)
            ->reorder()->orderBy('step_no');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * The actor must be an active approver snapshotted on this step, must not be the person
     * who requested or submitted it, and may decide only one step per request.
     */
    protected function mayDecide(PurchaseRequest $purchaseRequest, PurchaseRequestApproval $step, User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }
        if ((int) $purchaseRequest->created_by === (int) $user->id || (int) $purchaseRequest->submitted_by === (int) $user->id) {
            return false;
        }
        if (! $step->users()->where('users.id', $user->id)->exists()) {
            return false;
        }

        return ! $purchaseRequest->approvals()->where('acted_by', $user->id)->exists();
    }
}
