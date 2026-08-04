<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateInventoryMovementsTable extends Migration
{
    public function up()
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->timestamp('movement_at');
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('sparepart_branch_id')->constrained('sparepart_branches');
            $table->string('movement_type', 20);
            $table->decimal('qty_in', 18, 3)->default(0);
            $table->decimal('qty_out', 18, 3)->default(0);
            $table->decimal('balance_after', 18, 3);
            $table->string('reference_type', 50);
            $table->unsignedBigInteger('reference_id');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['branch_id', 'sparepart_branch_id', 'movement_at'], 'idx_inv_mov_branch_sb_mat');
        });

        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT ck_inventory_movements_qty_nonnegative CHECK (qty_in >= 0 AND qty_out >= 0)');
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT ck_inventory_movements_single_direction CHECK (NOT (qty_in > 0 AND qty_out > 0))');
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT ck_inventory_movements_nonzero CHECK (qty_in > 0 OR qty_out > 0)');
    }

    public function down()
    {
        Schema::dropIfExists('inventory_movements');
    }
}
