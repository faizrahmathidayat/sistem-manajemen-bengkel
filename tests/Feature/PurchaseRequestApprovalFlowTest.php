<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\PurchaseRequestService;
use App\Support\PurchaseRequestApprovalStatus;
use App\Support\PurchaseRequestStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsPermissions;
use Tests\Concerns\MakesProcurementData;
use Tests\TestCase;

class PurchaseRequestApprovalFlowTest extends TestCase
{
    use RefreshDatabase, GrantsPermissions, MakesProcurementData;

    protected function gudang(): User
    {
        $user = User::factory()->create();
        $this->grantGlobal($user, 'purchase_request.assign_supplier');

        return User::find($user->id);
    }

    protected function submitted(int $total, ?User $requester = null): PurchaseRequest
    {
        $bks = $this->makeBranch('BKS');
        if ($requester) {
            $this->actingAs($requester);
        }
        $request = $this->makeAssignedRequest($bks, $total);
        app(PurchaseRequestService::class)->submit($request, $this->gudang());

        return $request->fresh(['approvals.users']);
    }

    public function test_submit_is_blocked_until_every_line_has_supplier_and_price(): void
    {
        $this->makeStandardApprovalConfig();
        $bks = $this->makeBranch('BKS');
        $request = $this->makePurchaseRequest($bks, [
            ['sparepart_branch_id' => $this->makeSparepartBranch($bks)->id, 'qty' => 1, 'supplier_id' => $this->makeSupplier()->id, 'unit_price' => 1000],
            ['sparepart_branch_id' => $this->makeSparepartBranch($bks)->id, 'qty' => 1],
        ]);

        $this->actingAs($this->gudang())->patch("/purchase-requests/{$request->id}/submit")
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame(PurchaseRequestStatus::WAITING_SUPPLIER, $request->fresh()->status);
        $this->assertSame(0, $request->approvals()->count());
    }

    public function test_only_assign_supplier_holders_can_submit(): void
    {
        $this->makeStandardApprovalConfig();
        $bks = $this->makeBranch('BKS');
        $request = $this->makeAssignedRequest($bks, 5000000);

        $this->actingAs(User::factory()->create())->patch("/purchase-requests/{$request->id}/submit")->assertForbidden();
    }

