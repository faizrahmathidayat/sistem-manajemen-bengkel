<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateSparepartBranchStocksTable extends Migration
{
    public function up()
    {
        Schema::create('sparepart_branch_stocks', function (Blueprint $table) {
            $table->foreignId('sparepart_branch_id')->primary();
            $table->decimal('on_hand_qty', 18, 3)->default(0);
            $table->decimal('reserved_qty', 18, 3)->default(0);
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('sparepart_branch_id')->references('id')->on('sparepart_branches')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE sparepart_branch_stocks ADD CONSTRAINT ck_stock_nonnegative CHECK (on_hand_qty >= 0 AND reserved_qty >= 0 AND reserved_qty <= on_hand_qty)');
    }

    public function down()
    {
        Schema::dropIfExists('sparepart_branch_stocks');
    }
}
