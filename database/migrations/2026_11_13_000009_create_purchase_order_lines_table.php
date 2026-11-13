<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreatePurchaseOrderLinesTable extends Migration
{
    public function up()
    {
        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('purchase_request_line_id')->constrained('purchase_request_lines');
            $table->foreignId('sparepart_branch_id')->constrained('sparepart_branches');
            $table->decimal('qty_ordered', 18, 3);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('line_total', 18, 2);
            $table->decimal('qty_received', 18, 3)->default(0);
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            // A request line is bought exactly once.
            $table->unique('purchase_request_line_id', 'uq_po_lines_request_line');
        });

        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT ck_po_lines_qty_ordered_positive CHECK (qty_ordered > 0)');
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT ck_po_lines_qty_received_range CHECK (qty_received >= 0 AND qty_received <= qty_ordered)');
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT ck_po_lines_price_nonnegative CHECK (unit_price >= 0 AND line_total >= 0)');
    }

    public function down()
    {
        Schema::dropIfExists('purchase_order_lines');
    }
}
