<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateUserBranchesTable extends Migration
{
    public function up()
    {
        Schema::create('user_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'branch_id']);
        });

        // MySQL has no partial unique index ("WHERE is_default = true"), so we emulate
        // it with a generated column that is NULL unless the row is an active default —
        // MySQL unique indexes allow unlimited NULLs, so only one non-NULL (i.e. one
        // active default) per user is permitted.
        DB::statement(
            'ALTER TABLE user_branches ADD COLUMN default_marker TINYINT(1) '
            . 'GENERATED ALWAYS AS (CASE WHEN is_default = 1 AND is_active = 1 THEN 1 ELSE NULL END) STORED'
        );
        DB::statement(
            'ALTER TABLE user_branches ADD UNIQUE INDEX uq_user_default_branch (user_id, default_marker)'
        );
    }

    public function down()
    {
        Schema::dropIfExists('user_branches');
    }
}
