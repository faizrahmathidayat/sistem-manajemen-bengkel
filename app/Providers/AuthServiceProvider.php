<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        \App\Models\SparepartBranch::class => \App\Policies\SparepartBranchPolicy::class,
        \App\Models\WorkOrder::class => \App\Policies\WorkOrderPolicy::class,
        \App\Models\GoodsReceipt::class => \App\Policies\GoodsReceiptPolicy::class,
        \App\Models\StockAdjustment::class => \App\Policies\StockAdjustmentPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        Gate::before(function ($user, $ability, $arguments = []) {
            if (! method_exists($user, 'hasPermissionTo')) {
                return null;
            }

            // Record-level/branch-scoped authorization (e.g. $user->can('pkb.view', $pkb))
            // belongs in Policies, not this blanket permission-code gate. Deferring here
            // lets a Policy actually run instead of being short-circuited by the code check.
            if (! empty($arguments)) {
                return null;
            }

            return $user->hasPermissionTo($ability) ? true : null;
        });
    }
}
