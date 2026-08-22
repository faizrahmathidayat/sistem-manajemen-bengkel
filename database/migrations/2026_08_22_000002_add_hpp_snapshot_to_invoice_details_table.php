<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddHppSnapshotToInvoiceDetailsTable extends Migration
{
    public function up()
    {
        Schema::table('invoice_details', function (Blueprint $table) {
            $table->decimal('hpp_snapshot', 18, 2)->default(0)->after('line_total');
        });
    }

    public function down()
    {
        Schema::table('invoice_details', function (Blueprint $table) {
            $table->dropColumn('hpp_snapshot');
        });
    }
}
