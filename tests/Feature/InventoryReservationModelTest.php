<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\InventoryReservation;
use App\Models\Mechanic;
use App\Models\MechanicBranch;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Models\VehicleCategory;
use App\Models\VehicleType;
use App\Models\WorkOrder;
use App\Models\WorkOrderSparepartLine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryReservationModelTest extends TestCase
{
    use RefreshDatabase;

    protected function makeSparepartLine(): WorkOrderSparepartLine
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
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
        $workOrder = WorkOrder::create([
            'number' => 'PKB/JKT/202608/00001', 'branch_id' => $branch->id,
            'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'mechanic_id' => $mechanic->id, 'work_order_date' => now()->format('Y-m-d'),
        ]);
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);

        return WorkOrderSparepartLine::create([
            'work_order_id' => $workOrder->id, 'sparepart_branch_id' => $sparepartBranch->id,
            'item_code_snapshot' => 'OLI-01', 'item_name_snapshot' => 'Oli Mesin',
            'qty' => 5, 'default_unit_price' => 60000, 'unit_price' => 60000, 'line_total' => 300000,
        ]);
    }

    public function test_reservation_can_be_created_with_fillable_fields_and_defaults_to_active(): void
    {
        $line = $this->makeSparepartLine();

        $reservation = InventoryReservation::create([
            'branch_id' => $line->workOrder->branch_id,
            'sparepart_branch_id' => $line->sparepart_branch_id,
            'reservation_type' => 'pkb',
            'reference_type' => 'work_order_sparepart_line',
            'reference_id' => $line->id,
            'qty' => 5,
        ]);

        $this->assertSame('active', $reservation->status);
        $this->assertSame(5.0, (float) $reservation->qty);
    }

    public function test_reservation_qty_must_be_positive(): void
    {
        $line = $this->makeSparepartLine();

        $this->expectException(QueryException::class);
        InventoryReservation::create([
            'branch_id' => $line->workOrder->branch_id,
            'sparepart_branch_id' => $line->sparepart_branch_id,
            'reservation_type' => 'pkb',
            'reference_type' => 'work_order_sparepart_line',
            'reference_id' => $line->id,
            'qty' => 0,
        ]);
    }

    public function test_sparepart_line_reservations_relation_scopes_to_its_own_reference_type(): void
    {
        $line = $this->makeSparepartLine();
        InventoryReservation::create([
            'branch_id' => $line->workOrder->branch_id, 'sparepart_branch_id' => $line->sparepart_branch_id,
            'reservation_type' => 'pkb', 'reference_type' => 'work_order_sparepart_line', 'reference_id' => $line->id, 'qty' => 3,
        ]);
        // A reservation with the same reference_id but a different reference_type must not leak in.
        InventoryReservation::create([
            'branch_id' => $line->workOrder->branch_id, 'sparepart_branch_id' => $line->sparepart_branch_id,
            'reservation_type' => 'transfer', 'reference_type' => 'stock_transfer_line', 'reference_id' => $line->id, 'qty' => 2,
        ]);

        $this->assertCount(1, $line->reservations);
        $this->assertSame(3.0, (float) $line->reservations->first()->qty);
    }

    public function test_work_order_has_null_shortage_columns_by_default(): void
    {
        $line = $this->makeSparepartLine();

        $this->assertNull($line->workOrder->shortage_override_reason);
        $this->assertNull($line->workOrder->shortage_overridden_by);
        $this->assertNull($line->workOrder->shortage_overridden_at);
    }
}
