<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Invoice;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\ServiceCatalog;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use App\Models\WorkOrder;
use App\Services\InvoiceService;
use App\Support\InvoicePkbGapComparator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePkbGapComparatorTest extends TestCase
{
    use RefreshDatabase;

    protected function makeCompletedWorkOrder(float $serviceAmount): WorkOrder
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);
        $category = VehicleCategory::create(['name' => 'Mobil']);
        $brand = VehicleBrand::create(['category_id' => $category->id, 'name' => 'Toyota']);
        $type = VehicleType::create(['brand_id' => $brand->id, 'name' => 'Avanza']);
        $vehicle = Vehicle::create([
            'customer_id' => $customer->id, 'category_id' => $category->id,
            'brand_id' => $brand->id, 'type_id' => $type->id, 'plate_number' => 'B 1234 JJ',
        ]);
        $mechanic = Mechanic::create(['name' => 'Mekanik Jakarta']);
        MechanicBranch::create(['mechanic_id' => $mechanic->id, 'branch_id' => $branch->id]);
        $catalog = ServiceCatalog::create(['code' => 'SVC-1', 'name' => 'Ganti Oli', 'default_price' => $serviceAmount]);

        $workOrder = WorkOrder::create([
            'number' => 'PKB/JKT/1', 'branch_id' => $branch->id, 'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id, 'mechanic_id' => $mechanic->id,
            'work_order_date' => now()->toDateString(), 'status' => 'completed',
        ]);
        $workOrder->serviceLines()->create([
            'service_catalog_id' => $catalog->id, 'description' => 'Ganti Oli',
            'qty' => 1, 'unit_price' => $serviceAmount, 'line_total' => $serviceAmount,
        ]);

        return $workOrder->fresh();
    }

    protected function makeInvoiceFromWorkOrder(WorkOrder $workOrder): Invoice
    {
        return (new InvoiceService())->createFromWorkOrder($workOrder);
    }

    public function test_line_with_matching_qty_and_price_and_no_discount_is_sesuai(): void
    {
        $workOrder = $this->makeCompletedWorkOrder(100000);
        $invoice = $this->makeInvoiceFromWorkOrder($workOrder)->load('details');

        $rows = InvoicePkbGapComparator::build($invoice);

        $this->assertCount(1, $rows);
        $this->assertSame('sesuai', $rows[0]['category']);
    }

    public function test_line_with_matching_qty_and_price_but_invoice_discount_is_discounted_not_sesuai(): void
    {
        $workOrder = $this->makeCompletedWorkOrder(100000);
        $invoice = $this->makeInvoiceFromWorkOrder($workOrder);
        $detail = $invoice->details->first();
        $detail->update(['discount_percent' => 10, 'discount_amount' => 10000, 'line_total' => 90000]);
        $invoice = $invoice->fresh(['details']);

        $rows = InvoicePkbGapComparator::build($invoice);

        $this->assertCount(1, $rows);
        $this->assertSame('discounted', $rows[0]['category']);
        $this->assertSame(10.0, $rows[0]['invoice_discount_percent']);
        $this->assertSame(10000.0, $rows[0]['invoice_discount_amount']);
    }

    public function test_line_with_different_qty_is_changed_even_when_discounted(): void
    {
        $workOrder = $this->makeCompletedWorkOrder(100000);
        $invoice = $this->makeInvoiceFromWorkOrder($workOrder);
        $detail = $invoice->details->first();
        $detail->update(['qty' => 2, 'discount_percent' => 10, 'discount_amount' => 20000, 'line_total' => 180000]);
        $invoice = $invoice->fresh(['details']);

        $rows = InvoicePkbGapComparator::build($invoice);

        $this->assertCount(1, $rows);
        $this->assertSame('changed', $rows[0]['category']);
    }

    public function test_added_line_reports_its_own_discount(): void
    {
        $workOrder = $this->makeCompletedWorkOrder(100000);
        $invoice = $this->makeInvoiceFromWorkOrder($workOrder);
        $invoice->details()->create([
            'item_type' => 'service', 'description' => 'Jasa tambahan', 'qty' => 1,
            'unit_price' => 50000, 'discount_percent' => 20, 'discount_amount' => 10000, 'line_total' => 40000,
        ]);
        $invoice = $invoice->fresh(['details']);

        $rows = InvoicePkbGapComparator::build($invoice);

        $addedRow = collect($rows)->firstWhere('category', 'added');
        $this->assertNotNull($addedRow);
        $this->assertSame(20.0, $addedRow['invoice_discount_percent']);
        $this->assertSame(10000.0, $addedRow['invoice_discount_amount']);
    }
}
