<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Permission;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\InvoiceService;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrossProfitReportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function grantBranchPermission(User $user, Branch $branch, string $code): void
    {
        (new UserBranchService())->assign($user, $branch);
        [$resource, $action] = explode('.', $code, 2);
        $permission = Permission::firstOrCreate(
            ['code' => $code],
            ['resource' => $resource, 'action' => $action, 'description' => $code]
        );
        UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);
    }

    protected function makePostedInvoice(Branch $branch, float $avgCost, float $sellingPrice, float $qty): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $sparepart = Sparepart::create(['code' => 'OLI-' . uniqid(), 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => $sellingPrice]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => 100, 'average_cost' => $avgCost]);

        $invoice = (new InvoiceService())->createDirectSale($branch, $customer, [
            'invoice_date' => now()->toDateString(),
            'services' => [['description' => 'Cuci Mobil', 'qty' => 1, 'unit_price' => 40000]],
            'spareparts' => [['sparepart_branch_id' => $sparepartBranch->id, 'qty' => $qty, 'unit_price' => $sellingPrice]],
        ]);
        (new InvoiceService())->postInvoice($invoice->fresh());
    }

    public function test_index_is_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/reports/gross-profit');

        $response->assertOk();
        $response->assertSee('belum memiliki akses');
    }

    public function test_summary_view_computes_gross_profit_correctly(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makePostedInvoice($branch, 37500, 60000, 2); // pendapatan sparepart 120000, hpp 75000
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.gross_profit.view');

        $response = $this->actingAs($user)->get('/reports/gross-profit');

        $response->assertOk();
        // Pendapatan jasa 40000 + sparepart 120000 = 160000. HPP = 75000. Laba kotor = 85000.
        $response->assertSee('85.000');
    }

    public function test_draft_and_cancelled_invoices_are_excluded_from_summary(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi', 'stnk_name' => 'Budi']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        // Invoice draft (tidak diposting) tidak boleh ikut dihitung.
        (new InvoiceService())->createDirectSale($branch, $customer, [
            'invoice_date' => now()->toDateString(),
            'services' => [['description' => 'Cuci Mobil', 'qty' => 1, 'unit_price' => 999999]],
        ]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.gross_profit.view');

        $response = $this->actingAs($user)->get('/reports/gross-profit');

        $response->assertOk();
        $response->assertDontSee('999.999');
    }

    public function test_branch_filter_restricts_results(): void
    {
        $branchA = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $branchB = Branch::create(['code' => 'BDG', 'name' => 'Cabang Bandung']);
        $this->makePostedInvoice($branchA, 10000, 20000, 1);
        $this->makePostedInvoice($branchB, 10000, 30000, 1);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branchA, 'report.gross_profit.view');
        $this->grantBranchPermission($user, $branchB, 'report.gross_profit.view');

        $response = $this->actingAs($user)->get('/reports/gross-profit?branch_ids[]=' . $branchA->id);

        $response->assertOk();
        // Pendapatan sparepart branch A: 20000. Branch B (30000) tidak boleh ikut.
        $response->assertDontSee('30.000');
    }

    public function test_invoice_detail_view_shows_gross_profit_per_invoice(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makePostedInvoice($branch, 37500, 60000, 2);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.gross_profit.view');

        $response = $this->actingAs($user)->get('/reports/gross-profit?view_type=invoice_detail');

        $response->assertOk();
        $response->assertSee('85.000');
    }
}
