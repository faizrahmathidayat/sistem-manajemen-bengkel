<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use App\Services\Notifications\TelegramSender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Makes the bot answer people who write to it. A bot can only message someone who has pressed
 * Start, and it needs that person's numeric chat id; this command (long polling, no public
 * address needed) answers /start by asking the person to share their phone number with Telegram's
 * contact button, then links the chat to the user whose Nomor HP matches. In groups it replies with the
 * group's chat id. Keep it running in a terminal: php artisan telegram:poll
 */
class TelegramPoll extends Command
{
    protected $signature = 'telegram:poll
        {--once : Process what is waiting and exit}
        {--skip-pending : Discard messages that arrived before this run instead of answering them}
        {--wait=25 : Long-polling seconds per request}';

    protected $description = 'Reply to Telegram bot messages with the sender\'s chat id';

    protected $token;

    public function handle(): int
    {
        $this->token = config('services.telegram.bot_token');
        if (empty($this->token)) {
            $this->error('TELEGRAM_BOT_TOKEN belum diisi di .env.');

            return 1;
        }

        $offset = 0;
        if ($this->option('skip-pending')) {
            $latest = $this->fetch(-1, 0);
            if (! empty($latest)) {
                $offset = (int) end($latest)['update_id'] + 1;
                $this->fetch($offset, 0); // acknowledge, so Telegram drops everything older
            }
            $this->info('Pesan lama diabaikan.');
        }

        $this->info('Bot mendengarkan pesan masuk. Tekan Ctrl+C untuk berhenti.');

        do {
            try {
                $updates = $this->fetch($offset, $this->option('once') ? 0 : (int) $this->option('wait'));
                foreach ($updates as $update) {
                    $offset = max($offset, (int) $update['update_id'] + 1);
                    $this->reply($update);
                }
                if (! empty($updates)) {
                    $this->fetch($offset, 0); // acknowledge processed updates
                }
            } catch (\Throwable $e) {
                $this->warn('Gagal membaca pesan: ' . $this->scrub($e->getMessage()));
                if ($this->option('once')) {
                    return 1;
                }
                sleep(5);
            }
        } while (! $this->option('once'));

        return 0;
    }

    /** @return array<int, array> */
    protected function fetch(int $offset, int $wait): array
    {
        $response = Http::timeout($wait + 10)->get('https://api.telegram.org/bot' . $this->token . '/getUpdates', [
            'offset' => $offset,
            'timeout' => $wait,
            'limit' => 50,
        ]);

        if (! $response->successful() || ! data_get($response->json(), 'ok')) {
            throw new \RuntimeException('Telegram HTTP ' . $response->status() . ': ' . (string) data_get($response->json(), 'description'));
        }

        return (array) data_get($response->json(), 'result', []);
    }

    protected function reply(array $update): void
    {
        $message = $update['message'] ?? null;
        if (! $message || ! isset($message['chat']['id'])) {
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
            // Contacts cannot be requested in groups; show the group's id so it can be used as a notification target.
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
            $this->info("Terhubung: {$chatId} -> {$user->name}");

            return;
        }

        $reason = $users->count() > 1
            ? 'Nomor ini terdaftar pada lebih dari satu pengguna, jadi tidak dapat dihubungkan otomatis.'
            : 'Nomor ini belum terdaftar di Sistem Manajemen Bengkel.';
        $this->say($chatId, "{$reason}\nMinta admin mengisi Nomor HP Anda di data pengguna, lalu coba lagi. (Chat ID Anda: {$chatId})", [
            'reply_markup' => ['remove_keyboard' => true],
        ]);
        $this->warn("Tidak terhubung: {$chatId} ({$name}) - {$reason}");
    }

    protected function say(string $chatId, string $text, array $extra = []): void
    {
        try {
            (new TelegramSender($this->token))->sendWith($chatId, $text, $extra);
        } catch (\Throwable $e) {
            $this->warn("Gagal membalas {$chatId}: " . $e->getMessage());
        }
    }

    protected function scrub(string $text): string
    {
        return str_replace((string) $this->token, '***', $text);
    }
}
