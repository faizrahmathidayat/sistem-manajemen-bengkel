<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateWorkOrdersTable extends Migration
{
    public function up()
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('vehicle_id')->constrained('vehicles');
            $table->foreignId('mechanic_id')->constrained('mechanics');
            $table->date('work_order_date');
            $table->decimal('odometer_km', 12, 1)->nullable();
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['branch_id', 'work_order_date', 'status']);
        });

        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT ck_work_orders_odometer_nonnegative CHECK (odometer_km IS NULL OR odometer_km >= 0)");
    }

    public function down()
    {
        Schema::dropIfExists('work_orders');
    }
}
