<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddSparepartBranchIdToInvoiceDetailsTable extends Migration
{
    public function up()
    {
        Schema::table('invoice_details', function (Blueprint $table) {
            $table->foreignId('sparepart_branch_id')->nullable()->after('work_order_sparepart_line_id')->constrained('sparepart_branches');
        });

        // Backfill existing PKB-traced sparepart rows so the new
        // ck_invoice_details_sparepart_requires_branch CHECK (added below) doesn't reject them.
        DB::statement('
            UPDATE invoice_details
            JOIN work_order_sparepart_lines ON work_order_sparepart_lines.id = invoice_details.work_order_sparepart_line_id
            SET invoice_details.sparepart_branch_id = work_order_sparepart_lines.sparepart_branch_id
            WHERE invoice_details.item_type = "sparepart"
        ');

        DB::statement('ALTER TABLE invoice_details DROP CONSTRAINT ck_invoice_details_single_source');
        DB::statement('ALTER TABLE invoice_details ADD CONSTRAINT ck_invoice_details_not_both_sources CHECK (NOT (work_order_service_line_id IS NOT NULL AND work_order_sparepart_line_id IS NOT NULL))');
        DB::statement("ALTER TABLE invoice_details ADD CONSTRAINT ck_invoice_details_sparepart_requires_branch CHECK (item_type <> 'sparepart' OR sparepart_branch_id IS NOT NULL)");
    }

    public function down()
    {
        DB::statement('ALTER TABLE invoice_details DROP CONSTRAINT ck_invoice_details_sparepart_requires_branch');
        DB::statement('ALTER TABLE invoice_details DROP CONSTRAINT ck_invoice_details_not_both_sources');
        DB::statement('ALTER TABLE invoice_details ADD CONSTRAINT ck_invoice_details_single_source CHECK ((work_order_service_line_id IS NOT NULL AND work_order_sparepart_line_id IS NULL) OR (work_order_service_line_id IS NULL AND work_order_sparepart_line_id IS NOT NULL))');

        Schema::table('invoice_details', function (Blueprint $table) {
            $table->dropForeign(['sparepart_branch_id']);
            $table->dropColumn('sparepart_branch_id');
        });
    }
}
