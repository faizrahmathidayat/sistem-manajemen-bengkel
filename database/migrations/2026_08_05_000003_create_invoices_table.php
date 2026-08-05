<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateInvoicesTable extends Migration
{
    public function up()
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 50)->unique();
            $table->foreignId('work_order_id')->unique()->constrained('work_orders');
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('customer_id')->constrained('customers');
            $table->date('invoice_date');
            $table->string('status', 20)->default('draft');
            $table->decimal('subtotal_service', 18, 2)->default(0);
            $table->decimal('subtotal_sparepart', 18, 2)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['branch_id', 'invoice_date', 'status']);
            $table->index(['customer_id', 'status']);
        });

        DB::statement("ALTER TABLE invoices ADD CONSTRAINT ck_invoices_subtotals_nonnegative CHECK (subtotal_service >= 0 AND subtotal_sparepart >= 0)");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT ck_invoices_discount_percent_range CHECK (discount_percent >= 0 AND discount_percent <= 100)");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT ck_invoices_tax_percent_nonnegative CHECK (tax_percent >= 0)");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT ck_invoices_amounts_nonnegative CHECK (discount_amount >= 0 AND tax_amount >= 0 AND grand_total >= 0)");
    }

    public function down()
    {
        Schema::dropIfExists('invoices');
    }
}
