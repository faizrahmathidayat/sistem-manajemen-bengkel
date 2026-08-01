<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserBranchPermission extends Model
{
    protected $fillable = ['user_id', 'branch_id', 'permission_id', 'granted_by'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function permission()
    {
        return $this->belongsTo(Permission::class);
    }

    public function granter()
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
