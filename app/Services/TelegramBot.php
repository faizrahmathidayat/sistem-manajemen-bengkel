<?php

namespace App\Services;

use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\Notifications\TelegramSender;
use App\Support\PhoneNumber;
use App\Support\PurchaseRequestStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What the bot does with an incoming Telegram update. Shared by the long-polling command
 * (telegram:poll) and the webhook endpoint so both behave identically.
 *
 * Linking: a bot can only message someone who has pressed Start, and it needs their numeric chat id.
 * /start therefore asks the person to share their phone number with Telegram's contact button; the chat
 * is linked to the active user whose Nomor HP matches. In groups the bot answers with the group's chat id.
 *
 * Approving: approvers receive a message with buttons (see TelegramApprovalMessage). Presses are mapped
 * to the linked user and executed through PurchaseRequestService, so every rule of the web flow applies
 * (current step only, one person per level, stale-step guard, item selection rights).
 */
class TelegramBot
{
    protected $token;

    /** @var callable|null function (string $level, string $message): void, level is info|warn */
    protected $log;

    public function __construct(?string $token, ?callable $log = null)
    {
        $this->token = $token;
        $this->log = $log;
    }

    public function handleUpdate(array $update): void
    {
        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);

            return;
        }

        $message = $update['message'] ?? null;
        if (! is_array($message) || ! isset($message['chat']['id'])) {
            return;
        }

        $chat = $message['chat'];
        $chatId = (string) $chat['id'];
        $private = ($chat['type'] ?? 'private') === 'private';
        $name = $chat['first_name'] ?? ($chat['title'] ?? 'Anda');

        if (isset($message['contact'])) {
            $this->handleContact($message, $chatId, $name);

            return;
        }

        if (! isset($message['text'])) {
            return;
        }

        if ($private && Cache::has($this->rejectKey($chatId)) && $this->handleRejectReason($chatId, trim((string) $message['text']))) {
            return;
        }

        if (! $private) {
            $this->say($chatId, "Chat ID grup ini: {$chatId}\nGunakan angka ini bila notifikasi ingin dikirim ke grup.");

            return;
        }

        $linked = User::where('telegram_chat_id', $chatId)->first();
        if ($linked) {
            $this->say($chatId, "Halo {$name}. Akun Telegram ini sudah terhubung ke pengguna \"{$linked->name}\" di Sistem Manajemen Bengkel. Notifikasi approval akan masuk ke sini, lengkap dengan tombol Setuju dan Tolak.");

            return;
        }

        $this->say($chatId, "Halo {$name}. Untuk menghubungkan akun Anda ke Sistem Manajemen Bengkel, tekan tombol \"Bagikan nomor HP\" di bawah. Nomor harus sama dengan Nomor HP yang terdaftar di akun Anda.", [
            'reply_markup' => [
                'keyboard' => [[['text' => 'Bagikan nomor HP', 'request_contact' => true]]],
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ],
        ]);
    }

    // ---- approval buttons -------------------------------------------------------------------

    protected function handleCallback(array $cb): void
    {
        $callbackId = (string) ($cb['id'] ?? '');
        $fromId = (string) ($cb['from']['id'] ?? '');
        $chatId = (string) ($cb['message']['chat']['id'] ?? '');
        $messageId = $cb['message']['message_id'] ?? null;

        if ($callbackId === '' || $fromId === '' || $fromId !== $chatId || $messageId === null) {
            $this->answer($callbackId, 'Gunakan tombol ini lewat chat pribadi dengan bot.', true);

            return;
        }

        $parts = explode(':', (string) ($cb['data'] ?? ''));
        $action = $parts[0] ?? '';
        $requestId = (int) ($parts[1] ?? 0);
        $stepNo = (int) ($parts[2] ?? 0);
        $lineId = (int) ($parts[3] ?? 0);
        if (! in_array($action, ['a', 'y', 'n', 'r', 't'], true) || $requestId <= 0 || $stepNo <= 0) {
            $this->answer($callbackId, 'Tombol tidak dikenali.', true);

            return;
        }

        $user = User::where('telegram_chat_id', $chatId)->where('is_active', true)->first();
        if (! $user) {
            $this->answer($callbackId, 'Akun Telegram ini belum terhubung ke pengguna mana pun.', true);

            return;
        }

        $request = PurchaseRequest::with(['branch', 'creator', 'approvals.users', 'lines.sparepartBranch.sparepart', 'lines.supplier'])->find($requestId);
        if (! $request) {
            $this->answer($callbackId, 'Permintaan tidak ditemukan.', true);

            return;
        }

        $current = $request->status === PurchaseRequestStatus::WAITING_APPROVAL
            ? $request->approvals->where('status', 'pending')->sortBy('step_no')->first()
            : null;
        if (! $current || (int) $current->step_no !== $stepNo) {
            $status = PurchaseRequestStatus::LABELS[$request->status] ?? $request->status;
            $this->edit($chatId, $messageId, "Permintaan {$request->number} sudah diproses (status: {$status}). Tombol ini tidak berlaku lagi.", []);
            $this->answer($callbackId, 'Sudah diproses.', false);

            return;
        }

        $service = app(PurchaseRequestService::class);
        if (! $service->canAct($request, $user)) {
            $this->answer($callbackId, 'Anda tidak berwenang memutuskan langkah ini.', true);

            return;
        }

        switch ($action) {
            case 'a':
                $this->render($chatId, $messageId, $request, $current, $user, true);
                $this->answer($callbackId, 'Tekan "Ya, setujui" untuk memastikan.', false);
                break;
            case 'n':
                $this->render($chatId, $messageId, $request, $current, $user, false);
                $this->answer($callbackId, 'Dibatalkan.', false);
                break;
            case 't':
                if (! $current->maySelectItems()) {
                    $this->answer($callbackId, 'Level Anda tidak dapat memilih barang.', true);
                    break;
                }
                $selected = TelegramApprovalMessage::selectedIds($request, $current, $user);
                $selectable = $request->lines->filter(function ($l) {
                    return (bool) $l->is_selected;
                })->pluck('id')->map(function ($id) {
                    return (int) $id;
                })->all();
                if (! in_array($lineId, $selectable, true)) {
                    $this->answer($callbackId, 'Barang tidak dapat diubah.', true);
                    break;
                }
                $selected = in_array($lineId, $selected, true) ? array_values(array_diff($selected, [$lineId])) : array_merge($selected, [$lineId]);
                TelegramApprovalMessage::storeSelection($request, $current, $user, $selected);
                $this->render($chatId, $messageId, $request, $current, $user, false);
                $this->answer($callbackId, '', false);
                break;
            case 'r':
                Cache::put($this->rejectKey($chatId), ['request' => $request->id, 'step' => $stepNo], now()->addMinutes(30));
                $this->say($chatId, "Tulis alasan penolakan untuk {$request->number}. Kirim /batal untuk membatalkan.", [
                    'reply_markup' => ['force_reply' => true, 'input_field_placeholder' => 'Alasan penolakan'],
                ]);
                $this->answer($callbackId, 'Tulis alasannya di chat.', false);
                break;
            case 'y':
                $this->approve($callbackId, $chatId, $messageId, $request, $current, $user, $service);
                break;
        }
    }

    protected function approve(string $callbackId, string $chatId, $messageId, PurchaseRequest $request, $step, User $user, PurchaseRequestService $service): void
    {
        $selected = $step->maySelectItems() ? TelegramApprovalMessage::selectedIds($request, $step, $user) : null;

        try {
            $service->approve($request, $user, 'Disetujui via Telegram', (int) $step->step_no, $selected);
        } catch (\DomainException $e) {
            $this->answer($callbackId, $e->getMessage(), true);
            $this->render($chatId, $messageId, $request, $step, $user, false);

            return;
        } catch (AuthorizationException $e) {
            $this->answer($callbackId, 'Sudah diproses atau Anda tidak berwenang.', true);

            return;
        }

        TelegramApprovalMessage::forgetSelection($request, $step, $user);
        $fresh = $request->fresh();
        $this->edit($chatId, $messageId, "✅ Anda menyetujui {$request->number}.\nTotal disetujui: Rp " . number_format((float) $fresh->total_amount, 0, ',', '.'), []);
        $this->answer($callbackId, 'Disetujui ✅', false);
        $this->note('info', "Disetujui via Telegram: {$request->number} oleh {$user->name}");
    }

    /** The pending "reason for rejection" reply. Returns true when the message was consumed. */
    protected function handleRejectReason(string $chatId, string $text): bool
    {
        $pending = Cache::get($this->rejectKey($chatId));
        if (! is_array($pending)) {
            return false;
        }

        if ($text === '/batal') {
            Cache::forget($this->rejectKey($chatId));
            $this->say($chatId, 'Penolakan dibatalkan.');

            return true;
        }
        if ($text === '' || strpos($text, '/') === 0) {
            Cache::forget($this->rejectKey($chatId));

            return false; // some other command: forget the rejection and handle it normally
        }

        Cache::forget($this->rejectKey($chatId));
        $user = User::where('telegram_chat_id', $chatId)->where('is_active', true)->first();
        $request = PurchaseRequest::find((int) ($pending['request'] ?? 0));
        if (! $user || ! $request) {
            $this->say($chatId, 'Permintaan atau akun tidak ditemukan.');

            return true;
        }

        try {
            app(PurchaseRequestService::class)->reject($request, $user, mb_substr($text, 0, 1000), (int) ($pending['step'] ?? 0));
            $this->say($chatId, "❌ Permintaan {$request->number} ditolak. Alasan: " . mb_substr($text, 0, 200));
            $this->note('info', "Ditolak via Telegram: {$request->number} oleh {$user->name}");
        } catch (\DomainException $e) {
            $this->say($chatId, $e->getMessage());
        } catch (AuthorizationException $e) {
            $this->say($chatId, 'Permintaan sudah diproses atau Anda tidak berwenang.');
        } catch (\InvalidArgumentException $e) {
            $this->say($chatId, $e->getMessage());
        }

        return true;
    }

    protected function render(string $chatId, $messageId, PurchaseRequest $request, $step, User $user, bool $confirm): void
    {
        $card = TelegramApprovalMessage::build($request, $step, $user, $confirm);
        $this->edit($chatId, $messageId, $card['text'], $card['reply_markup']['inline_keyboard']);
    }

    protected function rejectKey(string $chatId): string
    {
        return 'tg_reject:' . $chatId;
    }

    // ---- linking ----------------------------------------------------------------------------

    protected function handleContact(array $message, string $chatId, string $name): void
    {
        $contact = $message['contact'];
        $fromId = (string) ($message['from']['id'] ?? '');

        // Telegram verifies the number only when it is the sender's own contact.
        if ($fromId === '' || (string) ($contact['user_id'] ?? '') !== $fromId) {
            $this->say($chatId, 'Bagikan nomor HP Anda sendiri lewat tombol "Bagikan nomor HP", bukan kontak orang lain.');

            return;
        }

        $phone = PhoneNumber::normalize($contact['phone_number'] ?? null);
        $users = $phone ? User::where('phone_number', $phone)->where('is_active', true)->get() : collect();

        if ($users->count() === 1) {
            $user = $users->first();
            DB::transaction(function () use ($user, $chatId) {
                User::where('telegram_chat_id', $chatId)->where('id', '!=', $user->id)->update(['telegram_chat_id' => null]);
                $user->telegram_chat_id = $chatId;
                $user->save();
            });
            $this->say($chatId, "Terhubung. Akun Telegram ini sekarang terhubung ke pengguna \"{$user->name}\". Notifikasi approval akan masuk ke sini.", [
                'reply_markup' => ['remove_keyboard' => true],
            ]);
            $this->note('info', "Terhubung: {$chatId} -> {$user->name}");

            return;
        }

        $reason = $users->count() > 1
            ? 'Nomor ini terdaftar pada lebih dari satu pengguna, jadi tidak dapat dihubungkan otomatis.'
            : 'Nomor ini belum terdaftar di Sistem Manajemen Bengkel.';
        $this->say($chatId, "{$reason}\nMinta admin mengisi Nomor HP Anda di data pengguna, lalu coba lagi. (Chat ID Anda: {$chatId})", [
            'reply_markup' => ['remove_keyboard' => true],
        ]);
        $this->note('warn', "Tidak terhubung: {$chatId} ({$name}) - {$reason}");
    }

    // ---- Telegram calls (never throw) --------------------------------------------------------

    protected function say(string $chatId, string $text, array $extra = []): void
    {
        $this->safely(function () use ($chatId, $text, $extra) {
            (new TelegramSender($this->token))->sendWith($chatId, $text, $extra);
        }, "membalas {$chatId}");
    }

    protected function edit(string $chatId, $messageId, string $text, array $keyboard): void
    {
        $this->safely(function () use ($chatId, $messageId, $text, $keyboard) {
            (new TelegramSender($this->token))->api('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'disable_web_page_preview' => true,
                'reply_markup' => ['inline_keyboard' => $keyboard],
            ]);
        }, "mengubah pesan {$messageId}");
    }

    protected function answer(string $callbackId, string $text, bool $alert): void
    {
        if ($callbackId === '') {
            return;
        }
        $this->safely(function () use ($callbackId, $text, $alert) {
            (new TelegramSender($this->token))->api('answerCallbackQuery', array_filter([
                'callback_query_id' => $callbackId,
                'text' => $text !== '' ? mb_substr($text, 0, 190) : null,
                'show_alert' => $alert ?: null,
            ], function ($v) {
                return $v !== null;
            }));
        }, 'menjawab tombol');
    }

    protected function safely(callable $fn, string $what): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->note('warn', "Gagal {$what}: " . $e->getMessage());
        }
    }

    protected function note(string $level, string $message): void
    {
        if ($this->log) {
            call_user_func($this->log, $level, $message);
        }
    }
}
