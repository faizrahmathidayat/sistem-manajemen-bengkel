<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approvers of a level flagged can_select_items may uncheck lines while approving; unchecked
 * lines are left out of the purchase orders. The flag is copied to the approval snapshot at
 * submit time, like the approver list.
 */
class AddItemSelectionToPurchaseRequests extends Migration
{
    public function up()
    {
        Schema::table('approval_levels', function (Blueprint $table) {
            $table->boolean('can_select_items')->default(false)->after('is_active');
        });

        Schema::table('purchase_request_approvals', function (Blueprint $table) {
            $table->boolean('can_select_items')->default(false)->after('status');
        });

        Schema::table('purchase_request_lines', function (Blueprint $table) {
            $table->boolean('is_selected')->default(true)->after('line_total');
            $table->unsignedSmallInteger('deselected_step_no')->nullable()->after('is_selected');
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->decimal('submitted_total', 18, 2)->nullable()->after('total_amount');
        });

        DB::table('purchase_requests')->whereNotNull('submitted_at')->update(['submitted_total' => DB::raw('total_amount')]);
    }

    public function down()
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn('submitted_total');
        });
        Schema::table('purchase_request_lines', function (Blueprint $table) {
            $table->dropColumn(['is_selected', 'deselected_step_no']);
        });
        Schema::table('purchase_request_approvals', function (Blueprint $table) {
            $table->dropColumn('can_select_items');
        });
        Schema::table('approval_levels', function (Blueprint $table) {
            $table->dropColumn('can_select_items');
        });
    }
}
