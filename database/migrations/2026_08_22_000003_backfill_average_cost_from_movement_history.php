<?php

use App\Models\SparepartBranchStock;
use App\Services\AverageCostBackfillService;
use App\Services\InventoryCostService;
use Illuminate\Database\Migrations\Migration;

class BackfillAverageCostFromMovementHistory extends Migration
{
    public function up()
    {
        (new AverageCostBackfillService(new InventoryCostService()))->run();
    }

    public function down()
    {
        SparepartBranchStock::query()->update(['average_cost' => 0]);
    }
}
