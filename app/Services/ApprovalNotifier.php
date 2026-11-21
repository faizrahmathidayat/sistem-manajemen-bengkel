<?php

namespace App\Services;

use App\Jobs\SendNotificationJob;
use App\Models\NotificationLog;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Support\PurchaseRequestStatus;
use Illuminate\Support\Str;

/**
 * Telegram notifications for the purchase request approval flow. Call notify() after a
 * transition has been committed; it looks at the request's new state:
 *  - waiting for approval            -> approvers of the step whose turn it is
 *  - approved / rejected / cancelled -> the requester
 * Nothing here may break the transition: every error is reported and swallowed.
 */
class ApprovalNotifier
{
    public function notify(PurchaseRequest $purchaseRequest): void
    {
        try {
            $request = $purchaseRequest->fresh(['branch', 'creator', 'approvals.users']);
            if (! $request) {
                return;
            }

            switch ($request->status) {
                case PurchaseRequestStatus::WAITING_APPROVAL:
                    $step = $request->approvals->where('status', 'pending')->sortBy('step_no')->first();
                    if ($step) {
                        foreach ($step->users as $approver) {
                            $card = TelegramApprovalMessage::build($request, $step, $approver);
                            $this->send([$approver], $request, 'step_ready', $card['text'], ['reply_markup' => $card['reply_markup']]);
                        }
                    }
                    break;
                case PurchaseRequestStatus::APPROVED:
                    $this->send($this->requester($request), $request, 'approved', "Permintaan {$request->number} telah disetujui. Pembelian sedang disiapkan.\n" . $this->link($request));
                    break;
                case PurchaseRequestStatus::REJECTED:
                    $reason = Str::limit((string) $request->rejected_reason, 200);
                    $this->send($this->requester($request), $request, 'rejected', "Permintaan {$request->number} ditolak.\nAlasan: {$reason}\n" . $this->link($request));
                    break;
                case PurchaseRequestStatus::CANCELLED:
                    if ((int) $request->cancelled_by !== (int) $request->created_by) {
                        $this->send($this->requester($request), $request, 'cancelled', "Permintaan {$request->number} dibatalkan.\n" . $this->link($request));
                    }
                    break;
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Links use NOTIFY_BASE_URL (e.g. the LAN address) so they open from a phone; falls back to APP_URL. */
    protected function link(PurchaseRequest $request): string
    {
        $base = rtrim((string) (config('services.approval_notifications.base_url') ?: config('app.url')), '/');

        return $base . route('purchase-requests.show', $request, false);
    }

    protected function requester(PurchaseRequest $request)
    {
        return collect([$request->creator])->filter();
    }

    /** @param iterable<User> $users */
    protected function send($users, PurchaseRequest $request, string $event, string $message, array $payload = []): void
    {
        foreach ($users as $user) {
            if (! $user->is_active || ! $user->notify_enabled || empty($user->telegram_chat_id)) {
                continue;
            }

            try {
                $log = NotificationLog::create([
                    'user_id' => $user->id,
                    'purchase_request_id' => $request->id,
                    'event' => $event,
                    'recipient' => $user->telegram_chat_id,
                    'message' => $message,
                    'payload' => $payload ?: null,
                    'status' => 'pending',
                ]);
                SendNotificationJob::dispatch($log->id);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
