<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranch;
use App\Models\Sparepart;
use App\Models\SparepartBranch;
use App\Services\InvoiceService;
use App\Support\InvoiceDetailItemType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceHppSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function makeBranchAndCustomer(): array
    {
        $branch = Branch::create(['code' => 'JKT', 'name' => 'Cabang Jakarta']);
        $customer = Customer::create(['customer_type' => 'INDIVIDUAL', 'name' => 'Budi Santoso', 'stnk_name' => 'Budi Santoso']);
        CustomerBranch::create(['customer_id' => $customer->id, 'branch_id' => $branch->id]);

        return [$branch, $customer];
    }

    public function test_posting_snapshots_average_cost_onto_sparepart_lines(): void
    {
        [$branch, $customer] = $this->makeBranchAndCustomer();
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
        \DB::table('sparepart_branch_stocks')
            ->where('sparepart_branch_id', $sparepartBranch->id)
            ->update(['on_hand_qty' => 10, 'average_cost' => 37500]);

        $invoice = (new InvoiceService())->createDirectSale($branch, $customer, [
            'invoice_date' => now()->toDateString(),
            'services' => [
                ['description' => 'Cuci Mobil', 'qty' => 1, 'unit_price' => 40000],
            ],
            'spareparts' => [
                ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 2, 'unit_price' => 60000],
            ],
        ]);

        (new InvoiceService())->postInvoice($invoice->fresh());

        $serviceDetail = $invoice->fresh()->details->firstWhere('item_type', InvoiceDetailItemType::SERVICE);
        $sparepartDetail = $invoice->fresh()->details->firstWhere('item_type', InvoiceDetailItemType::SPAREPART);

        $this->assertSame(0.0, (float) $serviceDetail->hpp_snapshot, 'Baris jasa HPP harus selalu 0');
        $this->assertSame(37500.0, (float) $sparepartDetail->hpp_snapshot, 'Baris sparepart HPP harus disnapshot dari average_cost saat posting');
    }

    public function test_posting_does_not_change_average_cost(): void
    {
        [$branch, $customer] = $this->makeBranchAndCustomer();
        $sparepart = Sparepart::create(['code' => 'OLI-01', 'name' => 'Oli Mesin']);
        $sparepartBranch = SparepartBranch::create(['sparepart_id' => $sparepart->id, 'branch_id' => $branch->id, 'selling_price' => 60000]);
        \DB::table('sparepart_branch_stocks')
            ->where('sparepart_branch_id', $sparepartBranch->id)
            ->update(['on_hand_qty' => 10, 'average_cost' => 37500]);

        $invoice = (new InvoiceService())->createDirectSale($branch, $customer, [
            'invoice_date' => now()->toDateString(),
            'spareparts' => [
                ['sparepart_branch_id' => $sparepartBranch->id, 'qty' => 2, 'unit_price' => 60000],
            ],
        ]);

        (new InvoiceService())->postInvoice($invoice->fresh());

        $stock = \DB::table('sparepart_branch_stocks')->where('sparepart_branch_id', $sparepartBranch->id)->first();
        $this->assertSame(8.0, (float) $stock->on_hand_qty);
        $this->assertSame(37500.0, (float) $stock->average_cost, 'average_cost TIDAK boleh berubah oleh barang keluar (WAC hanya berubah saat barang masuk)');
    }
}
