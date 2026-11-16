<?php

namespace Tests\Feature;

use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\PurchaseRequestService;
use App\Support\PurchaseRequestStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsPermissions;
use Tests\Concerns\MakesProcurementData;
use Tests\TestCase;

class PurchaseRequestItemSelectionTest extends TestCase
{
    use RefreshDatabase, GrantsPermissions, MakesProcurementData;

    protected function gudang(): User
    {
        $user = User::factory()->create();
        $this->grantGlobal($user, 'purchase_request.assign_supplier');

        return User::find($user->id);
    }

    /**
     * Manager (may select items) -> Direktur (may not). Three lines for 2 suppliers:
     * 10 x 100.000, 5 x 200.000 (supplier A) and 2 x 500.000 (supplier B) = 3.000.000.
     * Returns [request, manager, director, lines].
     */
    protected function submittedRequest(bool $managerMaySelect = true, bool $directorMaySelect = false): array
    {
        $this->resetApprovalConfig();
        $manager = $this->makeApprovalLevel('Manager', 1, [$this->makeUser()]);
        $director = $this->makeApprovalLevel('Direktur', 2, [$this->makeUser()]);
        $manager->update(['can_select_items' => $managerMaySelect]);
        $director->update(['can_select_items' => $directorMaySelect]);
        $this->makeApprovalRule(null, [$manager, $director], 1);

        $bks = $this->makeBranch('BKS');
        $a = $this->makeSupplier();
        $b = $this->makeSupplier();
        $request = $this->makePurchaseRequest($bks, [
            ['sparepart_branch_id' => $this->makeSparepartBranch($bks)->id, 'qty' => 10, 'supplier_id' => $a->id, 'unit_price' => 100000],
            ['sparepart_branch_id' => $this->makeSparepartBranch($bks)->id, 'qty' => 5, 'supplier_id' => $a->id, 'unit_price' => 200000],
            ['sparepart_branch_id' => $this->makeSparepartBranch($bks)->id, 'qty' => 2, 'supplier_id' => $b->id, 'unit_price' => 500000],
        ]);
        app(PurchaseRequestService::class)->submit($request, $this->gudang());

        return [$request->fresh(['lines', 'approvals']), User::find($manager->users()->first()->id), User::find($director->users()->first()->id), $request->lines];
    }

    protected function svc(): PurchaseRequestService
    {
        return app(PurchaseRequestService::class);
    }

    public function test_submit_copies_the_select_flag_and_original_total_to_the_snapshot(): void
    {
        [$request] = $this->submittedRequest();

        $this->assertTrue((bool) $request->approvals->firstWhere('step_no', 1)->can_select_items);
        $this->assertFalse((bool) $request->approvals->firstWhere('step_no', 2)->can_select_items);
        $this->assertSame('3000000.00', (string) $request->submitted_total);
    }

    public function test_turning_the_level_setting_on_applies_to_requests_already_waiting_at_that_step(): void
    {
        [$request, $manager, , $lines] = $this->submittedRequest(false, false);
        $this->actingAs($manager)->get("/purchase-requests/{$request->id}")
            ->assertOk()->assertDontSee('name="selected_lines[]"', false);

        \App\Models\ApprovalLevel::where('name', 'Manager')->update(['can_select_items' => true]);

        $this->actingAs($manager)->get("/purchase-requests/{$request->id}")
            ->assertOk()->assertSee('name="selected_lines[]"', false);
        $this->svc()->approve($request, $manager, null, 1, [$lines[0]->id]);
        $this->assertFalse((bool) $request->fresh('lines')->lines[1]->is_selected);
    }

    public function test_approver_may_uncheck_lines_and_total_and_purchase_orders_follow_the_checked_ones(): void
    {
        [$request, $manager, $director, $lines] = $this->submittedRequest();

        // Manager keeps only the first line (uncheck lines 2 and 3), Direktur then approves.
        $this->svc()->approve($request, $manager, null, 1, [$lines[0]->id]);
        $request = $request->fresh(['lines']);
        $this->assertTrue((bool) $request->lines[0]->is_selected);
        $this->assertFalse((bool) $request->lines[1]->is_selected);
        $this->assertSame(1, (int) $request->lines[1]->deselected_step_no);
        $this->assertSame('1000000.00', (string) $request->total_amount);

        $this->svc()->approve($request, $director, null, 2);

        $request = $request->fresh(['purchaseOrders.lines']);
        $this->assertSame(PurchaseRequestStatus::APPROVED, $request->status);
        $this->assertCount(1, $request->purchaseOrders);
        $this->assertSame(1, $request->purchaseOrders->first()->lines->count());
        $this->assertSame('1000000.00', (string) $request->purchaseOrders->first()->total_amount);
        $this->assertSame('3000000.00', (string) $request->submitted_total);
    }

    public function test_level_without_the_flag_cannot_change_the_selection(): void
    {
        [$request, $manager, $director, $lines] = $this->submittedRequest(true, false);
        $this->svc()->approve($request, $manager, null, 1);

        $this->expectException(\DomainException::class);
        try {
            $this->svc()->approve($request->fresh(), $director, null, 2, [$lines[0]->id]);
        } finally {
            $this->assertTrue($request->fresh('lines')->lines->every(fn ($l) => (bool) $l->is_selected));
        }
    }

