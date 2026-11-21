<?php

namespace App\Services\Notifications;

interface NotificationSender
{
    /** Short channel name stored on the notification log. */
    public function channel(): string;

    /**
     * Deliver a text message.
     *
     * @return array{status:string, response:?string} status is "sent" or "logged"
     *
     * @throws \RuntimeException the provider refused or could not be reached
     */
    public function send(string $to, string $message): array;
}
