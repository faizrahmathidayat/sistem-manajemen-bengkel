<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePurchaseRequestApprovalsTable extends Migration
{
    public function up()
    {
        Schema::create('purchase_request_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained('purchase_requests')->cascadeOnDelete();
            $table->unsignedInteger('step_no');
            $table->unsignedBigInteger('approval_level_id')->nullable();
            $table->string('level_name', 100);
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('acted_by')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('approval_level_id')->references('id')->on('approval_levels')->nullOnDelete();
            $table->foreign('acted_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['purchase_request_id', 'step_no'], 'uq_pr_approvals_step');
        });

        Schema::create('purchase_request_approval_users', function (Blueprint $table) {
            $table->id();
            // Explicit FK name: the auto-generated one exceeds MySQL's 64-character limit.
            $table->unsignedBigInteger('purchase_request_approval_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->foreign('purchase_request_approval_id', 'fk_pra_users_approval')
                ->references('id')->on('purchase_request_approvals')->cascadeOnDelete();

            $table->unique(['purchase_request_approval_id', 'user_id'], 'uq_pr_approval_users');
        });
    }

    public function down()
    {
        Schema::dropIfExists('purchase_request_approval_users');
        Schema::dropIfExists('purchase_request_approvals');
    }
}
