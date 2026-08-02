<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVehiclesTable extends Migration
{
    public function up()
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('plate_number', 30)->nullable()->unique();
            $table->string('frame_number', 100)->nullable()->unique();
            $table->string('engine_number', 100)->nullable()->unique();
            $table->foreignId('category_id')->constrained('vehicle_categories');
            $table->foreignId('brand_id')->constrained('vehicle_brands');
            $table->foreignId('type_id')->constrained('vehicle_types');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('vehicles');
    }
}
