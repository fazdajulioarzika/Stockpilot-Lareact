<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\GoodsReceipt;
use App\Models\StockIssue;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    private const ENTITY_ROUTES = [
        GoodsReceipt::class => '/goods-receipts',
        StockIssue::class => '/stock-issues',
        StockTransfer::class => '/transfers',
        Stocktake::class => '/stocktakes',
    ];

    public function index(Request $request): Response
    {
        $action = trim((string) $request->query('action', ''));
        $userId = $request->integer('user_id') ?: null;
        $from = $request->date('from')?->startOfDay();
        $to = $request->date('to')?->endOfDay();

        $logs = AuditLog::query()
            ->with(['user:id,name', 'entity'])
            ->when($action !== '', fn ($q) => $q->where('action', $action))
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(function (AuditLog $l) {
                $entity = $l->entity;
                $name = $entity instanceof User ? $entity->name : ($entity?->number ?? '#' . $l->entity_id);

                return [
                    'id' => $l->id,
                    'created_at' => $l->created_at,
                    'action' => $l->action,
                    'user' => $l->user?->name,
                    'metadata' => $l->metadata ?? [],
                    'entity_label' => class_basename($l->entity_type) . ' ' . $name,
                    'entity_url' => isset(self::ENTITY_ROUTES[$l->entity_type])
                        ? self::ENTITY_ROUTES[$l->entity_type] . '/' . $l->entity_id
                        : null,
                ];
            });

        return Inertia::render('audit-logs/index', [
            'logs' => $logs,
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'users' => User::orderBy('name')->get(['id', 'name']),
            'filters' => [
                'action' => $action !== '' ? $action : 'all',
                'user_id' => $userId ? (string) $userId : 'all',
                'from' => $request->query('from', ''),
                'to' => $request->query('to', ''),
            ],
        ]);
    }
}
