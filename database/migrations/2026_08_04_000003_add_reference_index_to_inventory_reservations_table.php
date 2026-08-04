<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddReferenceIndexToInventoryReservationsTable extends Migration
{
    public function up()
    {
        Schema::table('inventory_reservations', function (Blueprint $table) {
            $table->index(['reference_type', 'reference_id'], 'idx_inventory_reservations_reference');
        });
    }

    public function down()
    {
        Schema::table('inventory_reservations', function (Blueprint $table) {
            $table->dropIndex('idx_inventory_reservations_reference');
        });
    }
}
