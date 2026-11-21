<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Services\Notifications\SenderFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers one logged notification. Failures are recorded on the log row and swallowed so a
 * provider outage can never break (or, on the sync queue, delay beyond the HTTP timeout) the
 * approval that triggered it.
 */
class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    protected $logId;

    public function __construct(int $logId)
    {
        $this->logId = $logId;
    }

    public function handle(): void
    {
        $log = NotificationLog::find($this->logId);
        if (! $log || $log->status !== 'pending') {
            return;
        }

        $sender = SenderFactory::make();
        $log->channel = $sender->channel();

        try {
            $result = $sender->send($log->recipient, $log->message);
            $log->status = $result['status'];
            $log->response = $result['response'];
        } catch (\Throwable $e) {
            $log->status = 'failed';
            $log->error = mb_substr($e->getMessage(), 0, 2000);
        }

        $log->save();
    }
}
