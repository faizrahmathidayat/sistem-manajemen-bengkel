<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class DeleteGlobalGrantsForBranchScopedPermissionCodes extends Migration
{
    public function up()
    {
        DB::table('user_permissions')->whereIn('permission_id', function ($query) {
            $query->select('permissions.id')
                ->from('permissions')
                ->join('menus', 'menus.id', '=', 'permissions.menu_id')
                ->where('menus.is_branch_scoped', true);
        })->delete();
    }

    public function down()
    {
        // Data cleanup migration — the deleted global grants predate branch-scoped
        // permissions and are not reconstructible.
    }
}
