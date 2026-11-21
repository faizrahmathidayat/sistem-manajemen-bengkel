<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\PurchaseRequestService;
use App\Support\PurchaseRequestStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GrantsPermissions;
use Tests\Concerns\MakesProcurementData;
use Tests\TestCase;

class TelegramApprovalButtonsTest extends TestCase
{
    use RefreshDatabase, GrantsPermissions, MakesProcurementData;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.webhook_secret' => 'rahasia_123',
            'services.approval_notifications.channel' => 'telegram',
            'services.approval_notifications.base_url' => 'http://192.168.1.20:8010',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]], 200)]);
    }

    protected function gudang(): User
    {
        $user = User::factory()->create();
        $this->grantGlobal($user, 'purchase_request.assign_supplier');

        return User::find($user->id);
    }

    protected function withChat(User $user, string $chatId): User
    {
        $user->forceFill(['telegram_chat_id' => $chatId, 'notify_enabled' => true])->save();

        return $user->fresh();
    }

    /**
     * Manager (chat 1111, may select items when $managerSelects) -> Direktur (chat 2222).
     * Two lines: 10 x 100.000 (supplier A) and 2 x 500.000 (supplier B) = 2.000.000.
     * Returns [request, manager, director, lines, step1].
     */
    protected function submitted(bool $managerSelects = false): array
    {
        $this->resetApprovalConfig();
        $manager = $this->withChat($this->makeUser(), '1111');
        $director = $this->withChat($this->makeUser(), '2222');
        $m = $this->makeApprovalLevel('Manager', 1, [$manager]);
        $d = $this->makeApprovalLevel('Direktur', 2, [$director]);
        $m->update(['can_select_items' => $managerSelects]);
        $this->makeApprovalRule(null, [$m, $d], 1);

        $bks = $this->makeBranch('BKS');
        $a = $this->makeSupplier();
        $b = $this->makeSupplier();
        $creator = $this->withChat($this->makeUser(), '3333');
        $this->actingAs($creator);
        $request = $this->makePurchaseRequest($bks, [
            ['sparepart_branch_id' => $this->makeSparepartBranch($bks)->id, 'qty' => 10, 'supplier_id' => $a->id, 'unit_price' => 100000],
            ['sparepart_branch_id' => $this->makeSparepartBranch($bks)->id, 'qty' => 2, 'supplier_id' => $b->id, 'unit_price' => 500000],
        ]);
        app(PurchaseRequestService::class)->submit($request, $this->gudang());
        $request = $request->fresh(['lines', 'approvals']);

        return [$request, $manager, $director, $request->lines, $request->approvals->firstWhere('step_no', 1)];
    }

    protected function cbUpdate(string $data, string $chatId = '1111', int $messageId = 50, string $text = 'pesan lama'): array
    {
        return ['update_id' => random_int(1, 1000000), 'callback_query' => [
            'id' => 'cb' . random_int(1, 1000000),
            'from' => ['id' => (int) $chatId, 'first_name' => 'X'],
            'message' => ['message_id' => $messageId, 'chat' => ['id' => (int) $chatId, 'type' => 'private'], 'text' => $text],
            'data' => $data,
        ]];
    }

    protected function textUpdate(string $text, string $chatId = '1111'): array
    {
        return ['update_id' => random_int(1, 1000000), 'message' => [
            'message_id' => random_int(1, 1000),
            'from' => ['id' => (int) $chatId, 'first_name' => 'X'],
            'chat' => ['id' => (int) $chatId, 'type' => 'private', 'first_name' => 'X'],
            'text' => $text,
        ]];
    }

    protected function hook(array $update)
    {
        return $this->postJson('/api/telegram/webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => 'rahasia_123']);
    }

    protected function calls(string $method): array
    {
        $out = [];
        Http::assertSent(function (Request $request) use (&$out, $method) {
            if (substr($request->url(), -strlen('/' . $method)) === '/' . $method) {
                $out[] = $request->data();
            }

            return true;
        });

        return $out;
    }

    protected function buttons(array $markup): array
    {
        $flat = [];
        foreach ($markup['inline_keyboard'] ?? [] as $row) {
            foreach ($row as $button) {
                $flat[$button['callback_data'] ?? $button['text']] = $button['text'];
            }
        }

        return $flat;
    }

    public function test_the_notification_lists_the_items_and_carries_approve_and_reject_buttons(): void
    {
        [$request, , , , $step] = $this->submitted();

        $log = NotificationLog::firstOrFail();
        $this->assertSame('1111', $log->recipient);
        $this->assertStringContainsString('Sparepart', $log->message);
        $this->assertStringContainsString('10 x Rp 100.000', $log->message);
        $this->assertStringContainsString('Rp 2.000.000', $log->message);

        $buttons = $this->buttons($log->payload['reply_markup']);
        $this->assertArrayHasKey("a:{$request->id}:{$step->step_no}", $buttons);
        $this->assertArrayHasKey("r:{$request->id}:{$step->step_no}", $buttons);
        $this->assertCount(2, $buttons); // no item toggles for a level without the right
    }

    public function test_a_level_that_may_select_items_also_gets_a_toggle_per_item(): void
    {
        [$request, , , $lines, $step] = $this->submitted(true);

        $buttons = $this->buttons(NotificationLog::firstOrFail()->payload['reply_markup']);

        $this->assertArrayHasKey("t:{$request->id}:{$step->step_no}:{$lines[0]->id}", $buttons);
        $this->assertArrayHasKey("t:{$request->id}:{$step->step_no}:{$lines[1]->id}", $buttons);
        $this->assertCount(4, $buttons);
    }

    public function test_tapping_approve_only_asks_for_confirmation(): void
    {
        [$request, , , , $step] = $this->submitted();

        $this->hook($this->cbUpdate("a:{$request->id}:{$step->step_no}"))->assertOk();

        $this->assertSame('pending', $step->fresh()->status);
        $edits = $this->calls('editMessageText');
        $this->assertCount(1, $edits);
        $this->assertStringContainsString('Yakin', $edits[0]['text']);
        $buttons = $this->buttons($edits[0]['reply_markup']);
        $this->assertArrayHasKey("y:{$request->id}:{$step->step_no}", $buttons);
        $this->assertArrayHasKey("n:{$request->id}:{$step->step_no}", $buttons);
    }

    public function test_confirming_approves_the_step_and_notifies_the_next_level(): void
    {
        [$request, , $director, , $step] = $this->submitted();

        $this->hook($this->cbUpdate("y:{$request->id}:{$step->step_no}"))->assertOk();

        $this->assertSame('approved', $step->fresh()->status);
        $this->assertStringContainsString('Telegram', (string) $step->fresh()->notes);
        $last = NotificationLog::orderByDesc('id')->first();
        $this->assertSame($director->id, (int) $last->user_id);
        $this->assertSame('step_ready', $last->event);
        $edits = $this->calls('editMessageText');
        $this->assertStringContainsString('disetujui', strtolower($edits[count($edits) - 1]['text']));
        $this->assertEmpty($edits[count($edits) - 1]['reply_markup']['inline_keyboard'] ?? []);
    }

    public function test_cancelling_the_confirmation_restores_the_original_message(): void
    {
        [$request, , , , $step] = $this->submitted();

        $this->hook($this->cbUpdate("n:{$request->id}:{$step->step_no}"))->assertOk();

        $this->assertSame('pending', $step->fresh()->status);
        $edits = $this->calls('editMessageText');
        $this->assertStringNotContainsString('Yakin', $edits[0]['text']);
        $this->assertArrayHasKey("a:{$request->id}:{$step->step_no}", $this->buttons($edits[0]['reply_markup']));
    }

    public function test_a_chat_that_is_not_linked_to_the_approver_cannot_approve(): void
    {
        [$request, , , , $step] = $this->submitted();
        $stranger = $this->withChat($this->makeUser(), '9999');

        $this->hook($this->cbUpdate("y:{$request->id}:{$step->step_no}", '9999'))->assertOk();
        $this->hook($this->cbUpdate("y:{$request->id}:{$step->step_no}", '5555'))->assertOk(); // no account at all

        $this->assertSame('pending', $step->fresh()->status);
        $answers = $this->calls('answerCallbackQuery');
        $this->assertCount(2, $answers);
        $this->assertTrue($answers[0]['show_alert']);
    }

    public function test_a_stale_button_for_an_already_decided_step_changes_nothing(): void
    {
        [$request, $manager, , , $step] = $this->submitted();
        app(PurchaseRequestService::class)->approve($request, $manager, null, 1);

        $this->hook($this->cbUpdate("y:{$request->id}:{$step->step_no}"))->assertOk();

        $this->assertSame('approved', $step->fresh()->status);
        $this->assertSame(1, $request->approvals()->where('status', 'approved')->count());
        $edits = $this->calls('editMessageText');
        $this->assertStringContainsString('sudah diproses', strtolower($edits[0]['text']));
    }

    public function test_toggling_items_then_confirming_leaves_the_unchecked_item_out(): void
    {
        [$request, $manager, $director, $lines, $step] = $this->submitted(true);

        $this->hook($this->cbUpdate("t:{$request->id}:{$step->step_no}:{$lines[1]->id}"))->assertOk();
        $this->hook($this->cbUpdate("y:{$request->id}:{$step->step_no}"))->assertOk();

        $request = $request->fresh('lines');
        $this->assertTrue((bool) $request->lines[0]->is_selected);
        $this->assertFalse((bool) $request->lines[1]->is_selected);
        $this->assertSame('1000000.00', (string) $request->total_amount);
    }

    public function test_toggling_twice_checks_the_item_again(): void
    {
        [$request, , , $lines, $step] = $this->submitted(true);

        $this->hook($this->cbUpdate("t:{$request->id}:{$step->step_no}:{$lines[1]->id}"));
        $this->hook($this->cbUpdate("t:{$request->id}:{$step->step_no}:{$lines[1]->id}"));
        $this->hook($this->cbUpdate("y:{$request->id}:{$step->step_no}"));

        $this->assertTrue($request->fresh('lines')->lines->every(function ($l) {
            return (bool) $l->is_selected;
        }));
    }

    public function test_a_level_without_the_right_cannot_toggle_items(): void
    {
        [$request, , , $lines, $step] = $this->submitted(false);

        $this->hook($this->cbUpdate("t:{$request->id}:{$step->step_no}:{$lines[1]->id}"))->assertOk();
        $this->hook($this->cbUpdate("y:{$request->id}:{$step->step_no}"))->assertOk();

        $this->assertTrue($request->fresh('lines')->lines->every(function ($l) {
            return (bool) $l->is_selected;
        }));
    }

    public function test_unchecking_everything_is_refused_with_an_alert(): void
    {
        [$request, , , $lines, $step] = $this->submitted(true);

        $this->hook($this->cbUpdate("t:{$request->id}:{$step->step_no}:{$lines[0]->id}"));
        $this->hook($this->cbUpdate("t:{$request->id}:{$step->step_no}:{$lines[1]->id}"));
        $this->hook($this->cbUpdate("y:{$request->id}:{$step->step_no}"))->assertOk();

        $this->assertSame('pending', $step->fresh()->status);
        $answers = $this->calls('answerCallbackQuery');
        $this->assertTrue($answers[count($answers) - 1]['show_alert']);
    }

    public function test_reject_asks_for_a_reason_and_the_next_message_rejects_the_request(): void
    {
        [$request, , , , $step] = $this->submitted();

        $this->hook($this->cbUpdate("r:{$request->id}:{$step->step_no}"))->assertOk();
        $prompt = $this->calls('sendMessage');
        $this->assertTrue($prompt[count($prompt) - 1]['reply_markup']['force_reply']);
        $this->assertSame('pending', $step->fresh()->status);

        $this->hook($this->textUpdate('Harga terlalu tinggi'))->assertOk();

        $this->assertSame(PurchaseRequestStatus::REJECTED, $request->fresh()->status);
        $this->assertSame('Harga terlalu tinggi', $request->fresh()->rejected_reason);
    }

    public function test_batal_cancels_a_pending_rejection(): void
    {
        [$request, , , , $step] = $this->submitted();

        $this->hook($this->cbUpdate("r:{$request->id}:{$step->step_no}"));
        $this->hook($this->textUpdate('/batal'))->assertOk();
        $this->hook($this->textUpdate('Harga terlalu tinggi'))->assertOk();

        $this->assertSame(PurchaseRequestStatus::WAITING_APPROVAL, $request->fresh()->status);
    }

    public function test_the_webhook_and_polling_ask_telegram_for_button_presses(): void
    {
        config(['app.url' => 'https://bengkel.example.com']);
        $this->artisan('telegram:webhook', ['action' => 'set'])->assertExitCode(0);

        Http::assertSent(function (Request $request) {
            return substr($request->url(), -11) === '/setWebhook'
                && $request->data()['allowed_updates'] === ['message', 'callback_query'];
        });
    }
}