    public function test_later_level_cannot_recheck_a_line_unchecked_earlier(): void
    {
        [$request, $manager, $director, $lines] = $this->submittedRequest(true, true);
        $this->svc()->approve($request, $manager, null, 1, [$lines[0]->id, $lines[1]->id]);

        $this->expectException(\DomainException::class);
        $this->svc()->approve($request->fresh(), $director, null, 2, [$lines[0]->id, $lines[1]->id, $lines[2]->id]);
    }

    public function test_later_level_may_uncheck_more_lines(): void
    {
        [$request, $manager, $director, $lines] = $this->submittedRequest(true, true);
        $this->svc()->approve($request, $manager, null, 1, [$lines[0]->id, $lines[1]->id]);
        $this->svc()->approve($request->fresh(), $director, null, 2, [$lines[0]->id]);

        $request = $request->fresh(['lines', 'purchaseOrders.lines']);
        $this->assertSame(2, (int) $request->lines[1]->deselected_step_no);
        $this->assertSame('1000000.00', (string) $request->total_amount);
        $this->assertSame(1, $request->purchaseOrders->flatMap->lines->count());
    }

    public function test_at_least_one_line_must_stay_checked(): void
    {
        [$request, $manager] = $this->submittedRequest();

        $this->expectException(\DomainException::class);
        $this->svc()->approve($request, $manager, null, 1, []);
    }

    public function test_unknown_line_ids_are_rejected(): void
    {
        [$request, $manager, , $lines] = $this->submittedRequest();

        $this->expectException(\DomainException::class);
        $this->svc()->approve($request, $manager, null, 1, [$lines[0]->id, 999999]);
    }

    public function test_approve_without_a_selection_keeps_everything_checked(): void
    {
        [$request, $manager, $director] = $this->submittedRequest();
        $this->svc()->approve($request, $manager, null, 1);
        $this->svc()->approve($request->fresh(), $director, null, 2);

        $request = $request->fresh(['lines', 'purchaseOrders']);
        $this->assertTrue($request->lines->every(fn ($l) => (bool) $l->is_selected));
        $this->assertSame(2, $request->purchaseOrders->count());
        $this->assertSame('3000000.00', (string) $request->total_amount);
    }

    public function test_http_approve_with_checkboxes_updates_the_selection(): void
    {
        [$request, $manager, , $lines] = $this->submittedRequest();

        $this->actingAs($manager)->patch("/purchase-requests/{$request->id}/approve", [
            'step_no' => 1,
            'selection_submitted' => 1,
            'selected_lines' => [$lines[0]->id, $lines[2]->id],
        ])->assertRedirect()->assertSessionHas('status');

        $request = $request->fresh('lines');
        $this->assertFalse((bool) $request->lines[1]->is_selected);
        $this->assertSame('2000000.00', (string) $request->total_amount);
    }

    public function test_http_unchecking_everything_is_refused_with_a_message(): void
    {
        [$request, $manager] = $this->submittedRequest();

        $this->actingAs($manager)->patch("/purchase-requests/{$request->id}/approve", [
            'step_no' => 1,
            'selection_submitted' => 1,
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(PurchaseRequestStatus::WAITING_APPROVAL, $request->fresh()->status);
        $this->assertSame('pending', $request->approvals()->where('step_no', 1)->first()->status);
    }

    public function test_show_page_renders_checkboxes_only_for_the_allowed_approver(): void
    {
        [$request, $manager, $director, $lines] = $this->submittedRequest();

        $this->actingAs($manager)->get("/purchase-requests/{$request->id}")
            ->assertOk()->assertSee('name="selected_lines[]"', false);

        $this->svc()->approve($request, $manager, null, 1);
        $this->actingAs($director)->get("/purchase-requests/{$request->id}")
            ->assertOk()->assertDontSee('name="selected_lines[]"', false);
    }

    public function test_level_flag_is_saved_from_the_settings_screen(): void
    {
        $this->resetApprovalConfig();
        $admin = User::factory()->create();
        $this->grantGlobal($admin, 'approval_setting.view');
        $this->grantGlobal($admin, 'approval_setting.manage');
        $admin = User::find($admin->id);
        $approver = $this->makeUser();

        $this->actingAs($admin)->put('/approval-settings/levels', ['levels' => [
            ['name' => 'Manager', 'is_active' => 1, 'can_select_items' => 1, 'user_ids' => [$approver->id]],
            ['name' => 'Direktur', 'is_active' => 1, 'can_select_items' => 0, 'user_ids' => [$approver->id]],
        ]])->assertRedirect();

        $this->assertTrue((bool) \App\Models\ApprovalLevel::where('name', 'Manager')->first()->can_select_items);
        $this->assertFalse((bool) \App\Models\ApprovalLevel::where('name', 'Direktur')->first()->can_select_items);
    }
}
