<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddNipAndJoinDateToMechanicsTable extends Migration
{
    public function up()
    {
        Schema::table('mechanics', function (Blueprint $table) {
            $table->string('nip', 50)->nullable()->unique()->after('name');
            $table->date('join_date')->nullable()->after('nip');
        });

        DB::table('mechanics')->whereNull('nip')->orderBy('id')->each(function ($mechanic) {
            DB::table('mechanics')->where('id', $mechanic->id)->update([
                'nip' => 'LEGACY-' . str_pad($mechanic->id, 6, '0', STR_PAD_LEFT),
            ]);
        });
    }

    public function down()
    {
        Schema::table('mechanics', function (Blueprint $table) {
            $table->dropUnique(['nip']);
            $table->dropColumn(['nip', 'join_date']);
        });
    }
}
