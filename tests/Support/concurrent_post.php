<?php

// Dipanggil oleh tes konkurensi sebagai proses terpisah.
// Pemakaian: php concurrent_post.php <receipt|issue|start_stocktake> <id> <userId> <startAt>

use App\Actions\Inventory\PostGoodsReceipt;
use App\Actions\Inventory\PostStockIssue;
use App\Actions\Inventory\StartStocktake;
use App\Models\GoodsReceipt;
use App\Models\StockIssue;
use App\Models\Stocktake;
use App\Models\User;

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[, $kind, $id, $userId, $startAt] = $argv;

$user = User::findOrFail((int) $userId);

// Semua proses menunggu waktu yang sama supaya benar-benar bersamaan.
$wait = (float) $startAt - microtime(true);

if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

try {
    match ($kind) {
        'receipt' => app(PostGoodsReceipt::class)->execute(GoodsReceipt::findOrFail((int) $id), $user),
        'issue' => app(PostStockIssue::class)->execute(StockIssue::findOrFail((int) $id), $user),
        'start_stocktake' => app(StartStocktake::class)->execute(Stocktake::findOrFail((int) $id), $user),
    };

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => class_basename($e), 'message' => $e->getMessage()]);
}
