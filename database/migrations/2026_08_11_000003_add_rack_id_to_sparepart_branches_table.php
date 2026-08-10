<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRackIdToSparepartBranchesTable extends Migration
{
    public function up()
    {
        Schema::table('sparepart_branches', function (Blueprint $table) {
            $table->foreignId('rack_id')->nullable()->after('rack_number')->constrained('racks')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('sparepart_branches', function (Blueprint $table) {
            $table->dropForeign(['rack_id']);
            $table->dropColumn('rack_id');
        });
    }
}
