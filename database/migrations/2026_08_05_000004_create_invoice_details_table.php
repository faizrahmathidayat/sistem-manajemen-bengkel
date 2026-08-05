<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateInvoiceDetailsTable extends Migration
{
    public function up()
    {
        Schema::create('invoice_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('item_type', 20);
            $table->foreignId('work_order_service_line_id')->nullable()->constrained('work_order_service_lines');
            $table->foreignId('work_order_sparepart_line_id')->nullable()->constrained('work_order_sparepart_lines');
            $table->string('item_code_snapshot', 30)->nullable();
            $table->string('description', 255);
            $table->decimal('qty', 18, 3);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('line_total', 18, 2);
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['invoice_id', 'sort_order']);
        });

        DB::statement("ALTER TABLE invoice_details ADD CONSTRAINT ck_invoice_details_item_type CHECK (item_type IN ('service', 'sparepart'))");
        DB::statement("ALTER TABLE invoice_details ADD CONSTRAINT ck_invoice_details_single_source CHECK ((work_order_service_line_id IS NOT NULL AND work_order_sparepart_line_id IS NULL) OR (work_order_service_line_id IS NULL AND work_order_sparepart_line_id IS NOT NULL))");
        DB::statement("ALTER TABLE invoice_details ADD CONSTRAINT ck_invoice_details_qty_positive CHECK (qty > 0)");
        DB::statement("ALTER TABLE invoice_details ADD CONSTRAINT ck_invoice_details_price_nonnegative CHECK (unit_price >= 0 AND line_total >= 0)");
    }

    public function down()
    {
        Schema::dropIfExists('invoice_details');
    }
}
