<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class BackfillServiceItemCodeByCatalogName extends Migration
{
    /**
     * Second backfill pass for service lines the work-order trace could not cover (direct sales and
     * lines added in the invoice editor). The editor's catalog picker copies the catalog name into
     * the line description, so an exact name match (case/outer-whitespace insensitive) identifies the
     * catalog entry. Catalog names are unique; non-matching or manually typed lines stay blank.
     */
    public function up()
    {
        DB::statement(
            "UPDATE invoice_details d
             JOIN service_catalogs c ON LOWER(TRIM(c.name)) = LOWER(TRIM(d.description))
             SET d.item_code_snapshot = c.code
             WHERE d.item_type = 'service' AND d.item_code_snapshot IS NULL"
        );
    }

    public function down()
    {
        // Not reversible: cannot tell backfilled codes apart from ones stored later.
    }
}
