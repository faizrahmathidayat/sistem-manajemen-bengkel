<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateApprovalRulesTable extends Migration
{
    public function up()
    {
        Schema::create('approval_rules', function (Blueprint $table) {
            $table->id();
            // Inclusive upper bound of the request total; NULL = no upper bound (last rule only).
            $table->decimal('max_amount', 18, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('approval_rule_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_rule_id')->constrained('approval_rules')->cascadeOnDelete();
            $table->foreignId('approval_level_id')->constrained('approval_levels');
            $table->timestamps();

            $table->unique(['approval_rule_id', 'approval_level_id'], 'uq_approval_rule_levels');
        });
    }

    public function down()
    {
        Schema::dropIfExists('approval_rule_levels');
        Schema::dropIfExists('approval_rules');
    }
}
