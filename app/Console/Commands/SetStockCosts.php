<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\User;
use App\Services\StockService;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetStockCosts extends Command
{
    protected $signature = 'stockpilot:set-costs
        {file? : CSV berkolom sku,unit_cost}
        {--demo : Isi biaya contoh untuk semua produk (tidak untuk production)}
        {--user= : Email pelaksana (default: Admin aktif pertama)}
        {--force : Timpa nilai yang sudah ada}';

    protected $description = 'Atur biaya untuk saldo stok yang belum bernilai. Dicatat di ledger sebagai penilaian ulang.';

    public function handle(StockService $stock): int
    {
        if ($this->option('demo') && app()->isProduction()) {
            $this->error('Opsi --demo tidak boleh dipakai di production.');

            return self::FAILURE;
        }

        $user = $this->option('user')
            ? User::where('email', $this->option('user'))->first()
            : User::where('role', 'admin')->where('is_active', true)->orderBy('id')->first();

        if (!$user) {
            $this->error('Pengguna pelaksana tidak ditemukan.');

            return self::FAILURE;
        }

        $costs = $this->option('demo') ? $this->demoCosts() : $this->readCsv((string) $this->argument('file'));

        if ($costs === null) {
            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $changed = 0;
        $skipped = 0;
        $missing = [];

        foreach ($costs as $sku => $cost) {
            $product = Product::where('sku', $sku)->first();

            if (!$product) {
                $missing[] = $sku;

                continue;
            }

            $rows = InventoryStock::where('product_id', $product->id)->where('quantity', '>', 0)->pluck('id');

            foreach ($rows as $rowId) {
                $result = DB::transaction(function () use ($rowId, $product, $cost, $force, $stock, $user) {
                    $locked = InventoryStock::whereKey($rowId)->lockForUpdate()->first();

                    if (!$locked || bccomp((string) $locked->quantity, '0', 3) <= 0) {
                        return null;
                    }

                    if (bccomp((string) $locked->total_value, '0', 4) > 0 && !$force) {
                        return 'skipped';
                    }

                    $before = (string) $locked->total_value;
                    $new = Money::round4(bcmul((string) $locked->quantity, $cost, 7));
                    $movement = $stock->revalue($locked->warehouse_id, $product->id, $new, $product, $user->id);

                    if (!$movement) {
                        return null;
                    }

                    AuditLog::record('stock.revalued', $product, $user, [
                        'warehouse_id' => $locked->warehouse_id,
                        'unit_cost' => $cost,
                        'value' => [$before, $new],
                    ]);

                    return 'changed';
                });

                if ($result === 'changed') {
                    $changed++;
                } elseif ($result === 'skipped') {
                    $skipped++;
                }
            }
        }

        $this->info("Selesai: {$changed} saldo dinilai, {$skipped} dilewati (sudah bernilai, pakai --force untuk menimpa).");

        if ($missing !== []) {
            $this->warn('SKU tidak ditemukan: ' . implode(', ', $missing));
        }

        return self::SUCCESS;
    }

    /** @return array<string, string>|null */
    private function readCsv(string $path): ?array
    {
        if ($path === '' || !is_readable($path)) {
            $this->error('Berikan file CSV (sku,unit_cost) atau gunakan --demo.');

            return null;
        }

        $handle = fopen($path, 'r');
        $costs = [];
        $line = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;

            if ($row === [null] || count($row) < 2) {
                continue;
            }

            $sku = trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]));
            $cost = trim((string) $row[1]);

            if ($line === 1 && strtolower($sku) === 'sku') {
                continue;
            }

            if ($sku === '' || !preg_match('/^\d{1,9}(\.\d{1,4})?$/', $cost)) {
                fclose($handle);
                $this->error("Baris {$line} tidak valid: biaya harus angka tidak negatif, maksimal 4 desimal, titik sebagai pemisah desimal.");

                return null;
            }

            $costs[$sku] = $cost;
        }

        fclose($handle);

        return $costs;
    }

    /** @return array<string, string> */
    private function demoCosts(): array
    {
        return Product::orderBy('id')->get()
            ->mapWithKeys(fn (Product $p) => [$p->sku => (string) (2000 + (($p->id * 1370) % 48000))])
            ->all();
    }
}
