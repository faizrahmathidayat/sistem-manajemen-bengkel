<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PurchaseRequestStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsPermissions;
use Tests\Concerns\MakesProcurementData;
use Tests\TestCase;

class PurchaseRequestAuthorizationTest extends TestCase
{
    use RefreshDatabase, GrantsPermissions, MakesProcurementData;

    protected function requester($branch, array $codes = ['purchase_request.view', 'purchase_request.create']): User
    {
        $user = User::factory()->create();
        foreach ($codes as $code) {
            $this->grantBranch($user, $branch, $code);
        }

        return User::find($user->id);
    }

    protected function requestBy(User $creator, $branch, array $overrides = [], array $lineOverrides = [])
    {
        $this->actingAs($creator);
        $sp = $this->makeSparepartBranch($branch);

        return $this->makePurchaseRequest(
            $branch,
            [array_merge(['sparepart_branch_id' => $sp->id, 'qty' => 5], $lineOverrides)],
            $overrides
        );
    }

    public function test_view_is_limited_to_own_branch_unless_a_global_permission_applies(): void
    {
        $bks = $this->makeBranch('BKS');
        $utm = $this->makeBranch('UTM', 'Cabang Utama');
        $owner = $this->requester($bks);
        $request = $this->requestBy($owner, $bks);

        $this->assertTrue($owner->can('view', $request));
        $this->assertFalse($this->requester($utm)->can('view', $request));

        foreach (['purchase_request.view_all', 'purchase_request.assign_supplier', 'approval_setting.manage'] as $code) {
            $global = User::factory()->create();
            $this->grantGlobal($global, $code);
            $this->assertTrue(User::find($global->id)->can('view', $request), $code);
        }
    }

    public function test_creator_can_update_and_cancel_while_waiting_and_unassigned(): void
    {
        $bks = $this->makeBranch('BKS');
        $owner = $this->requester($bks);
        $request = $this->requestBy($owner, $bks);

        $this->assertTrue($owner->can('update', $request));
        $this->assertTrue($owner->can('cancel', $request));
    }

    public function test_non_creator_with_create_permission_cannot_update_or_cancel(): void
    {
        $bks = $this->makeBranch('BKS');
        $request = $this->requestBy($this->requester($bks), $bks);
        $other = $this->requester($bks);

        $this->assertFalse($other->can('update', $request));
        $this->assertFalse($other->can('cancel', $request));
    }

    public function test_update_and_cancel_are_denied_once_status_moves_on(): void
    {
        $bks = $this->makeBranch('BKS');
        $owner = $this->requester($bks);
        $request = $this->requestBy($owner, $bks, ['status' => PurchaseRequestStatus::WAITING_APPROVAL]);

        $this->assertFalse($owner->can('update', $request));
        $this->assertFalse($owner->can('cancel', $request));
    }

    public function test_update_and_cancel_are_denied_once_any_line_has_a_supplier(): void
    {
        $bks = $this->makeBranch('BKS');
        $owner = $this->requester($bks);
        $supplier = $this->makeSupplier();
        $request = $this->requestBy($owner, $bks, [], ['supplier_id' => $supplier->id]);

        $this->assertFalse($owner->can('update', $request));
        $this->assertFalse($owner->can('cancel', $request));
    }

    public function test_assign_supplier_requires_global_permission_and_waiting_status(): void
    {
        $bks = $this->makeBranch('BKS');
        $request = $this->requestBy($this->requester($bks), $bks);

        $gudang = User::factory()->create();
        $this->grantGlobal($gudang, 'purchase_request.assign_supplier');
        $gudang = User::find($gudang->id);

        $this->assertTrue($gudang->can('assignSupplier', $request));
        $this->assertFalse($this->requester($bks)->can('assignSupplier', $request));

        $request->update(['status' => PurchaseRequestStatus::WAITING_APPROVAL]);
        $this->assertFalse($gudang->can('assignSupplier', $request->fresh()));
    }
}
