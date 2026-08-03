<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateWorkOrderServiceLinesTable extends Migration
{
    public function up()
    {
        Schema::create('work_order_service_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('service_catalog_id')->nullable()->constrained('service_catalogs');
            $table->string('description', 255);
            $table->decimal('qty', 18, 3);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('line_total', 18, 2);
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        DB::statement("ALTER TABLE work_order_service_lines ADD CONSTRAINT ck_wo_service_lines_qty_positive CHECK (qty > 0)");
        DB::statement("ALTER TABLE work_order_service_lines ADD CONSTRAINT ck_wo_service_lines_price_nonnegative CHECK (unit_price >= 0 AND line_total >= 0)");
    }

    public function down()
    {
        Schema::dropIfExists('work_order_service_lines');
    }
}
