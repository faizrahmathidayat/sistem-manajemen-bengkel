<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateGoodsReceiptLinesTable extends Migration
{
    public function up()
    {
        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->foreignId('sparepart_branch_id')->constrained('sparepart_branches');
            $table->decimal('qty', 18, 3);
            $table->decimal('purchase_price', 18, 2);
            $table->decimal('line_total', 18, 2);
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT ck_goods_receipt_lines_qty_positive CHECK (qty > 0)');
        DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT ck_goods_receipt_lines_price_nonnegative CHECK (purchase_price >= 0 AND line_total >= 0)');
    }

    public function down()
    {
        Schema::dropIfExists('goods_receipt_lines');
    }
}
