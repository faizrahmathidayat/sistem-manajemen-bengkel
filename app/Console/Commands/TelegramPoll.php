<?php

namespace App\Console\Commands;

use App\Services\TelegramBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Long-polling alternative to the webhook, for a machine without a public HTTPS address (local
 * development): it answers people who write to the bot exactly like the webhook does (see
 * App\Services\TelegramBot). Keep it running in a terminal: php artisan telegram:poll
 * Polling and a registered webhook cannot be active at the same time.
 */
class TelegramPoll extends Command
{
    protected $signature = 'telegram:poll
        {--once : Process what is waiting and exit}
        {--skip-pending : Discard messages that arrived before this run instead of answering them}
        {--wait=25 : Long-polling seconds per request}';

    protected $description = 'Answer Telegram bot messages by long polling (use the webhook in production)';

    protected $token;

    public function handle(): int
    {
        $this->token = config('services.telegram.bot_token');
        if (empty($this->token)) {
            $this->error('TELEGRAM_BOT_TOKEN belum diisi di .env.');

            return 1;
        }

        $bot = new TelegramBot($this->token, function ($level, $message) {
            $level === 'warn' ? $this->warn($message) : $this->info($message);
        });

        $offset = 0;
        try {
            if ($this->option('skip-pending')) {
                $latest = $this->fetch(-1, 0);
                if (! empty($latest)) {
                    $offset = (int) end($latest)['update_id'] + 1;
                    $this->fetch($offset, 0); // acknowledge, so Telegram drops everything older
                }
                $this->info('Pesan lama diabaikan.');
            }
        } catch (\Throwable $e) {
            return $this->fail($e);
        }

        $this->info('Bot mendengarkan pesan masuk. Tekan Ctrl+C untuk berhenti.');

        do {
            try {
                $updates = $this->fetch($offset, $this->option('once') ? 0 : (int) $this->option('wait'));
                foreach ($updates as $update) {
                    $offset = max($offset, (int) $update['update_id'] + 1);
                    $bot->handleUpdate($update);
                }
                if (! empty($updates)) {
                    $this->fetch($offset, 0); // acknowledge processed updates
                }
            } catch (\Throwable $e) {
                if ($this->isWebhookConflict($e) || $this->option('once')) {
                    return $this->fail($e);
                }
                $this->warn('Gagal membaca pesan: ' . $this->scrub($e->getMessage()));
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

    protected function isWebhookConflict(\Throwable $e): bool
    {
        return strpos($e->getMessage(), 'HTTP 409') !== false;
    }

    protected function fail(\Throwable $e): int
    {
        $this->error('Gagal membaca pesan: ' . $this->scrub($e->getMessage()));
        if ($this->isWebhookConflict($e)) {
            $this->line('Webhook sedang aktif, jadi polling tidak bisa dipakai. Hapus dulu dengan: php artisan telegram:webhook delete');
        }

        return 1;
    }

    protected function scrub(string $text): string
    {
        return str_replace((string) $this->token, '***', $text);
    }
}
