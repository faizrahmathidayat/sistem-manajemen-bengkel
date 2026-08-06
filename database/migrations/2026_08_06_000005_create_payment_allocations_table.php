<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreatePaymentAllocationsTable extends Migration
{
    public function up()
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_receipt_id')->constrained('payment_receipts')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices');
            $table->decimal('allocated_amount', 18, 2);
            $table->timestamps();

            $table->unique(['payment_receipt_id', 'invoice_id']);
            $table->index('invoice_id');
        });

        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT ck_payment_allocations_amount_positive CHECK (allocated_amount > 0)');
    }

    public function down()
    {
        Schema::dropIfExists('payment_allocations');
    }
}
