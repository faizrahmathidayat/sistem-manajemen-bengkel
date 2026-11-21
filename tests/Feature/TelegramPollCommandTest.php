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
        $message = ['message_id' => $id, 'from' => ['id' => $chatId, 'first_name' => $name], 'chat' => ['id' => $chatId, 'type' => $type, 'first_name' => $name]];
        if ($text !== null) {
            $message['text'] = $text;
        }

        return ['update_id' => $id, 'message' => $message];
    }

    /** A shared contact. $contactUserId defaults to the sender (their own number). */
    protected function contactUpdate(int $id, int $chatId, string $phone, ?int $contactUserId = null): array
    {
        $update = $this->update($id, $chatId, null);
        $update['message']['contact'] = ['phone_number' => $phone, 'first_name' => 'Budi', 'user_id' => $contactUserId ?? $chatId];

        return $update;
    }

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

    /** @return array<int, array> payloads of the sendMessage calls */
    protected function replies(): array
    {
        $out = [];
        Http::assertSent(function (Request $request) use (&$out) {
            if (strpos($request->url(), '/sendMessage') !== false) {
                $out[] = $request->data();
            }

            return true;
        });

        return $out;
    }

    protected function run_(): void
    {
        $this->artisan('telegram:poll', ['--once' => true])->assertExitCode(0);
    }

    public function test_start_from_an_unlinked_person_asks_for_their_phone_number_with_the_share_button(): void
    {
        $this->fakeTelegram([$this->update(10, 1421232494, '/start', 'Irfan')]);

        $this->run_();

        $replies = $this->replies();
        $this->assertCount(1, $replies);
        $this->assertSame('1421232494', (string) $replies[0]['chat_id']);
        $this->assertTrue($replies[0]['reply_markup']['keyboard'][0][0]['request_contact']);
        $this->assertStringContainsString('Irfan', $replies[0]['text']);
    }

    public function test_sharing_the_matching_phone_number_links_the_account_automatically(): void
    {
        $user = User::factory()->create(['name' => 'Sri Wahyuni', 'is_active' => true]);
        $user->forceFill(['phone_number' => '6285771855911'])->save();
        $this->fakeTelegram([$this->contactUpdate(11, 777000, '+62 857-7185-5911')]);

        $this->run_();

        $this->assertSame('777000', $user->fresh()->telegram_chat_id);
        $replies = $this->replies();
        $this->assertStringContainsString('Sri Wahyuni', $replies[0]['text']);
        $this->assertTrue($replies[0]['reply_markup']['remove_keyboard']);
    }

    public function test_a_contact_that_is_not_the_senders_own_is_refused(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['phone_number' => '6285771855911'])->save();
        $this->fakeTelegram([$this->contactUpdate(12, 777000, '6285771855911', 999)]);

        $this->run_();

        $this->assertNull($user->fresh()->telegram_chat_id);
        $this->assertStringContainsString('sendiri', $this->replies()[0]['text']);
    }

    public function test_an_unknown_number_is_not_linked_and_the_chat_id_is_shown_for_the_admin(): void
    {
        $this->fakeTelegram([$this->contactUpdate(13, 888000, '6281200000000')]);

        $this->run_();

        $this->assertSame(0, User::whereNotNull('telegram_chat_id')->count());
        $this->assertStringContainsString('888000', $this->replies()[0]['text']);
    }

    public function test_a_number_shared_by_two_users_is_not_linked(): void
    {
        foreach ([1, 2] as $i) {
            User::factory()->create(['is_active' => true])->forceFill(['phone_number' => '6281234500000'])->save();
        }
        $this->fakeTelegram([$this->contactUpdate(14, 888001, '6281234500000')]);

        $this->run_();

        $this->assertSame(0, User::whereNotNull('telegram_chat_id')->count());
    }

    public function test_an_inactive_user_is_not_linked(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $user->forceFill(['phone_number' => '6281234500001'])->save();
        $this->fakeTelegram([$this->contactUpdate(15, 888002, '6281234500001')]);

        $this->run_();

        $this->assertNull($user->fresh()->telegram_chat_id);
    }

    public function test_linking_moves_the_chat_away_from_any_other_user(): void
    {
        $old = User::factory()->create(['is_active' => true]);
        $old->forceFill(['telegram_chat_id' => '777000'])->save();
        $new = User::factory()->create(['is_active' => true]);
        $new->forceFill(['phone_number' => '6281234500002'])->save();
        $this->fakeTelegram([$this->contactUpdate(16, 777000, '6281234500002')]);

        $this->run_();

        $this->assertNull($old->fresh()->telegram_chat_id);
        $this->assertSame('777000', $new->fresh()->telegram_chat_id);
    }

    public function test_an_already_linked_person_is_told_which_account_they_are(): void
    {
        $user = User::factory()->create(['name' => 'Sri Wahyuni']);
        $user->forceFill(['telegram_chat_id' => '555000111'])->save();
        $this->fakeTelegram([$this->update(17, 555000111, '/start', 'Sri')]);

        $this->run_();

        $replies = $this->replies();
        $this->assertStringContainsString('Sri Wahyuni', $replies[0]['text']);
        $this->assertArrayNotHasKey('reply_markup', $replies[0]);
    }

    public function test_a_group_message_is_answered_with_the_group_chat_id(): void
    {
        $this->fakeTelegram([$this->update(18, -1001234567890, '/start', 'Grup Approver', 'supergroup')]);

        $this->run_();

        $replies = $this->replies();
        $this->assertStringContainsString('-1001234567890', $replies[0]['text']);
        $this->assertArrayNotHasKey('reply_markup', $replies[0]);
    }

    public function test_it_acknowledges_updates_and_ignores_non_text_ones(): void
    {
        $this->fakeTelegram([
            $this->update(20, 111, null),
            $this->update(21, 222, '/start', 'Ani'),
        ]);

        $this->run_();

        Http::assertSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $data = array_merge($q, (array) $request->data());

            return strpos($request->url(), '/getUpdates') !== false && isset($data['offset']) && (int) $data['offset'] === 22;
        });
        $replies = $this->replies();
        $this->assertCount(1, $replies);
        $this->assertSame('222', (string) $replies[0]['chat_id']);
    }

    public function test_skip_pending_discards_the_backlog_without_replying(): void
    {
        $this->fakeTelegram([$this->update(30, 333, '/start', 'Lama')]);

        $this->artisan('telegram:poll', ['--once' => true, '--skip-pending' => true])->assertExitCode(0);

        $this->assertCount(0, $this->replies());
    }

    public function test_it_refuses_to_run_without_a_token(): void
    {
        config(['services.telegram.bot_token' => null]);
        Http::fake();

        $this->artisan('telegram:poll', ['--once' => true])->assertExitCode(1);

        Http::assertNothingSent();
    }
}
