<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVehicleBrandsTable extends Migration
{
    public function up()
    {
        Schema::create('vehicle_brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('vehicle_categories')->cascadeOnDelete();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'name']);
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('vehicle_brands');
    }
}