    public function test_submit_builds_chain_and_snapshots_approvers_for_38_million(): void
    {
        [$manager, $director] = $this->makeStandardApprovalConfig();
        $bks = $this->makeBranch('BKS');
        $request = $this->makeAssignedRequest($bks, 38320000);
        $gudang = $this->gudang();

        $this->actingAs($gudang)->patch("/purchase-requests/{$request->id}/submit")
            ->assertRedirect(route('purchase-requests.show', $request));

        $request->refresh();
        $this->assertSame(PurchaseRequestStatus::WAITING_APPROVAL, $request->status);
        $this->assertSame('38320000.00', (string) $request->total_amount);
        $this->assertSame($gudang->id, (int) $request->submitted_by);
        $this->assertNotNull($request->submitted_at);
        $steps = $request->approvals()->with('users')->get();
        $this->assertSame(['Manager', 'Direktur'], $steps->pluck('level_name')->all());
        $this->assertSame([1, 2], $steps->pluck('step_no')->all());
        $this->assertSame(
            [PurchaseRequestApprovalStatus::PENDING, PurchaseRequestApprovalStatus::PENDING],
            $steps->pluck('status')->all()
        );
        $this->assertSame($manager->users->pluck('id')->all(), $steps[0]->users->pluck('id')->all());
        $this->assertSame($director->users->pluck('id')->all(), $steps[1]->users->pluck('id')->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'purchase_request.submitted', 'auditable_id' => $request->id]);
    }

    public function test_submit_is_rejected_with_message_when_a_level_has_no_active_user(): void
    {
        [, $director] = $this->makeStandardApprovalConfig();
        $director->users()->sync([$this->makeUser(false)->id]);
        $bks = $this->makeBranch('BKS');
        $request = $this->makeAssignedRequest($bks, 38320000);

        $response = $this->actingAs($this->gudang())->patch("/purchase-requests/{$request->id}/submit");

        $response->assertRedirect();
        $this->assertStringContainsString('Direktur', session('error'));
        $this->assertSame(PurchaseRequestStatus::WAITING_SUPPLIER, $request->fresh()->status);
        $this->assertSame(0, $request->approvals()->count());
    }

    public function test_only_the_current_step_approver_can_approve_and_order_is_enforced(): void
    {
        [$manager, $director] = $this->makeStandardApprovalConfig();
        $request = $this->submitted(38320000);
        $managerUser = $manager->users->first();
        $directorUser = $director->users->first();

        $this->actingAs($directorUser)->patch("/purchase-requests/{$request->id}/approve")->assertForbidden();
        $this->assertSame(PurchaseRequestStatus::WAITING_APPROVAL, $request->fresh()->status);

        $this->actingAs($managerUser)->patch("/purchase-requests/{$request->id}/approve", ['note' => 'Setuju, harga sesuai kontrak.'])
            ->assertRedirect(route('purchase-requests.show', $request));
        $first = $request->approvals()->orderBy('step_no')->first();
        $this->assertSame(PurchaseRequestApprovalStatus::APPROVED, $first->status);
        $this->assertSame($managerUser->id, (int) $first->acted_by);
        $this->assertSame('Setuju, harga sesuai kontrak.', $first->notes);
        $this->assertSame(PurchaseRequestStatus::WAITING_APPROVAL, $request->fresh()->status);

        $this->actingAs($managerUser)->patch("/purchase-requests/{$request->id}/approve")->assertForbidden();
        $this->actingAs($directorUser)->patch("/purchase-requests/{$request->id}/approve")->assertRedirect();
        $this->assertSame(PurchaseRequestStatus::APPROVED, $request->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'purchase_request.approved', 'auditable_id' => $request->id]);
    }

    public function test_final_approval_generates_purchase_orders_and_earlier_steps_do_not(): void
    {
        [$manager, $director] = $this->makeStandardApprovalConfig();
        $request = $this->submitted(38320000);

        $this->actingAs($manager->users->first())->patch("/purchase-requests/{$request->id}/approve");
        $this->assertSame(0, $request->purchaseOrders()->count());

        $this->actingAs($director->users->first())->patch("/purchase-requests/{$request->id}/approve");
        $this->assertSame(1, $request->purchaseOrders()->count()); // one supplier in this fixture
        $this->assertSame('draft', $request->purchaseOrders()->first()->status);
    }

    public function test_reject_requires_a_reason_voids_remaining_steps_and_is_final(): void
    {
        [$manager, $director] = $this->makeStandardApprovalConfig();
        $request = $this->submitted(38320000);
        $managerUser = $manager->users->first();

        $this->actingAs($managerUser)->patch("/purchase-requests/{$request->id}/reject", ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->assertSame(PurchaseRequestStatus::WAITING_APPROVAL, $request->fresh()->status);

        $this->actingAs($managerUser)->patch("/purchase-requests/{$request->id}/reject", ['reason' => 'Harga di atas pasar.'])
            ->assertRedirect(route('purchase-requests.show', $request));

        $request->refresh();
        $this->assertSame(PurchaseRequestStatus::REJECTED, $request->status);
        $this->assertSame('Harga di atas pasar.', $request->rejected_reason);
        $steps = $request->approvals()->orderBy('step_no')->get();
        $this->assertSame(PurchaseRequestApprovalStatus::REJECTED, $steps[0]->status);
        $this->assertSame('Harga di atas pasar.', $steps[0]->notes);
        $this->assertSame(PurchaseRequestApprovalStatus::VOID, $steps[1]->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'purchase_request.rejected', 'auditable_id' => $request->id]);

        // Final: nobody can approve, reject again, or generate orders.
        $this->actingAs($director->users->first())->patch("/purchase-requests/{$request->id}/approve")->assertForbidden();
        $this->actingAs($managerUser)->patch("/purchase-requests/{$request->id}/reject", ['reason' => 'lagi'])->assertForbidden();
        $this->assertSame(0, $request->purchaseOrders()->count());
    }

    public function test_requester_cannot_approve_their_own_request(): void
    {
        [$manager] = $this->makeStandardApprovalConfig();
        $requester = $manager->users->first(); // the manager also happens to be the requester
        $request = $this->submitted(5000000, $requester);

        $this->assertSame($requester->id, (int) $request->created_by);
        $this->actingAs($requester)->patch("/purchase-requests/{$request->id}/approve")->assertForbidden();
        $this->assertSame(PurchaseRequestStatus::WAITING_APPROVAL, $request->fresh()->status);
    }

    public function test_the_person_who_submitted_cannot_approve(): void
    {
        [$manager] = $this->makeStandardApprovalConfig();
        $submitter = $manager->users->first();
        $this->grantGlobal($submitter, 'purchase_request.assign_supplier');
        $bks = $this->makeBranch('BKS');
        $request = $this->makeAssignedRequest($bks, 5000000);
        app(PurchaseRequestService::class)->submit($request, User::find($submitter->id));

        $this->actingAs($submitter)->patch("/purchase-requests/{$request->id}/approve")->assertForbidden();
    }

    public function test_same_user_cannot_process_two_steps_of_one_request(): void
    {
        [$manager, $director] = $this->makeStandardApprovalConfig();
        $both = $this->makeUser();
        $manager->users()->sync([$both->id]);
        $director->users()->sync([$both->id]);
        $request = $this->submitted(38320000);

        $this->actingAs($both)->patch("/purchase-requests/{$request->id}/approve")->assertRedirect();
        $this->actingAs($both)->patch("/purchase-requests/{$request->id}/approve")->assertForbidden();

        $this->assertSame(PurchaseRequestStatus::WAITING_APPROVAL, $request->fresh()->status);
    }

    public function test_changing_settings_after_submit_does_not_affect_the_snapshot(): void
    {
        [$manager] = $this->makeStandardApprovalConfig();
        $original = $manager->users->first();
        $request = $this->submitted(5000000);

        $replacement = $this->makeUser();
        $manager->users()->sync([$replacement->id]);
        \App\Models\ApprovalRule::query()->delete();
        $manager->update(['name' => 'Kepala Baru', 'is_active' => false]);

        $this->actingAs($replacement)->patch("/purchase-requests/{$request->id}/approve")->assertForbidden();
        $this->actingAs($original)->patch("/purchase-requests/{$request->id}/approve")->assertRedirect();

        $this->assertSame(PurchaseRequestStatus::APPROVED, $request->fresh()->status);
        $this->assertSame('Manager', $request->approvals()->first()->level_name);
    }

    public function test_double_approve_is_effective_once_and_creates_no_duplicate_orders(): void
    {
        [$manager] = $this->makeStandardApprovalConfig();
        $second = $this->makeUser();
        $manager->users()->attach($second->id);
        $request = $this->submitted(5000000);
        $service = app(PurchaseRequestService::class);

        $service->approve($request, $manager->users()->first());
        try {
            $service->approve($request->fresh(), $second);
            $this->fail('Second approval must be refused');
        } catch (\Exception $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertSame(PurchaseRequestStatus::APPROVED, $request->fresh()->status);
        $this->assertSame(1, $request->purchaseOrders()->count());
        $this->assertSame(1, $request->approvals()->where('status', PurchaseRequestApprovalStatus::APPROVED)->count());
    }

    public function test_reload_approvers_needs_manage_permission_and_copies_current_config(): void
    {
        [$manager] = $this->makeStandardApprovalConfig();
        $request = $this->submitted(5000000);
        $manager->users->first()->update(['is_active' => false]);
        $fresh = $this->makeUser();
        $manager->users()->sync([$fresh->id]);

        $this->actingAs($this->gudang())->patch("/purchase-requests/{$request->id}/reload-approvers")->assertForbidden();

        $admin = User::factory()->create();
        $this->grantGlobal($admin, 'approval_setting.manage');
        $this->actingAs(User::find($admin->id))->patch("/purchase-requests/{$request->id}/reload-approvers")
            ->assertRedirect(route('purchase-requests.show', $request));

        $this->assertSame([$fresh->id], $request->approvals()->first()->users()->pluck('users.id')->all());
        $this->actingAs($fresh)->patch("/purchase-requests/{$request->id}/approve")->assertRedirect();
        $this->assertSame(PurchaseRequestStatus::APPROVED, $request->fresh()->status);
    }

    public function test_show_page_renders_in_every_status(): void
    {
        [$manager] = $this->makeStandardApprovalConfig();
        $viewer = $this->gudang();
        $waitingSupplier = $this->makeAssignedRequest($this->makeBranch('BKS'), 4000000);
        $waitingApproval = $this->submitted(5000000);
        $approvedRequest = $this->submitted(6000000);
        app(PurchaseRequestService::class)->approve($approvedRequest, $manager->users->first());
        $rejectedRequest = $this->submitted(7000000);
        app(PurchaseRequestService::class)->reject($rejectedRequest, $manager->users->first(), 'Terlalu mahal');

        foreach ([$waitingSupplier, $waitingApproval, $approvedRequest, $rejectedRequest] as $request) {
            $this->actingAs($viewer)->get("/purchase-requests/{$request->id}")->assertOk();
        }
        $this->actingAs($viewer)->get("/purchase-requests/{$waitingApproval->id}")->assertSee('Progres Approval')->assertSee('Manager');
        $this->actingAs($viewer)->get("/purchase-requests/{$rejectedRequest->id}")->assertSee('Terlalu mahal')->assertSee('Ditolak');
        $this->actingAs($viewer)->get("/purchase-requests/{$approvedRequest->id}")->assertSee('Pembelian yang Terbentuk');
        $this->actingAs($manager->users->first())->get("/purchase-requests/{$waitingApproval->id}")->assertSee('Setuju')->assertSee('Tolak');
    }

    public function test_audit_log_rows_exist_for_each_decision(): void
    {
        [$manager] = $this->makeStandardApprovalConfig();
        $request = $this->submitted(5000000);
        app(PurchaseRequestService::class)->approve($request, $manager->users->first());

        $events = AuditLog::where('auditable_id', $request->id)->pluck('event')->all();
        $this->assertContains('purchase_request.submitted', $events);
        $this->assertContains('purchase_request.approved', $events);
    }
}
