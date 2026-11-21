<?php

namespace App\Services;

use App\Models\User;
use App\Services\Notifications\TelegramSender;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * What the bot does with an incoming Telegram update. Shared by the long-polling command
 * (telegram:poll) and the webhook endpoint so both behave identically.
 *
 * A bot can only message someone who has pressed Start, and it needs their numeric chat id.
 * /start therefore asks the person to share their phone number with Telegram's contact button;
 * the chat is linked to the active user whose Nomor HP matches. In groups the bot answers with the
 * group's chat id, which can be used as a notification target.
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

        if (! $private) {
            $this->say($chatId, "Chat ID grup ini: {$chatId}\nGunakan angka ini bila notifikasi ingin dikirim ke grup.");

            return;
        }

        $linked = User::where('telegram_chat_id', $chatId)->first();
        if ($linked) {
            $this->say($chatId, "Halo {$name}. Akun Telegram ini sudah terhubung ke pengguna \"{$linked->name}\" di Sistem Manajemen Bengkel. Notifikasi approval akan masuk ke sini.");

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

    protected function say(string $chatId, string $text, array $extra = []): void
    {
        try {
            (new TelegramSender($this->token))->sendWith($chatId, $text, $extra);
        } catch (\Throwable $e) {
            $this->note('warn', "Gagal membalas {$chatId}: " . $e->getMessage());
        }
    }

    protected function note(string $level, string $message): void
    {
        if ($this->log) {
            call_user_func($this->log, $level, $message);
        }
    }
}
