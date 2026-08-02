<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCustomerBranchesTable extends Migration
{
    public function up()
    {
        Schema::create('customer_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['customer_id', 'branch_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('customer_branches');
    }
}
