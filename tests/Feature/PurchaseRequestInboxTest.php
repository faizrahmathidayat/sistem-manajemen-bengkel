<?php

namespace Tests\Feature;

use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\PurchaseRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsPermissions;
use Tests\Concerns\MakesProcurementData;
use Tests\TestCase;

class PurchaseRequestInboxTest extends TestCase
{
    use RefreshDatabase, GrantsPermissions, MakesProcurementData;

    protected function submit(int $total, string $number): PurchaseRequest
    {
        $gudang = User::factory()->create();
        $this->grantGlobal($gudang, 'purchase_request.assign_supplier');
        $request = $this->makeAssignedRequest($this->makeBranch('BKS'), $total, ['number' => $number]);
        app(PurchaseRequestService::class)->submit($request, User::find($gudang->id));

        return $request;
    }

    public function test_manager_sees_waiting_requests_and_director_only_sees_those_that_reach_them(): void
    {
        [$manager, $director] = $this->makeStandardApprovalConfig();
        $small = $this->submit(8000000, 'RQ/BKS/202611/10001');   // Manager only
        $big = $this->submit(38320000, 'RQ/BKS/202611/10002');    // Manager -> Direktur
        $managerUser = $manager->users->first();
        $directorUser = $director->users->first();

        $this->actingAs($managerUser)->get('/approvals')->assertOk()
            ->assertSee('RQ/BKS/202611/10001')->assertSee('RQ/BKS/202611/10002');

        // Director sees nothing yet: neither request has reached level 2, and the small one never will.
        $this->actingAs($directorUser)->get('/approvals')->assertOk()
            ->assertDontSee('RQ/BKS/202611/10001')->assertDontSee('RQ/BKS/202611/10002');

        app(PurchaseRequestService::class)->approve($big, $managerUser);

        $this->actingAs($directorUser)->get('/approvals')->assertSee('RQ/BKS/202611/10002')
            ->assertDontSee('RQ/BKS/202611/10001');
        $this->actingAs($managerUser)->get('/approvals')->assertDontSee('RQ/BKS/202611/10002')
            ->assertSee('RQ/BKS/202611/10001');
    }

    public function test_history_tab_lists_own_decisions_with_the_outcome(): void
    {
        [$manager] = $this->makeStandardApprovalConfig();
        $request = $this->submit(8000000, 'RQ/BKS/202611/10003');
        $managerUser = $manager->users->first();
        app(PurchaseRequestService::class)->reject($request, $managerUser, 'Tidak sesuai anggaran');

        $this->actingAs($managerUser)->get('/approvals?tab=history')->assertOk()
            ->assertSee('RQ/BKS/202611/10003')->assertSee('Ditolak')->assertSee('Tidak sesuai anggaran');
        $this->actingAs($managerUser)->get('/approvals')->assertDontSee('RQ/BKS/202611/10003');
    }

    public function test_requests_the_user_created_or_already_decided_are_not_in_their_waiting_list(): void
    {
        [$manager, $director] = $this->makeStandardApprovalConfig();
        $both = $this->makeUser();
        $manager->users()->sync([$both->id]);
        $director->users()->sync([$both->id, $this->makeUser()->id]);
        $request = $this->submit(38320000, 'RQ/BKS/202611/10004');

        app(PurchaseRequestService::class)->approve($request, $both);

        // $both already decided step 1 and is also the level-2 approver: must not be offered again.
        $this->actingAs($both)->get('/approvals')->assertDontSee('RQ/BKS/202611/10004');
    }

    public function test_user_who_is_not_an_approver_gets_the_no_access_page_and_no_sidebar_link(): void
    {
        $this->makeStandardApprovalConfig();
        $outsider = User::factory()->create();
        $this->grantGlobal($outsider, 'dashboard.view');

        $this->actingAs(User::find($outsider->id))->get('/approvals')->assertOk()->assertViewIs('approvals.no-access');
        $this->actingAs(User::find($outsider->id))->get('/dashboard')->assertDontSee(route('approvals.index'), false);
    }

    public function test_approver_sees_the_sidebar_link_with_a_waiting_count_badge(): void
    {
        [$manager] = $this->makeStandardApprovalConfig();
        $this->submit(8000000, 'RQ/BKS/202611/10005');
        $this->submit(9000000, 'RQ/BKS/202611/10006');
        $managerUser = $manager->users->first();
        $this->grantGlobal($managerUser, 'dashboard.view');

        $html = $this->actingAs(User::find($managerUser->id))->get('/dashboard')
            ->assertSee(route('approvals.index'), false)->getContent();

        $this->assertMatchesRegularExpression('/Approval Permintaan.*?>\s*2\s*</s', $html);
    }
}
