<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreatePurchaseRequestLinesTable extends Migration
{
    public function up()
    {
        Schema::create('purchase_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained('purchase_requests')->cascadeOnDelete();
            $table->foreignId('sparepart_branch_id')->constrained('sparepart_branches');
            $table->decimal('qty', 18, 3);
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers');
            $table->decimal('unit_price', 18, 2)->nullable();
            $table->decimal('line_total', 18, 2)->nullable();
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['purchase_request_id', 'sparepart_branch_id'], 'uq_pr_lines_request_sparepart');
        });

        DB::statement('ALTER TABLE purchase_request_lines ADD CONSTRAINT ck_pr_lines_qty_positive CHECK (qty > 0)');
        DB::statement('ALTER TABLE purchase_request_lines ADD CONSTRAINT ck_pr_lines_price_nonnegative CHECK ((unit_price IS NULL OR unit_price >= 0) AND (line_total IS NULL OR line_total >= 0))');
    }

    public function down()
    {
        Schema::dropIfExists('purchase_request_lines');
    }
}
