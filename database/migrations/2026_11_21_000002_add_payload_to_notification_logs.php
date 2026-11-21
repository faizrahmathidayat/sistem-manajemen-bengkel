<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPayloadToNotificationLogs extends Migration
{
    public function up()
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            // Extra provider fields sent with the message, e.g. Telegram inline buttons.
            $table->text('payload')->nullable()->after('message');
        });
    }

    public function down()
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropColumn('payload');
        });
    }
}
