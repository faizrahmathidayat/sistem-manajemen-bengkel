<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateApprovalLevelsTable extends Migration
{
    public function up()
    {
        Schema::create('approval_levels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('approval_level_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_level_id')->constrained('approval_levels')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['approval_level_id', 'user_id'], 'uq_approval_level_users');
        });
    }

    public function down()
    {
        Schema::dropIfExists('approval_level_users');
        Schema::dropIfExists('approval_levels');
    }
}
