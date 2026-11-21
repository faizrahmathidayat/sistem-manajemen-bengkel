<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Notifications\TelegramSender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Makes the bot answer people who write to it. A bot can only message someone who has pressed
 * Start, and it needs that person's numeric chat id; this command (long polling, no public
 * address needed) replies to any incoming text with the sender's chat id so an admin can enter
 * it on the user form. Keep it running in a terminal: php artisan telegram:poll
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
        if (! $message || ! isset($message['text']) || ! isset($message['chat']['id'])) {
            return;
        }

        $chatId = (string) $message['chat']['id'];
        $name = $message['chat']['first_name'] ?? ($message['chat']['title'] ?? 'Anda');
        $linked = User::where('telegram_chat_id', $chatId)->first();

        $text = $linked
            ? "Halo {$name}. Akun Telegram ini sudah terhubung ke pengguna \"{$linked->name}\" di Sistem Manajemen Bengkel.\nChat ID: {$chatId}"
            : "Halo {$name}.\nChat ID Telegram Anda: {$chatId}\n\nBerikan angka ini ke admin untuk diisi pada data pengguna Anda, supaya notifikasi approval masuk ke sini.";

        try {
            (new TelegramSender($this->token))->send($chatId, $text);
            $this->line("Dibalas: {$chatId} ({$name})");
        } catch (\Throwable $e) {
            $this->warn("Gagal membalas {$chatId}: " . $e->getMessage());
        }
    }

    protected function scrub(string $text): string
    {
        return str_replace((string) $this->token, '***', $text);
    }
}
