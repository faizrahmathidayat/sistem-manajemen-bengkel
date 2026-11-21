<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramPollCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => 'test-token']);
    }

    protected function update(int $id, int $chatId, ?string $text, string $name = 'Budi', string $type = 'private'): array
    {
        $message = ['message_id' => $id, 'chat' => ['id' => $chatId, 'type' => $type, 'first_name' => $name]];
        if ($text !== null) {
            $message['text'] = $text;
        }

        return ['update_id' => $id, 'message' => $message];
    }

    /** getUpdates returns $batches in order (offset -1 returns the latest one); sendMessage always succeeds. */
    protected function fakeTelegram(array $pending): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(function (Request $request) use (&$pending) {
            if (strpos($request->url(), '/getUpdates') !== false) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
                $data = array_merge($q, (array) $request->data());
                $offset = isset($data['offset']) ? (int) $data['offset'] : 0;
                if ($offset === -1) {
                    return Http::response(['ok' => true, 'result' => $pending ? [end($pending)] : []]);
                }
                $pending = array_values(array_filter($pending, function ($u) use ($offset) {
                    return $u['update_id'] >= $offset;
                }));

                return Http::response(['ok' => true, 'result' => $pending]);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 99]]);
        });
    }

    protected function sent(): array
    {
        $texts = [];
        Http::assertSent(function (Request $request) use (&$texts) {
            if (strpos($request->url(), '/sendMessage') !== false) {
                $texts[] = $request->data();
            }

            return true;
        });

        return $texts;
    }

    public function test_it_replies_to_start_with_the_chat_id(): void
    {
        $this->fakeTelegram([$this->update(10, 1421232494, '/start', 'Irfan')]);

        $this->artisan('telegram:poll', ['--once' => true])->assertExitCode(0);

        $messages = array_values(array_filter($this->sent(), function ($d) {
            return isset($d['text']);
        }));
        $this->assertCount(1, $messages);
        $this->assertSame('1421232494', (string) $messages[0]['chat_id']);
        $this->assertStringContainsString('1421232494', $messages[0]['text']);
        $this->assertStringContainsString('Irfan', $messages[0]['text']);
    }

    public function test_it_tells_an_already_linked_user_which_account_they_are(): void
    {
        $user = User::factory()->create(['name' => 'Sri Wahyuni']);
        $user->forceFill(['telegram_chat_id' => '555000111'])->save();
        $this->fakeTelegram([$this->update(11, 555000111, 'halo', 'Sri')]);

        $this->artisan('telegram:poll', ['--once' => true])->assertExitCode(0);

        $messages = array_values(array_filter($this->sent(), function ($d) {
            return isset($d['text']);
        }));
        $this->assertStringContainsString('Sri Wahyuni', $messages[0]['text']);
    }

    public function test_it_acknowledges_updates_and_ignores_non_text_ones(): void
    {
        $this->fakeTelegram([
            $this->update(20, 111, null),
            $this->update(21, 222, '/start', 'Ani'),
        ]);

        $this->artisan('telegram:poll', ['--once' => true])->assertExitCode(0);

        Http::assertSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $data = array_merge($q, (array) $request->data());

            return strpos($request->url(), '/getUpdates') !== false && isset($data['offset']) && (int) $data['offset'] === 22;
        });
        $messages = array_values(array_filter($this->sent(), function ($d) {
            return isset($d['text']);
        }));
        $this->assertCount(1, $messages);
        $this->assertSame('222', (string) $messages[0]['chat_id']);
    }

    public function test_skip_pending_discards_the_backlog_without_replying(): void
    {
        $this->fakeTelegram([$this->update(30, 333, '/start', 'Lama')]);

        $this->artisan('telegram:poll', ['--once' => true, '--skip-pending' => true])->assertExitCode(0);

        $messages = array_values(array_filter($this->sent(), function ($d) {
            return isset($d['text']);
        }));
        $this->assertCount(0, $messages);
    }

    public function test_it_refuses_to_run_without_a_token(): void
    {
        config(['services.telegram.bot_token' => null]);
        Http::fake();

        $this->artisan('telegram:poll', ['--once' => true])->assertExitCode(1);

        Http::assertNothingSent();
    }
}
