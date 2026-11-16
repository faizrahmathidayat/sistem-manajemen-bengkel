<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - purchase_requests.is_urgent: the requester marks a request urgent.
 * - suppliers.account_number: bank account kept on the supplier master.
 * - purchase_request_lines.supplier_account_number: the account number agreed for this request
 *   (defaults from the master, editable until approval is requested), copied to the purchase order.
 */
class AddUrgentAndAccountNumberToProcurement extends Migration
{
    public function up()
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->boolean('is_urgent')->default(false)->after('notes');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('account_number', 60)->nullable()->after('address');
        });

        Schema::table('purchase_request_lines', function (Blueprint $table) {
            $table->string('supplier_account_number', 60)->nullable()->after('supplier_id');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('supplier_account_number', 60)->nullable()->after('supplier_id');
        });
    }

    public function down()
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('supplier_account_number');
        });
        Schema::table('purchase_request_lines', function (Blueprint $table) {
            $table->dropColumn('supplier_account_number');
        });
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('account_number');
        });
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn('is_urgent');
        });
    }
}
