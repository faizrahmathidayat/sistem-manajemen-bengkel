<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Permission;
use App\Models\ServiceCatalog;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Services\UserBranchService;
use App\Support\InvoiceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ExtractsPdfText;
use Tests\TestCase;

class InvoiceDirectSaleIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use ExtractsPdfText;

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

    public function test_full_direct_sale_lifecycle_create_edit_discount_post_print(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $catalog = ServiceCatalog::create(['code' => 'SVC-CUCI', 'name' => 'Cuci Mobil', 'default_price' => 40000]);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => 10]);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.create');
        $this->grantBranchPermission($user, $branch, 'invoice.view');
        $this->grantBranchPermission($user, $branch, 'invoice.edit');
        $this->grantBranchPermission($user, $branch, 'invoice.post');
        $this->grantBranchPermission($user, $branch, 'invoice.print');

        // 1. Create the Direct Sales invoice with a per-line discount already on the sparepart line.
        $storeResponse = $this->actingAs($user)->post('/invoices/direct', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'services' => [
                ['description' => $catalog->name, 'qty' => 1, 'unit_price' => 40000, 'discount_percent' => 0],
            ],
            'spareparts' => [
                ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 2, 'unit_price' => 60000, 'discount_percent' => 10],
            ],
        ]);

        $invoice = \App\Models\Invoice::latest('id')->first();
        $storeResponse->assertRedirect("/invoices/{$invoice->id}");
        $this->assertNull($invoice->work_order_id);
        $this->assertTrue($invoice->is_direct_sale);
        $this->assertStringStartsWith("DS/{$branch->code}/", $invoice->number);
        $this->assertSame(InvoiceStatus::DRAFT, $invoice->status);

        $sparepartDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);
        $this->assertSame(12000.0, (float) $sparepartDetail->discount_amount);
        $this->assertSame(108000.0, (float) $sparepartDetail->line_total);

        // 2. show() does not crash and labels it "Direct Sales" instead of a PKB number.
        $showResponse = $this->actingAs($user)->get("/invoices/{$invoice->id}");
        $showResponse->assertOk();
        $showResponse->assertSee('Direct Sales');

        // 3. Header discount/tax entered via the existing edit/update flow.
        $updateResponse = $this->actingAs($user)->put("/invoices/{$invoice->id}", [
            'discount_percent' => 5,
            'tax_percent' => 11,
            'services' => [[
                'work_order_service_line_id' => null,
                'description' => $catalog->name,
                'qty' => 1,
                'unit_price' => 40000,
                'discount_percent' => 0,
            ]],
            'spareparts' => [[
                'work_order_sparepart_line_id' => null,
                'sparepart_branch_id' => $sparepartBranch->id,
                'qty' => 2,
                'unit_price' => 60000,
                'discount_percent' => 10,
            ]],
        ]);
        $updateResponse->assertRedirect("/invoices/{$invoice->id}");
        $invoice->refresh();
        $this->assertSame(5.0, (float) $invoice->discount_percent);
        $this->assertSame(11.0, (float) $invoice->tax_percent);

        // 4. Posting deducts stock exactly like a PKB-based invoice.
        $stockBefore = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $postResponse = $this->actingAs($user)->patch("/invoices/{$invoice->id}/post");
        $postResponse->assertRedirect("/invoices/{$invoice->id}");
        $this->assertSame(InvoiceStatus::POSTED, $invoice->fresh()->status);
        $stockAfter = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame((float) $stockBefore->on_hand_qty - 2.0, (float) $stockAfter->on_hand_qty);

        // 5. Printing the PDF does not crash, shows "Direct Sales", the Diskon column, and the PPN row (tax > 0).
        $printResponse = $this->actingAs($user)->get("/invoices/{$invoice->id}/print");
        $printResponse->assertOk();
        $printContent = $this->extractPdfText($printResponse->getContent());
        $this->assertStringContainsString('Direct Sales', $printContent);
        $this->assertStringContainsString('Diskon', $printContent);
        $this->assertStringContainsString('PPN', $printContent);

        // 6. The PKB-vs-Invoice gap report must NOT list this Direct Sales invoice as a gap
        // (its query already filters whereNotNull('invoices.work_order_id')). Permission code
        // and route confirmed from InvoicePkbGapReportControllerTest.php / routes/web.php.
        $this->grantBranchPermission($user, $branch, 'report.invoice_pkb_gap.view');
        $gapResponse = $this->actingAs($user)->get('/reports/invoice-pkb-gap');
        $gapResponse->assertOk();
        $gapResponse->assertDontSee($invoice->number);
    }
}
