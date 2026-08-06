<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreatePaymentReceiptsTable extends Migration
{
    public function up()
    {
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('customer_id')->constrained('customers');
            $table->date('payment_date');
            $table->string('payment_method', 20);
            $table->string('reference_number', 100)->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('status', 20)->default('posted');
            $table->text('notes')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->text('void_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('voided_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['branch_id', 'payment_date', 'status']);
        });

        DB::statement('ALTER TABLE payment_receipts ADD CONSTRAINT ck_payment_receipts_amount_positive CHECK (amount > 0)');
    }

    public function down()
    {
        Schema::dropIfExists('payment_receipts');
    }
}
