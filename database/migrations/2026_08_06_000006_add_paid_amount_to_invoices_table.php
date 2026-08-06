<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddPaidAmountToInvoicesTable extends Migration
{
    public function up()
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('paid_amount', 18, 2)->default(0)->after('grand_total');
        });

        DB::statement('ALTER TABLE invoices ADD CONSTRAINT ck_invoices_paid_amount_nonnegative CHECK (paid_amount >= 0)');
    }

    public function down()
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('paid_amount');
        });
    }
}
