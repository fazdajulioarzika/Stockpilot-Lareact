<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use App\Enums\StocktakeStatus;
use App\Models\Stocktake;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => !$request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'abilities' => fn () => $request->user() ? [
                'manageMasterData' => $request->user()->can('manage-master-data'),
                'viewAudit' => $request->user()->can('view-audit-log'),
                'manageUsers' => $request->user()->can('manage-users'),
                'viewValuation' => $request->user()->can('view-valuation'),
            ] : null,
            'lockedWarehouses' => function () use ($request) {
                $user = $request->user();

                if (!$user) {
                    return [];
                }

                $ids = $user->accessibleWarehouseIds(); // null = semua gudang

                return Stocktake::query()
                    ->with('warehouse:id,code')
                    ->whereIn('status', array_map(fn (StocktakeStatus $s) => $s->value, StocktakeStatus::locking()))
                    ->when($ids !== null, fn ($q) => $q->whereIn('warehouse_id', $ids))
                    ->get(['id', 'number', 'warehouse_id'])
                    ->map(fn (Stocktake $s) => [
                        'id' => $s->id,
                        'number' => $s->number,
                        'code' => $s->warehouse->code,
                    ])
                    ->values()
                    ->all();
            },
        ];
    }
}
