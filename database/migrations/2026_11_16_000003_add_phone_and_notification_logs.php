<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPhoneAndNotificationLogs extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_number', 20)->nullable();
            $table->boolean('notify_enabled')->default(true);
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('purchase_request_id')->nullable();
            $table->string('event', 40);
            $table->string('channel', 30)->nullable();
            $table->string('recipient', 20);
            $table->text('message');
            $table->string('status', 20)->default('pending'); // pending | sent | failed | logged
            $table->text('response')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('purchase_request_id')->references('id')->on('purchase_requests')->nullOnDelete();
            $table->index(['purchase_request_id', 'event']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('notification_logs');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone_number', 'notify_enabled']);
        });
    }
}
