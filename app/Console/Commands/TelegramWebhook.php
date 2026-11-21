<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Registers, inspects or removes the Telegram webhook. It only calls the Telegram API, so it can
 * be run from any machine that has the bot token in its .env (the server, or your own computer).
 */
class TelegramWebhook extends Command
{
    protected $signature = 'telegram:webhook
        {action : set | info | delete}
        {--url= : Public https address of the app (default: APP_URL)}';

    protected $description = 'Register, inspect or remove the Telegram bot webhook';

    protected $token;

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, ['set', 'info', 'delete'], true)) {
            $this->error('Aksi tidak dikenal. Pilih: set, info, atau delete.');

            return 1;
        }

        $this->token = config('services.telegram.bot_token');
        if (empty($this->token)) {
            $this->error('TELEGRAM_BOT_TOKEN belum diisi di .env.');

            return 1;
        }

        switch ($action) {
            case 'set':
                return $this->set();
            case 'delete':
                return $this->callTelegram('deleteWebhook', [], 'Webhook dihapus. Polling (telegram:poll) bisa dipakai lagi.');
            default:
                return $this->showInfo();
        }
    }

    protected function set(): int
    {
        $secret = (string) config('services.telegram.webhook_secret');
        if ($secret === '') {
            $this->error('TELEGRAM_WEBHOOK_SECRET belum diisi di .env (huruf, angka, _ atau -, maksimal 256 karakter).');

            return 1;
        }
        if (! preg_match('/^[A-Za-z0-9_-]{1,256}$/', $secret)) {
            $this->error('TELEGRAM_WEBHOOK_SECRET hanya boleh berisi huruf, angka, _ dan -.');

            return 1;
        }

        $base = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        if (stripos($base, 'https://') !== 0) {
            $this->error('Alamat webhook harus HTTPS dan dapat diakses dari internet (sekarang: ' . ($base ?: 'kosong') . '). Gunakan --url=https://domain-anda.');

            return 1;
        }

        $url = $base . '/api/telegram/webhook';
        $this->line('Mendaftarkan webhook ke ' . $url);

        return $this->callTelegram('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message'],
        ], 'Webhook terdaftar. Bot kini menjawab otomatis tanpa telegram:poll.');
    }

    protected function showInfo(): int
    {
        $response = $this->request('getWebhookInfo', []);
        if ($response === null) {
            return 1;
        }

        $info = (array) data_get($response, 'result', []);
        $this->line('Alamat webhook   : ' . (! empty($info['url']) ? $info['url'] : '(belum diatur)'));
        $this->line('Update tertunda  : ' . ($info['pending_update_count'] ?? 0));
        if (! empty($info['last_error_message'])) {
            $this->warn('Error terakhir    : ' . $info['last_error_message']);
        }

        return 0;
    }

    protected function callTelegram(string $method, array $payload, string $success): int
    {
        $response = $this->request($method, $payload);
        if ($response === null) {
            return 1;
        }

        $this->info($success);

        return 0;
    }

    /** @return array|null decoded response, or null after printing an error */
    protected function request(string $method, array $payload): ?array
    {
        try {
            $response = Http::timeout(15)->post('https://api.telegram.org/bot' . $this->token . '/' . $method, $payload);
        } catch (\Throwable $e) {
            $this->error('Tidak dapat menghubungi Telegram: ' . str_replace($this->token, '***', $e->getMessage()));

            return null;
        }

        if (! $response->successful() || ! data_get($response->json(), 'ok')) {
            $this->error('Telegram menolak: ' . (string) (data_get($response->json(), 'description') ?: 'HTTP ' . $response->status()));

            return null;
        }

        return (array) $response->json();
    }
}
