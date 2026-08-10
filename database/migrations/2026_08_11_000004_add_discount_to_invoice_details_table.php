<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddDiscountToInvoiceDetailsTable extends Migration
{
    public function up()
    {
        Schema::table('invoice_details', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->default(0)->after('unit_price');
            $table->decimal('discount_amount', 15, 2)->default(0)->after('discount_percent');
        });

        DB::statement('ALTER TABLE invoice_details ADD CONSTRAINT ck_invoice_details_discount_percent_range CHECK (discount_percent >= 0 AND discount_percent <= 100)');
        DB::statement('ALTER TABLE invoice_details ADD CONSTRAINT ck_invoice_details_discount_amount_nonnegative CHECK (discount_amount >= 0)');
    }

    public function down()
    {
        DB::statement('ALTER TABLE invoice_details DROP CONSTRAINT ck_invoice_details_discount_amount_nonnegative');
        DB::statement('ALTER TABLE invoice_details DROP CONSTRAINT ck_invoice_details_discount_percent_range');

        Schema::table('invoice_details', function (Blueprint $table) {
            $table->dropColumn(['discount_percent', 'discount_amount']);
        });
    }
}
