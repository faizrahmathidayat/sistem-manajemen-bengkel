<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateInventoryReservationsTable extends Migration
{
    public function up()
    {
        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('sparepart_branch_id')->constrained('sparepart_branches');
            $table->string('reservation_type', 20);
            $table->string('reference_type', 50);
            $table->unsignedBigInteger('reference_id');
            $table->decimal('qty', 18, 3);
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['sparepart_branch_id', 'status']);
        });

        DB::statement('ALTER TABLE inventory_reservations ADD CONSTRAINT ck_inventory_reservations_qty_positive CHECK (qty > 0)');
    }

    public function down()
    {
        Schema::dropIfExists('inventory_reservations');
    }
}
