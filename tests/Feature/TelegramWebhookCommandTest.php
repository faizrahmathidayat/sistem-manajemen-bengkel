<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWebhookCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.webhook_secret' => 'rahasia_123',
            'app.url' => 'https://bengkel.example.com',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true, 'description' => 'Webhook was set'], 200)]);
    }

    public function test_set_registers_the_webhook_with_the_secret_and_only_message_updates(): void
    {
        $this->artisan('telegram:webhook', ['action' => 'set'])->assertExitCode(0);

        Http::assertSent(function (Request $request) {
            $d = $request->data();

            return $request->url() === 'https://api.telegram.org/bottest-token/setWebhook'
                && $d['url'] === 'https://bengkel.example.com/api/telegram/webhook'
                && $d['secret_token'] === 'rahasia_123'
                && $d['allowed_updates'] === ['message'];
        });
    }

    public function test_set_accepts_an_explicit_url_and_strips_a_trailing_slash(): void
    {
        $this->artisan('telegram:webhook', ['action' => 'set', '--url' => 'https://lain.example.org/'])->assertExitCode(0);

        Http::assertSent(function (Request $request) {
            return $request->data()['url'] === 'https://lain.example.org/api/telegram/webhook';
        });
    }

    public function test_set_refuses_a_non_https_address(): void
    {
        $this->artisan('telegram:webhook', ['action' => 'set', '--url' => 'http://192.168.1.31:8010'])->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_set_refuses_when_the_secret_or_token_is_missing(): void
    {
        config(['services.telegram.webhook_secret' => null]);
        $this->artisan('telegram:webhook', ['action' => 'set'])->assertExitCode(1);

        config(['services.telegram.webhook_secret' => 'rahasia_123', 'services.telegram.bot_token' => null]);
        $this->artisan('telegram:webhook', ['action' => 'set'])->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_set_refuses_a_secret_with_characters_telegram_does_not_allow(): void
    {
        config(['services.telegram.webhook_secret' => 'rahasia dengan spasi!']);

        $this->artisan('telegram:webhook', ['action' => 'set'])->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_info_and_delete_call_the_matching_telegram_methods(): void
    {
        $this->artisan('telegram:webhook', ['action' => 'info'])->assertExitCode(0);
        $this->artisan('telegram:webhook', ['action' => 'delete'])->assertExitCode(0);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.telegram.org/bottest-token/getWebhookInfo';
        });
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.telegram.org/bottest-token/deleteWebhook';
        });
    }

    public function test_an_unknown_action_is_refused(): void
    {
        $this->artisan('telegram:webhook', ['action' => 'hancurkan'])->assertExitCode(1);

        Http::assertNothingSent();
    }
}
