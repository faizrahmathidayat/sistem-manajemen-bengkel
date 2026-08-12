<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Invoice;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\Permission;
use App\Models\ServiceCatalog;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\User;
use App\Models\UserBranchPermission;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use App\Models\WorkOrder;
use App\Services\InvoiceService;
use App\Services\UserBranchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ExtractsPdfText;
use Tests\TestCase;

class PublicInvoiceTest extends TestCase
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

    protected function makeWorkOrder(Branch $branch): WorkOrder
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::create(['name' => "Mobil {$branch->code}"]);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => "B 1234 {$branch->code}",
        ]);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $catalog = ServiceCatalog::create(['code' => "SVC-01-{$branch->code}", 'name' => 'Ganti Oli', 'default_price' => 50000]);
        $sparepart = Sparepart::create(['code' => "OLI-01-{$branch->code}", 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => 10]);

        $user = User::factory()->create();
        $this->grantBranchPermission($user, $branch, 'pkb.create');
        $this->grantBranchPermission($user, $branch, 'pkb.confirm');
        $this->grantBranchPermission($user, $branch, 'pkb.complete');

        $this->actingAs($user)->post('/work-orders', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id,
            'work_order_date' => now()->format('Y-m-d'),
            'services' => [
                ['service_catalog_id' => $catalog->id, 'description' => 'Ganti Oli', 'qty' => 1, 'unit_price' => 50000],
            ],
            'spareparts' => [
                ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 2, 'unit_price' => 60000],
            ],
        ]);
        $workOrder = WorkOrder::latest('id')->first();
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/confirm");
        $this->actingAs($user)->patch("/work-orders/{$workOrder->id}/complete");

        return $workOrder->fresh();
    }

    protected function makePostedInvoice(Branch $branch): Invoice
    {
        $invoice = (new InvoiceService())->createFromWorkOrder($this->makeWorkOrder($branch));
        (new InvoiceService())->postInvoice($invoice);

        return $invoice->fresh();
    }

    protected function makeDraftInvoice(Branch $branch): Invoice
    {
        return (new InvoiceService())->createFromWorkOrder($this->makeWorkOrder($branch));
    }

    public function test_show_pin_form_renders_for_a_posted_invoice_with_valid_hash(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);

        $response = $this->get("/i/{$invoice->hash_id}");

        $response->assertOk();
        $response->assertSee($invoice->number);
    }

    public function test_show_pin_form_404s_for_unknown_hash(): void
    {
        $response = $this->get('/i/does-not-exist');

        $response->assertNotFound();
    }

    public function test_show_pin_form_404s_for_a_draft_invoice(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeDraftInvoice($branch);

        $response = $this->get("/i/{$invoice->hash_id}");

        $response->assertNotFound();
    }

    public function test_show_pin_form_redirects_to_pdf_when_session_already_verified(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);

        $response = $this->withSession(["public_invoice_verified.{$invoice->id}" => true])
            ->get("/i/{$invoice->hash_id}");

        $response->assertRedirect(route('public-invoices.pdf', $invoice));
    }

    public function test_verify_pin_with_correct_pin_sets_session_and_redirects_to_pdf(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);

        $response = $this->post("/i/{$invoice->hash_id}/verify", ['pin' => $invoice->pin]);

        $response->assertRedirect(route('public-invoices.pdf', $invoice));
        $this->assertTrue(session("public_invoice_verified.{$invoice->id}"));
    }

    public function test_verify_pin_with_wrong_pin_redirects_back_with_error_and_does_not_set_session(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $wrongPin = $invoice->pin === '000000' ? '111111' : '000000';

        $response = $this->post("/i/{$invoice->hash_id}/verify", ['pin' => $wrongPin]);

        $response->assertRedirect("/i/{$invoice->hash_id}");
        $response->assertSessionHas('error');
        $this->assertNull(session("public_invoice_verified.{$invoice->id}"));
    }

    public function test_verify_pin_is_throttled_after_ten_attempts_per_minute(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);
        $wrongPin = $invoice->pin === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 10; $i++) {
            $this->post("/i/{$invoice->hash_id}/verify", ['pin' => $wrongPin]);
        }
        $response = $this->post("/i/{$invoice->hash_id}/verify", ['pin' => $wrongPin]);

        $response->assertStatus(429);
    }

    public function test_show_pdf_without_verified_session_redirects_to_pin_form(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);

        $response = $this->get("/i/{$invoice->hash_id}/pdf");

        $response->assertRedirect("/i/{$invoice->hash_id}");
    }

    public function test_show_pdf_with_verified_session_streams_matching_pdf(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);

        $response = $this->withSession(["public_invoice_verified.{$invoice->id}" => true])
            ->get("/i/{$invoice->hash_id}/pdf");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $content = $this->extractPdfText($response->getContent());
        $this->assertStringContainsString($invoice->number, $content);
    }

    public function test_all_public_invoice_routes_work_without_authentication(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makePostedInvoice($branch);

        $this->get("/i/{$invoice->hash_id}")->assertOk();
        $this->post("/i/{$invoice->hash_id}/verify", ['pin' => $invoice->pin])
            ->assertRedirect(route('public-invoices.pdf', $invoice));
        $this->withSession(["public_invoice_verified.{$invoice->id}" => true])
            ->get("/i/{$invoice->hash_id}/pdf")->assertOk();
    }
}
