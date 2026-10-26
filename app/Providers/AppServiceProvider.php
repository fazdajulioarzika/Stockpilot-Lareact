<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use App\Models\GoodsReceipt;
use App\Models\StockIssue;
use App\Policies\InventoryDocumentPolicy;
use App\Models\StockTransfer;
use App\Policies\TransferPolicy;
use App\Models\Stocktake;
use App\Policies\StocktakePolicy;
use App\Enums\Role;
use App\Models\PurchaseOrder;
use App\Policies\PurchaseOrderPolicy;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('manage-master-data', fn (User $user) => $user->isAdmin());
        Gate::policy(GoodsReceipt::class, InventoryDocumentPolicy::class);
        Gate::policy(StockIssue::class, InventoryDocumentPolicy::class);
        Gate::policy(StockTransfer::class, TransferPolicy::class);
        Gate::policy(Stocktake::class, StocktakePolicy::class);
        Gate::define('view-audit-log', fn (User $user) => $user->hasRole(Role::Admin, Role::Manager));
        Gate::define('manage-users', fn (User $user) => $user->isAdmin());
        Gate::define('view-valuation', fn (User $user) => $user->hasRole(Role::Admin, Role::Manager));
        Gate::policy(PurchaseOrder::class, PurchaseOrderPolicy::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(
            fn (): ?Password => app()->isProduction()
                ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
                : null,
        );
    }
}
