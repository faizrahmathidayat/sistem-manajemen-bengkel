<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.webhook_secret' => 'rahasia_123']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
    }

    protected function startUpdate(int $chatId = 4242, string $name = 'Budi'): array
    {
        return ['update_id' => 1, 'message' => [
            'message_id' => 1,
            'from' => ['id' => $chatId, 'first_name' => $name],
            'chat' => ['id' => $chatId, 'type' => 'private', 'first_name' => $name],
            'text' => '/start',
        ]];
    }

    protected function hook(array $update, ?string $secret = 'rahasia_123')
    {
        $headers = $secret === null ? [] : ['X-Telegram-Bot-Api-Secret-Token' => $secret];

        return $this->postJson('/api/telegram/webhook', $update, $headers);
    }

    public function test_a_request_without_the_secret_is_rejected_and_nothing_is_sent(): void
    {
        $this->hook($this->startUpdate(), null)->assertForbidden();
        $this->hook($this->startUpdate(), 'salah')->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_the_webhook_refuses_everything_when_no_secret_is_configured(): void
    {
        config(['services.telegram.webhook_secret' => null]);

        $this->hook($this->startUpdate(), '')->assertForbidden();
        $this->hook($this->startUpdate(), null)->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_start_is_answered_with_the_phone_share_button(): void
    {
        $this->hook($this->startUpdate(4242, 'Irfan'))->assertOk()->assertJson(['ok' => true]);

        Http::assertSent(function (Request $request) {
            $d = $request->data();

            return $request->url() === 'https://api.telegram.org/bottest-token/sendMessage'
                && (string) $d['chat_id'] === '4242'
                && $d['reply_markup']['keyboard'][0][0]['request_contact'] === true
                && strpos($d['text'], 'Irfan') !== false;
        });
    }

    public function test_a_shared_matching_phone_number_links_the_account(): void
    {
        $user = User::factory()->create(['name' => 'Sri Wahyuni', 'is_active' => true]);
        $user->forceFill(['phone_number' => '6285771855911'])->save();
        $update = $this->startUpdate(9001);
        unset($update['message']['text']);
        $update['message']['contact'] = ['phone_number' => '6285771855911', 'first_name' => 'Sri', 'user_id' => 9001];

        $this->hook($update)->assertOk();

        $this->assertSame('9001', $user->fresh()->telegram_chat_id);
    }

    public function test_updates_without_a_message_are_acknowledged_quietly(): void
    {
        $this->hook(['update_id' => 5, 'my_chat_member' => ['chat' => ['id' => 1]]])->assertOk();
        $this->hook([])->assertOk();

        Http::assertNothingSent();
    }

    public function test_a_telegram_failure_still_returns_200_so_telegram_does_not_retry(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'boom'], 500)]);

        $this->hook($this->startUpdate())->assertOk();
    }
}
