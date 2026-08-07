<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\UserPermission;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_hides_links_the_user_has_no_permission_for(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee(route('branches.index'), false);
        $response->assertDontSee(route('users.index'), false);
    }

    public function test_sidebar_shows_cabang_link_when_user_has_branch_view_permission(): void
    {
        $permission = Permission::create([
            'code' => 'branch.view',
            'resource' => 'branch',
            'action' => 'view',
            'description' => 'Melihat cabang',
        ]);
        $user = User::factory()->create();
        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('branches.index'), false);
        $response->assertDontSee(route('users.index'), false);
    }

    public function test_sidebar_shows_users_link_when_user_has_user_view_permission(): void
    {
        $permission = Permission::create([
            'code' => 'user.view',
            'resource' => 'user',
            'action' => 'view',
            'description' => 'Melihat user',
        ]);
        $user = User::factory()->create();
        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('users.index'), false);
        $response->assertDontSee(route('branches.index'), false);
    }

    public function test_sidebar_shows_customer_link_without_requiring_branch_view_permission(): void
    {
        $permission = Permission::create([
            'code' => 'customer.view',
            'resource' => 'customer',
            'action' => 'view',
            'description' => 'Melihat customer',
        ]);
        $user = User::factory()->create();
        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('customers.index'), false);
        $response->assertDontSee(route('branches.index'), false);
    }

    public function test_sidebar_shows_vehicle_and_vehicle_reference_links_when_authorized(): void
    {
        $user = User::factory()->create();

        foreach (['vehicle.view', 'vehicle_reference.view'] as $code) {
            [$resource, $action] = explode('.', $code, 2);
            $permission = Permission::create(['code' => $code, 'resource' => $resource, 'action' => $action, 'description' => $code]);
            UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);
        }

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('vehicles.index'), false);
        $response->assertSee(route('vehicle-references.index'), false);
    }

    public function test_sidebar_shows_mechanic_and_service_links_when_authorized(): void
    {
        $user = User::factory()->create();

        foreach (['mechanic.view', 'service.view'] as $code) {
            [$resource, $action] = explode('.', $code, 2);
            $permission = Permission::create(['code' => $code, 'resource' => $resource, 'action' => $action, 'description' => $code]);
            UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);
        }

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('mechanics.index'), false);
        $response->assertSee(route('service-catalogs.index'), false);
    }

    public function test_sidebar_shows_master_sparepart_link_only_for_branch_scoped_permission(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create([
            'code' => 'sparepart.view',
            'resource' => 'sparepart',
            'action' => 'view',
            'description' => 'Melihat sparepart',
        ]);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('sparepart-branches.index'), false);
    }

    public function test_sidebar_hides_master_sparepart_link_without_any_branch_grant(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee(route('sparepart-branches.index'), false);
    }

    public function test_sidebar_hides_master_sparepart_link_for_global_permission_without_branch_scope(): void
    {
        $permission = Permission::create([
            'code' => 'sparepart.view',
            'resource' => 'sparepart',
            'action' => 'view',
            'description' => 'Melihat sparepart',
        ]);
        $user = User::factory()->create();
        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee(route('sparepart-branches.index'), false);
    }

    public function test_navbar_shows_quick_search_input_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Cari No. PKB, No. Polisi, Kode Sparepart, No. Invoice', false);
    }

    public function test_navbar_shows_up_to_three_permission_badges_and_overflow_count(): void
    {
        $user = User::factory()->create();
        $codes = ['branch.view', 'customer.view', 'vehicle.view', 'mechanic.view'];

        foreach ($codes as $code) {
            [$resource, $action] = explode('.', $code, 2);
            $permission = Permission::create(['code' => $code, 'resource' => $resource, 'action' => $action, 'description' => $code]);
            UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);
        }

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee('branch.view', false);
        $response->assertSee('customer.view', false);
        $response->assertSee('vehicle.view', false);
        $response->assertDontSee('mechanic.view', false);
        $response->assertSee('+1 lainnya', false);
    }

    public function test_navbar_shows_notification_bell(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('bi-bell', false);
    }

    public function test_sidebar_shows_pkb_placeholder_when_user_has_pkb_view_permission_in_a_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'pkb.view', 'resource' => 'pkb', 'action' => 'view', 'description' => 'Melihat PKB']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        // "Perintah Kerja Bengkel" is the sidebar link's own unique text.
        // "bi-clipboard-check" is NOT a safe companion assertion â€” the
        // Dashboard's own "Status PKB Hari Ini" KPI card renders the same
        // icon class unconditionally (dashboard/index.blade.php), which would
        // make that assertion pass even if this sidebar link were broken.
        // (The Dashboard's own "Buat PKB Baru" button, previously a disabled
        // placeholder mentioned here, is now a real conditional link â€” see
        // test_buat_pkb_baru_button_shown_when_user_has_pkb_create_permission
        // in DashboardTest.php.)
        $response->assertSee('Perintah Kerja Bengkel', false);
        // Also assert on the real route URL: the old disabled-placeholder markup
        // (<span class="nav-link nav-link-disabled">...) contained the same text
        // but no href, so a text-only assertion would still pass on a regression
        // back to a non-functional placeholder. Only a real <a href> link renders
        // this URL (same precedent as test_sidebar_shows_kartu_stok_placeholder_
        // alongside_master_sparepart below).
        $response->assertSee(route('work-orders.index'), false);
    }

    public function test_sidebar_hides_pkb_placeholder_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('Perintah Kerja Bengkel', false);
    }

    public function test_sidebar_shows_kartu_stok_placeholder_alongside_master_sparepart(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'sparepart.view', 'resource' => 'sparepart', 'action' => 'view', 'description' => 'Melihat sparepart']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('sparepart-branches.index'), false);
        // Not a bare assertSee('Kartu Stok'): the Dashboard's own "Kartu Stok"
        // tab button (rendered unconditionally for every user) shares that
        // exact text with this sidebar placeholder. Assert against the
        // sidebar placeholder's unique icon class instead.
        $response->assertSee('bi-card-list', false);
        $response->assertSee(route('stock-card.index'), false);
    }

    public function test_sidebar_shows_penerimaan_barang_placeholder_when_user_has_receipt_view_permission_in_a_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'receipt.view', 'resource' => 'receipt', 'action' => 'view', 'description' => 'Melihat penerimaan barang']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Penerimaan Barang', false);
        // Also assert on the real route URL: the old disabled-placeholder markup
        // (<span class="nav-link nav-link-disabled">...) contained the same text
        // but no href, so a text-only assertion would still pass on a regression
        // back to a non-functional placeholder (same precedent as
        // test_sidebar_shows_pkb_placeholder_when_user_has_pkb_view_permission_in_a_branch above).
        $response->assertSee(route('goods-receipts.index'), false);
    }

    public function test_sidebar_shows_stock_adjustment_placeholder_when_user_has_stock_adjustment_view_permission_in_a_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'stock_adjustment.view', 'resource' => 'stock_adjustment', 'action' => 'view', 'description' => 'Melihat stock adjustment']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Stock Adjustment', false);
        // Also assert on the real route URL: the old disabled-placeholder markup
        // (<span class="nav-link nav-link-disabled">...) contained the same text
        // but no href, so a text-only assertion would still pass on a regression
        // back to a non-functional placeholder (same precedent as
        // test_sidebar_shows_pkb_placeholder_when_user_has_pkb_view_permission_in_a_branch above).
        $response->assertSee(route('stock-adjustments.index'), false);
    }


    public function test_sidebar_shows_transfer_stock_link_when_user_has_stock_transfer_view_permission_in_a_branch(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'stock_transfer.view', 'resource' => 'stock_transfer', 'action' => 'view', 'description' => 'Melihat transfer stock']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Transfer Stock', false);
        // Also assert on the real route URL: the old disabled-placeholder markup
        // (<span class="nav-link nav-link-disabled">...) contained the same text
        // but no href, so a text-only assertion would still pass on a regression
        // back to a non-functional placeholder (same precedent as
        // test_sidebar_shows_pkb_placeholder_when_user_has_pkb_view_permission_in_a_branch above).
        $response->assertSee(route('stock-transfers.index'), false);
    }
    public function test_sidebar_links_directly_to_audit_log_when_permitted(): void
    {
        $permission = Permission::create(['code' => 'audit_log.view', 'resource' => 'audit_log', 'action' => 'view', 'description' => 'Melihat audit log']);
        $user = User::factory()->create();
        UserPermission::create(['user_id' => $user->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('audit-logs.index'), false);
        $response->assertDontSee('Segera Hadir', false);
    }

    public function test_sidebar_links_directly_to_pkb_report_when_permitted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'report.pkb.view', 'resource' => 'report', 'action' => 'pkb.view', 'description' => 'Melihat laporan PKB']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('reports.pkb.index'), false);
        $response->assertDontSee('Segera Hadir', false);
    }

    public function test_sidebar_links_directly_to_invoices_when_permitted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'invoice.view', 'resource' => 'invoice', 'action' => 'view', 'description' => 'Melihat invoice']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('invoices.index'), false);
        $response->assertDontSee('Segera Hadir', false);
    }

    public function test_sidebar_links_directly_to_payment_receipts_when_permitted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'payment.view', 'resource' => 'payment', 'action' => 'view', 'description' => 'Melihat pembayaran']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('payment-receipts.index'), false);
        $response->assertDontSee('Segera Hadir', false);
    }

    public function test_sidebar_links_directly_to_receivables_report_when_permitted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'report.receivable.view', 'resource' => 'report', 'action' => 'receivable.view', 'description' => 'Melihat laporan piutang']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('reports.receivables.index'), false);
        $response->assertDontSee('Segera Hadir', false);
    }

    public function test_sidebar_links_directly_to_invoice_report_when_permitted(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $permission = Permission::create(['code' => 'report.invoice.view', 'resource' => 'report', 'action' => 'invoice.view', 'description' => 'Melihat laporan invoice']);
        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('reports.invoices.index'), false);
        $response->assertDontSee('Segera Hadir', false);
    }

    public function test_sidebar_hides_all_new_placeholder_headings_without_any_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertDontSee('Perintah Kerja Bengkel', false);
        // Not a bare assertDontSee('Invoice'): the navbar's quick-search placeholder
        // ("...No. Invoice...", from Task 2) legitimately contains that substring on
        // every authenticated page. Assert against the placeholder's unique icon class instead.
        $response->assertDontSee('bi-receipt', false);
        $response->assertDontSee('Penerimaan Pembayaran', false);
        $response->assertDontSee('Penerimaan Barang', false);
        $response->assertDontSee('Stock Adjustment', false);
        $response->assertDontSee('Transfer Stock', false);
        // Not a bare assertDontSee('Audit Log'): Task 7's Dashboard tab button
        // (#tab-audit-log, rendered unconditionally for every user) shares the
        // exact text "Audit Log" with this sidebar placeholder. Assert against
        // the placeholder's unique icon class instead.
        $response->assertDontSee('bi-journal-text', false);
        $response->assertDontSee('Laporan PKB', false);
        $response->assertDontSee('Laporan Invoice', false);
    }
}
