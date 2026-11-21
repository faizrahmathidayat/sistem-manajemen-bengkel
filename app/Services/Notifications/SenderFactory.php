<?php

namespace App\Services\Notifications;

class SenderFactory
{
    /**
     * "auto" (default) uses Telegram when TELEGRAM_BOT_TOKEN is set and otherwise only logs;
     * "telegram" always tries Telegram (a missing token then shows up as a failed log row);
     * "log" never sends.
     */
    public static function make(): NotificationSender
    {
        $channel = (string) config('services.approval_notifications.channel', 'auto');
        $token = config('services.telegram.bot_token');

        if ($channel === 'log' || ($channel === 'auto' && empty($token))) {
            return new LogSender();
        }

        return new TelegramSender($token);
    }
}
