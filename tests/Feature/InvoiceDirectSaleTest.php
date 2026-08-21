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
use App\Services\InvoiceService;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceDirectSaleTest extends TestCase
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

    protected function makeBranchAndCustomer(): array
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);

        return [$branch, $customer];
    }

    public function test_create_direct_sale_builds_draft_invoice_without_work_order(): void
    {
        [$branch, $customer] = $this->makeBranchAndCustomer();
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);

        $invoice = (new InvoiceService())->createDirectSale($branch, $customer, [
            'invoice_date' => now()->toDateString(),
            'services' => [
                ['description' => 'Cuci Mobil', 'qty' => 1, 'unit_price' => 40000, 'discount_percent' => 0],
            ],
            'spareparts' => [
                ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 2, 'unit_price' => 60000, 'discount_percent' => 10],
            ],
        ]);

        $this->assertNull($invoice->work_order_id);
        $this->assertTrue($invoice->is_direct_sale);
        $this->assertSame(\App\Support\InvoiceStatus::DRAFT, $invoice->status);
        $this->assertStringStartsWith("DS/{$branch->code}/", $invoice->number);
        $this->assertCount(2, $invoice->details);

        $sparepartDetail = $invoice->details->firstWhere('item_type', \App\Support\InvoiceDetailItemType::SPAREPART);
        $this->assertSame(10.0, (float) $sparepartDetail->discount_percent);
        $this->assertSame(12000.0, (float) $sparepartDetail->discount_amount);
        $this->assertSame(108000.0, (float) $sparepartDetail->line_total);

        $this->assertSame(40000.0, (float) $invoice->subtotal_service);
        $this->assertSame(108000.0, (float) $invoice->subtotal_sparepart);
        $this->assertSame(148000.0, (float) $invoice->grand_total);
    }

    public function test_invoices_table_accepts_null_work_order_id(): void
    {
        [$branch, $customer] = $this->makeBranchAndCustomer();

        $invoice = \App\Models\Invoice::create([
            'number' => 'DS/JKT/202608/00001',
            'work_order_id' => null,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'status' => \App\Support\InvoiceStatus::DRAFT,
        ]);

        $this->assertNull($invoice->fresh()->work_order_id);
    }

    public function test_invoice_policy_create_direct_requires_invoice_create_permission_in_branch(): void
    {
        [$branch] = $this->makeBranchAndCustomer();
        $user = User::factory()->create();
        $policy = new \App\Policies\InvoicePolicy();

        $this->assertFalse($policy->createDirect($user, $branch));

        $this->grantBranchPermission($user, $branch, 'invoice.create');
        $this->assertTrue($policy->createDirect($user->fresh(), $branch));
    }

    public function test_create_direct_form_is_visible_for_user_with_invoice_create_permission(): void
    {
        [$branch] = $this->makeBranchAndCustomer();
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.create');

        $response = $this->actingAs($user)->get('/invoices/direct/create');

        $response->assertOk();
        $response->assertSee('Invoice Langsung');
    }

    public function test_create_direct_form_shows_no_access_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/invoices/direct/create');

        $response->assertOk();
        $response->assertSee('belum memiliki akses');
    }

    public function test_store_direct_creates_invoice_and_redirects_to_show(): void
    {
        [$branch, $customer] = $this->makeBranchAndCustomer();
        $catalog = ServiceCatalog::create(['code' => 'SVC-CUCI', 'name' => 'Cuci Mobil', 'default_price' => 40000]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.create');

        $response = $this->actingAs($user)->post('/invoices/direct', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'services' => [
                ['description' => $catalog->name, 'qty' => 1, 'unit_price' => 40000],
            ],
            'spareparts' => [],
        ]);

        $invoice = \App\Models\Invoice::latest('id')->first();
        $response->assertRedirect("/invoices/{$invoice->id}");
        $this->assertNull($invoice->work_order_id);
        $this->assertStringStartsWith('DS/', $invoice->number);
    }

    public function test_store_direct_rejects_decimal_qty(): void
    {
        [$branch, $customer] = $this->makeBranchAndCustomer();
        $catalog = ServiceCatalog::create(['code' => 'SVC-CUCI', 'name' => 'Cuci Mobil', 'default_price' => 40000]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.create');

        $response = $this->actingAs($user)->post('/invoices/direct', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'services' => [
                ['description' => $catalog->name, 'qty' => 1.5, 'unit_price' => 40000],
            ],
            'spareparts' => [],
        ]);

        $response->assertSessionHasErrors(['services.0.qty']);
        $this->assertSame(0, \App\Models\Invoice::count());
    }

    public function test_store_direct_rejects_empty_line_items(): void
    {
        [$branch, $customer] = $this->makeBranchAndCustomer();
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.create');

        $response = $this->actingAs($user)->post('/invoices/direct', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
        ]);

        $response->assertSessionHasErrors('services');
    }

    public function test_show_direct_sale_invoice_does_not_crash_and_shows_placeholder(): void
    {
        [$branch, $customer] = $this->makeBranchAndCustomer();
        $invoice = (new InvoiceService())->createDirectSale($branch, $customer, [
            'invoice_date' => now()->toDateString(),
            'services' => [['description' => 'Cuci Mobil', 'qty' => 1, 'unit_price' => 40000]],
        ]);
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.view');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertSee('Direct Sales');
    }

    public function test_print_direct_sale_invoice_does_not_crash(): void
    {
        [$branch, $customer] = $this->makeBranchAndCustomer();
        $invoice = (new InvoiceService())->createDirectSale($branch, $customer, [
            'invoice_date' => now()->toDateString(),
            'services' => [['description' => 'Cuci Mobil', 'qty' => 1, 'unit_price' => 40000]],
        ]);
        (new InvoiceService())->updateInvoice($invoice, [
            'discount_percent' => 0,
            'tax_percent' => 0,
            'services' => [['description' => 'Cuci Mobil', 'qty' => 1, 'unit_price' => 40000]],
            'spareparts' => [],
        ]);
        (new InvoiceService())->postInvoice($invoice->fresh());
        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'invoice.print');

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}/print");

        $response->assertOk();
    }
}
