<?php

namespace App\Http\Controllers;

use App\Services\TelegramBot;
use Illuminate\Http\Request;

/**
 * Endpoint Telegram calls for every message sent to the bot. Telegram echoes the secret given
 * at setWebhook in the X-Telegram-Bot-Api-Secret-Token header; anything else is refused, and the
 * endpoint refuses everything while no secret is configured. Always answers 200 once the secret
 * checks out, so Telegram does not keep retrying an update we could not process.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        $secret = (string) config('services.telegram.webhook_secret');
        $given = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');

        if ($secret === '' || ! hash_equals($secret, $given)) {
            abort(403);
        }

        try {
            (new TelegramBot(config('services.telegram.bot_token')))->handleUpdate((array) $request->json()->all());
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['ok' => true]);
    }
}
