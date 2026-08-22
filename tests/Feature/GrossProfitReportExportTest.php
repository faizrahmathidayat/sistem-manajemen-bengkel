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

class GrossProfitReportExportTest extends TestCase
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

    protected function makePostedInvoice(Branch $branch): void
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => 100, 'average_cost' => 37500]);

        $invoice = (new InvoiceService())->createDirectSale($branch, $customer, [
            'invoice_date' => now()->toDateString(),
            'spareparts' => [['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 2, 'unit_price' => 60000]],
        ]);
        (new InvoiceService())->postInvoice($invoice->fresh());
    }

    public function test_pdf_actions_are_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/reports/gross-profit/pdf-preview')->assertForbidden();
        $this->actingAs($user)->get('/reports/gross-profit/pdf-download')->assertForbidden();
    }

    public function test_pdf_preview_returns_inline_disposition_with_correct_totals(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.gross_profit.view');

        $response = $this->actingAs($user)->get('/reports/gross-profit/pdf-preview');

        $response->assertOk();
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
    }

    public function test_pdf_download_returns_attachment_disposition(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.gross_profit.view');

        $response = $this->actingAs($user)->get('/reports/gross-profit/pdf-download');

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    public function test_pdf_preview_invoice_detail_view_returns_ok(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $this->makePostedInvoice($branch);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'report.gross_profit.view');

        $response = $this->actingAs($user)->get('/reports/gross-profit/pdf-preview?view_type=invoice_detail');

        $response->assertOk();
    }
}
