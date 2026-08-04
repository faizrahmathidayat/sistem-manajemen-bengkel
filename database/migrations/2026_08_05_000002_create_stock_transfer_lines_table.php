<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateStockTransferLinesTable extends Migration
{
    public function up()
    {
        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('sparepart_id')->constrained('spareparts');
            $table->decimal('qty', 18, 3);
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['stock_transfer_id', 'sparepart_id'], 'st_lines_st_id_sp_id_unique');
        });

        DB::statement('ALTER TABLE stock_transfer_lines ADD CONSTRAINT ck_stock_transfer_lines_qty_positive CHECK (qty > 0)');
    }

    public function down()
    {
        Schema::dropIfExists('stock_transfer_lines');
    }
}
