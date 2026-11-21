<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Http;

/**
 * Telegram Bot API sendMessage. The recipient is a chat id (a user who pressed Start on the
 * bot, or a group the bot was added to). The bot token is part of the request URL, so every
 * error text is scrubbed of it before it can reach a log or the database.
 */
class TelegramSender implements NotificationSender
{
    protected $token;

    public function __construct(?string $token)
    {
        $this->token = $token;
    }

    public function channel(): string
    {
        return 'telegram';
    }

    public function send(string $to, string $message): array
    {
        if (! $this->token) {
            throw new \RuntimeException('TELEGRAM_BOT_TOKEN belum diisi.');
        }

        try {
            $response = Http::timeout(10)->post('https://api.telegram.org/bot' . $this->token . '/sendMessage', [
                'chat_id' => $to,
                'text' => $message,
                'disable_web_page_preview' => true,
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Tidak dapat menghubungi Telegram: ' . $this->scrub($e->getMessage()));
        }

        $body = $response->json();
        if (! $response->successful() || ! data_get($body, 'ok')) {
            $detail = data_get($body, 'description') ?: $response->body();

            throw new \RuntimeException('Telegram menolak pesan (HTTP ' . $response->status() . '): ' . $this->scrub((string) $detail));
        }

        return ['status' => 'sent', 'response' => 'message_id ' . data_get($body, 'result.message_id')];
    }

    protected function scrub(string $text): string
    {
        $text = $this->token ? str_replace($this->token, '***', $text) : $text;

        return mb_substr($text, 0, 1000);
    }
}
