<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\AuditLogger;
use App\Services\UserBranchService;
use App\Support\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function grantGlobalPermission(User $user, string $code): void
    {
        [$resource, $action] = explode('.', $code, 2);
        $permission = Permission::firstOrCreate(
            ['code' => $code],
            ['resource' => $resource, 'action' => $action, 'description' => $code]
        );
        \App\Models\UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);
    }

    /**
     * The event filter's <select> always lists every AuditEvent label as an
     * <option>, so a whole-page assertSee/assertDontSee can't distinguish
     * "narrowed results" from "still an option in the dropdown". Scope the
     * assertion to the results table card only.
     */
    protected function resultsTableHtml(string $content): string
    {
        $start = strpos($content, '<div class="card">');
        $end = strpos($content, '<div class="mt-3">');

        return substr($content, $start, $end - $start);
    }

    public function test_index_requires_audit_log_view_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/audit-logs');

        $response->assertForbidden();
    }

    public function test_index_lists_logs_across_every_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        (new AuditLogger())->log(AuditEvent::INVOICE_POSTED, $branchA->id, null, [], ['status' => 'posted']);
        (new AuditLogger())->log(AuditEvent::STOCK_ADJUSTMENT_POSTED, $branchB->id, null, [], ['status' => 'posted']);
        $user = User::factory()->create();
        $this->grantGlobalPermission($user, 'audit_log.view');

        $response = $this->actingAs($user)->get('/audit-logs');

        $response->assertOk();
        $response->assertSee(AuditEvent::LABELS[AuditEvent::INVOICE_POSTED]);
        $response->assertSee(AuditEvent::LABELS[AuditEvent::STOCK_ADJUSTMENT_POSTED]);
    }

    public function test_index_filters_by_event(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        (new AuditLogger())->log(AuditEvent::INVOICE_POSTED, $branch->id, null, [], ['status' => 'posted']);
        (new AuditLogger())->log(AuditEvent::INVOICE_CANCELLED, $branch->id, null, [], ['status' => 'cancelled']);
        $user = User::factory()->create();
        $this->grantGlobalPermission($user, 'audit_log.view');

        $response = $this->actingAs($user)->get('/audit-logs?event=' . AuditEvent::INVOICE_POSTED);

        $response->assertOk();
        $table = $this->resultsTableHtml($response->getContent());
        $this->assertStringContainsString(AuditEvent::LABELS[AuditEvent::INVOICE_POSTED], $table);
        $this->assertStringNotContainsString(AuditEvent::LABELS[AuditEvent::INVOICE_CANCELLED], $table);
    }

    public function test_index_filters_by_branch(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        (new AuditLogger())->log(AuditEvent::INVOICE_POSTED, $branchA->id, null, [], ['status' => 'posted']);
        (new AuditLogger())->log(AuditEvent::STOCK_ADJUSTMENT_POSTED, $branchB->id, null, [], ['status' => 'posted']);
        $user = User::factory()->create();
        $this->grantGlobalPermission($user, 'audit_log.view');

        $response = $this->actingAs($user)->get("/audit-logs?branch_ids[]={$branchA->id}");

        $response->assertOk();
        $table = $this->resultsTableHtml($response->getContent());
        $this->assertStringContainsString(AuditEvent::LABELS[AuditEvent::INVOICE_POSTED], $table);
        $this->assertStringNotContainsString(AuditEvent::LABELS[AuditEvent::STOCK_ADJUSTMENT_POSTED], $table);
    }

    public function test_index_filters_by_user(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $actorA = User::factory()->create(['name' => 'Andi Petugas']);
        $actorB = User::factory()->create(['name' => 'Budi Petugas']);
        $this->actingAs($actorA);
        (new AuditLogger())->log(AuditEvent::INVOICE_POSTED, $branch->id, null, [], ['status' => 'posted']);
        $this->actingAs($actorB);
        (new AuditLogger())->log(AuditEvent::INVOICE_CANCELLED, $branch->id, null, [], ['status' => 'cancelled']);
        $viewer = User::factory()->create();
        $this->grantGlobalPermission($viewer, 'audit_log.view');

        $response = $this->actingAs($viewer)->get('/audit-logs?user=Andi');

        $response->assertOk();
        $table = $this->resultsTableHtml($response->getContent());
        $this->assertStringContainsString(AuditEvent::LABELS[AuditEvent::INVOICE_POSTED], $table);
        $this->assertStringNotContainsString(AuditEvent::LABELS[AuditEvent::INVOICE_CANCELLED], $table);
    }

    public function test_index_shows_old_and_new_values_diff(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        (new AuditLogger())->log(AuditEvent::INVOICE_CANCELLED, $branch->id, null, ['status' => 'draft'], ['status' => 'cancelled', 'reason' => 'Uji diff']);
        $user = User::factory()->create();
        $this->grantGlobalPermission($user, 'audit_log.view');

        $response = $this->actingAs($user)->get('/audit-logs');

        $response->assertOk();
        $response->assertSee('Uji diff');
    }
}
