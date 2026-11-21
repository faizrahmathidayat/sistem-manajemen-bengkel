<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\User;
use App\Services\PurchaseRequestService;
use App\Support\PurchaseRequestStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GrantsPermissions;
use Tests\Concerns\MakesProcurementData;
use Tests\TestCase;

class ApprovalNotificationTest extends TestCase
{
    use RefreshDatabase, GrantsPermissions, MakesProcurementData;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.approval_notifications.channel' => 'telegram',
            'services.approval_notifications.base_url' => 'http://192.168.1.20:8010/',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 6]], 200)]);
    }

    protected function gudang(): User
    {
        $user = User::factory()->create();
        $this->grantGlobal($user, 'purchase_request.assign_supplier');

        return User::find($user->id);
    }

    protected function withChat(User $user, string $chatId = '1111', bool $enabled = true): User
    {
        $user->forceFill(['telegram_chat_id' => $chatId, 'notify_enabled' => $enabled])->save();

        return $user->fresh();
    }

    /** Manager -> Direktur, both reachable. Returns [request, managerUser, directorUser, creator]. */
    protected function submitted(bool $urgent = false): array
    {
        $this->resetApprovalConfig();
        $managerUser = $this->withChat($this->makeUser(), '1111');
        $directorUser = $this->withChat($this->makeUser(), '2222');
        $manager = $this->makeApprovalLevel('Manager', 1, [$managerUser]);
        $director = $this->makeApprovalLevel('Direktur', 2, [$directorUser]);
        $this->makeApprovalRule(null, [$manager, $director], 1);

        $bks = $this->makeBranch('BKS');
        $creator = $this->withChat($this->makeUser(), '3333');
        $this->actingAs($creator);
        $request = $this->makeAssignedRequest($bks, 4000000, ['is_urgent' => $urgent]);

        app(PurchaseRequestService::class)->submit($request, $this->gudang());

        return [$request->fresh(), $managerUser, $directorUser, $creator];
    }

    public function test_submitting_notifies_only_the_first_step_approvers_via_telegram(): void
    {
        [$request, $manager] = $this->submitted();

        $this->assertSame(1, NotificationLog::count());
        $log = NotificationLog::firstOrFail();
        $this->assertSame($manager->id, (int) $log->user_id);
        $this->assertSame('1111', $log->recipient);
        $this->assertSame('step_ready', $log->event);
        $this->assertSame('sent', $log->status);
        $this->assertSame('telegram', $log->channel);
        $this->assertStringContainsString($request->number, $log->message);
        $this->assertStringContainsString("http://192.168.1.20:8010/purchase-requests/{$request->id}", $log->message);

        Http::assertSent(function ($httpRequest) use ($request) {
            $body = $httpRequest->data();

            return $httpRequest->url() === 'https://api.telegram.org/bottest-token/sendMessage'
                && (string) $body['chat_id'] === '1111'
                && strpos($body['text'], $request->number) !== false;
        });
    }

    public function test_urgent_requests_are_prefixed(): void
    {
        $this->submitted(true);

        $this->assertStringStartsWith('[URGENT] ', NotificationLog::firstOrFail()->message);
    }

    public function test_next_level_is_notified_after_approval_and_requester_after_the_last_one(): void
    {
        [$request, $manager, $director, $creator] = $this->submitted();
        $service = app(PurchaseRequestService::class);

        $service->approve($request, $manager, null, 1);
        $second = NotificationLog::orderByDesc('id')->first();
        $this->assertSame($director->id, (int) $second->user_id);
        $this->assertSame('step_ready', $second->event);

        $service->approve($request->fresh(), $director, null, 2);
        $last = NotificationLog::orderByDesc('id')->first();
        $this->assertSame($creator->id, (int) $last->user_id);
        $this->assertSame('approved', $last->event);
        $this->assertSame(3, NotificationLog::count());
    }

    public function test_requester_is_told_about_rejection_with_the_reason(): void
    {
        [$request, $manager, , $creator] = $this->submitted();

        app(PurchaseRequestService::class)->reject($request, $manager, 'Harga terlalu tinggi', 1);

        $log = NotificationLog::orderByDesc('id')->first();
        $this->assertSame($creator->id, (int) $log->user_id);
        $this->assertSame('rejected', $log->event);
        $this->assertStringContainsString('Harga terlalu tinggi', $log->message);
    }

    public function test_requester_is_told_when_someone_else_cancels(): void
    {
        [$request, , , $creator] = $this->submitted();

        app(PurchaseRequestService::class)->cancel($request, $this->gudang(), 'Tidak jadi');

        $log = NotificationLog::orderByDesc('id')->first();
        $this->assertSame('cancelled', $log->event);
        $this->assertSame($creator->id, (int) $log->user_id);
    }

    public function test_users_without_a_chat_id_or_with_notifications_off_are_skipped(): void
    {
        $this->resetApprovalConfig();
        $none = $this->makeUser();
        $off = $this->withChat($this->makeUser(), '4444', false);
        $manager = $this->makeApprovalLevel('Manager', 1, [$none, $off]);
        $this->makeApprovalRule(null, [$manager], 1);
        $request = $this->makeAssignedRequest($this->makeBranch('BKS'), 1000000);

        app(PurchaseRequestService::class)->submit($request, $this->gudang());

        $this->assertSame(0, NotificationLog::count());
        Http::assertNothingSent();
        $this->assertSame(PurchaseRequestStatus::WAITING_APPROVAL, $request->fresh()->status);
    }

    public function test_a_failing_provider_is_logged_without_leaking_the_token_and_never_blocks_approval(): void
    {
        $this->resetApprovalConfig();
        $manager = $this->makeApprovalLevel('Manager', 1, [$this->withChat($this->makeUser())]);
        $this->makeApprovalRule(null, [$manager], 1);
        $request = $this->makeAssignedRequest($this->makeBranch('BKS'), 1000000);

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);
        app(PurchaseRequestService::class)->submit($request, $this->gudang());

        $this->assertSame(PurchaseRequestStatus::WAITING_APPROVAL, $request->fresh()->status);
        $log = NotificationLog::firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('bot was blocked by the user', $log->error);
        $this->assertStringNotContainsString('test-token', (string) $log->error . (string) $log->response);
    }

    public function test_a_connection_error_message_never_contains_the_token(): void
    {
        $this->resetApprovalConfig();
        $manager = $this->makeApprovalLevel('Manager', 1, [$this->withChat($this->makeUser())]);
        $this->makeApprovalRule(null, [$manager], 1);
        $request = $this->makeAssignedRequest($this->makeBranch('BKS'), 1000000);

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 28 for https://api.telegram.org/bottest-token/sendMessage');
        });
        app(PurchaseRequestService::class)->submit($request, $this->gudang());

        $log = NotificationLog::firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertStringNotContainsString('test-token', (string) $log->error);
    }

    public function test_without_a_token_the_message_is_only_logged(): void
    {
        config(['services.approval_notifications.channel' => 'auto', 'services.telegram.bot_token' => null]);
        $this->resetApprovalConfig();
        $manager = $this->makeApprovalLevel('Manager', 1, [$this->withChat($this->makeUser())]);
        $this->makeApprovalRule(null, [$manager], 1);
        $request = $this->makeAssignedRequest($this->makeBranch('BKS'), 1000000);

        app(PurchaseRequestService::class)->submit($request, $this->gudang());

        Http::assertNothingSent();
        $this->assertSame('logged', NotificationLog::firstOrFail()->status);
    }

    public function test_user_form_saves_chat_id_and_toggle_and_rejects_garbage(): void
    {
        $admin = User::factory()->create();
        foreach (['user.view', 'user.create', 'user.edit'] as $code) {
            $this->grantGlobal($admin, $code);
        }
        $admin = User::find($admin->id);
        $target = $this->makeUser();
        $base = ['name' => $target->name, 'username' => $target->username, 'is_active' => 1];

        $this->actingAs($admin)->put("/users/{$target->id}", $base + ['telegram_chat_id' => ' 1421232494 ', 'notify_enabled' => 1])->assertRedirect();
        $this->assertSame('1421232494', $target->fresh()->telegram_chat_id);
        $this->assertTrue((bool) $target->fresh()->notify_enabled);

        $this->actingAs($admin)->put("/users/{$target->id}", $base + ['telegram_chat_id' => '-1001234567890', 'notify_enabled' => 0])->assertRedirect();
        $this->assertSame('-1001234567890', $target->fresh()->telegram_chat_id);
        $this->assertFalse((bool) $target->fresh()->notify_enabled);

        $this->actingAs($admin)->put("/users/{$target->id}", $base + ['telegram_chat_id' => '@username'])->assertSessionHasErrors('telegram_chat_id');
        $this->assertSame('-1001234567890', $target->fresh()->telegram_chat_id);

        $this->actingAs($admin)->put("/users/{$target->id}", $base + ['telegram_chat_id' => ''])->assertRedirect();
        $this->assertNull($target->fresh()->telegram_chat_id);
    }
}
