<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMechanicBranchesTable extends Migration
{
    public function up()
    {
        Schema::create('mechanic_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mechanic_id')->constrained('mechanics')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['mechanic_id', 'branch_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('mechanic_branches');
    }
}
