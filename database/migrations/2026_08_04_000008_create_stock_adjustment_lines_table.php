<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateStockAdjustmentLinesTable extends Migration
{
    public function up()
    {
        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained('stock_adjustments')->cascadeOnDelete();
            $table->foreignId('sparepart_branch_id')->constrained('sparepart_branches');
            $table->decimal('system_qty', 18, 3);
            $table->decimal('physical_qty', 18, 3);
            $table->decimal('adjustment_qty', 18, 3);
            $table->string('reason', 255);
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['stock_adjustment_id', 'sparepart_branch_id'], 'sa_lines_sa_id_sb_id_unique');
        });

        DB::statement('ALTER TABLE stock_adjustment_lines ADD CONSTRAINT ck_stock_adjustment_lines_qty_nonnegative CHECK (system_qty >= 0 AND physical_qty >= 0)');
    }

    public function down()
    {
        Schema::dropIfExists('stock_adjustment_lines');
    }
}
