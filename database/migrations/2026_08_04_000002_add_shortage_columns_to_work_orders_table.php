<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddShortageColumnsToWorkOrdersTable extends Migration
{
    public function up()
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->text('shortage_override_reason')->nullable()->after('notes');
            $table->unsignedBigInteger('shortage_overridden_by')->nullable()->after('shortage_override_reason');
            $table->timestamp('shortage_overridden_at')->nullable()->after('shortage_overridden_by');

            $table->foreign('shortage_overridden_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropForeign(['shortage_overridden_by']);
            $table->dropColumn(['shortage_override_reason', 'shortage_overridden_by', 'shortage_overridden_at']);
        });
    }
}
