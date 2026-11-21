<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Log;

/** Used when no bot token is configured: the message is written to the application log only. */
class LogSender implements NotificationSender
{
    public function channel(): string
    {
        return 'log';
    }

    public function send(string $to, string $message, array $extra = []): array
    {
        Log::info('Notification (not sent, no provider configured)', ['to' => $to, 'message' => $message]);

        return ['status' => 'logged', 'response' => null];
    }
}
