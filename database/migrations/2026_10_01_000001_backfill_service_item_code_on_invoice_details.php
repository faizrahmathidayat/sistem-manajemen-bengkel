<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class BackfillServiceItemCodeOnInvoiceDetails extends Migration
{
    /**
     * Service invoice lines never stored a code (item_code_snapshot was always null).
     * Fill it for lines that trace back to a catalog-backed work-order service line.
     * Lines without a catalog (manual / direct-sale) stay null.
     */
    public function up()
    {
        DB::statement(
            "UPDATE invoice_details d
             JOIN work_order_service_lines l ON l.id = d.work_order_service_line_id
             JOIN service_catalogs c ON c.id = l.service_catalog_id
             SET d.item_code_snapshot = c.code
             WHERE d.item_type = 'service' AND d.item_code_snapshot IS NULL"
        );
    }

    public function down()
    {
        // Not reversible: cannot tell backfilled codes apart from ones stored later.
    }
}
