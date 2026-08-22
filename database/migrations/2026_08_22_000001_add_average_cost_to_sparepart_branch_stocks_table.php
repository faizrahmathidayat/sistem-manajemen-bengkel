<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddAverageCostToSparepartBranchStocksTable extends Migration
{
    public function up()
    {
        Schema::table('sparepart_branch_stocks', function (Blueprint $table) {
            $table->decimal('average_cost', 18, 2)->default(0)->after('reserved_qty');
        });
    }

    public function down()
    {
        Schema::table('sparepart_branch_stocks', function (Blueprint $table) {
            $table->dropColumn('average_cost');
        });
    }
}
