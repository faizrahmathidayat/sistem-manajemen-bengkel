<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

trait HasAudit
{
    public static function bootHasAudit()
    {
        static::creating(function ($model) {
            if (Auth::check()) {
                if (! $model->created_by) {
                    $model->created_by = Auth::id();
                }
                if (! $model->updated_by) {
                    $model->updated_by = Auth::id();
                }
            }
        });

        static::updating(function ($model) {
            if (Auth::check()) {
                $model->updated_by = Auth::id();
            }
        });
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
