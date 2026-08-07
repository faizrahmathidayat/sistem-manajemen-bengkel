<?php

namespace Tests\Feature;

use App\Mail\InvoicePostedMail;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
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
use Tests\TestCase;

class InvoicePostedMailTest extends TestCase
{
    use RefreshDatabase;

    protected function makeInvoice(Branch $branch)
    {
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso', 'email' => 'budi@example.test']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::create(['name' => 'Mobil']);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 XYZ',
        ]);
        $mechanic = Mechanic::create(['name' => 'Agus Setiawan']);
        MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $catalog = ServiceCatalog::create(['code' => 'SVC-01', 'name' => 'Ganti Oli', 'default_price' => 50000]);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
        \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->update(['on_hand_qty' => 10]);

        $user = User::factory()->create();
        (new UserBranchService())->assign($user, $branch);
        foreach (['pkb.create', 'pkb.confirm', 'pkb.complete'] as $code) {
            [$resource, $action] = explode('.', $code, 2);
            $permission = Permission::firstOrCreate(['code' => $code], ['resource' => $resource, 'action' => $action, 'description' => $code]);
            UserBranchPermission::create(['user_id' => $user->id, 'branch_id' => $branch->id, 'permission_id' => $permission->id]);
        }

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

        return (new InvoiceService())->createFromWorkOrder($workOrder->fresh());
    }

    public function test_build_sets_subject_view_and_pdf_attachment(): void
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $invoice = $this->makeInvoice($branch);

        $mailable = new InvoicePostedMail($invoice);
        // assertSeeInHtml triggers Mailable::build() internally (via renderForAssertions);
        // calling ->build() manually beforehand would invoke it twice and double the attachment.
        $mailable->assertSeeInHtml($invoice->number);
        $mailable->assertSeeInHtml($invoice->customer->name);

        $this->assertSame("Invoice {$invoice->number} — {$invoice->branch->name}", $mailable->subject);
        $this->assertCount(1, $mailable->rawAttachments);
        $this->assertSame('invoice-' . $invoice->number . '.pdf', $mailable->rawAttachments[0]['name']);
        $this->assertSame('application/pdf', $mailable->rawAttachments[0]['options']['mime']);
    }
}
