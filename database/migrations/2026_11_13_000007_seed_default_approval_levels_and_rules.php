<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class SeedDefaultApprovalLevelsAndRules extends Migration
{
    /**
     * Starting configuration only: levels carry no users, so nothing can be submitted for
     * approval until an admin assigns approvers. Skipped when the admin already configured
     * something.
     */
    public function up()
    {
        if (DB::table('approval_levels')->exists() || DB::table('approval_rules')->exists()) {
            return;
        }

        $now = now();
        $levelIds = [];
        foreach (['Manager', 'Direktur', 'CEO'] as $index => $name) {
            $levelIds[$name] = DB::table('approval_levels')->insertGetId([
                'name' => $name,
                'sort_order' => $index + 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $rules = [
            [10000000, ['Manager']],
            [50000000, ['Manager', 'Direktur']],
            [null, ['Manager', 'Direktur', 'CEO']],
        ];
        foreach ($rules as $index => $rule) {
            $ruleId = DB::table('approval_rules')->insertGetId([
                'max_amount' => $rule[0],
                'sort_order' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($rule[1] as $levelName) {
                DB::table('approval_rule_levels')->insert([
                    'approval_rule_id' => $ruleId,
                    'approval_level_id' => $levelIds[$levelName],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down()
    {
        // Intentionally empty: admins may have edited the configuration since.
    }
}
