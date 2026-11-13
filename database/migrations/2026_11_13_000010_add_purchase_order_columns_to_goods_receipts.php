<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPurchaseOrderColumnsToGoodsReceipts extends Migration
{
    /**
     * Receipts now originate from purchase orders. Both columns stay nullable so receipts
     * created before this feature (no purchase order) remain valid and visible.
     */
    public function up()
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->after('branch_id')->constrained('purchase_orders');
        });

        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->foreignId('purchase_order_line_id')->nullable()->after('sparepart_branch_id')->constrained('purchase_order_lines');
        });
    }

    public function down()
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_line_id']);
            $table->dropColumn('purchase_order_line_id');
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_id']);
            $table->dropColumn('purchase_order_id');
        });
    }
}
