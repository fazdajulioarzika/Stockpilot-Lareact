<?php

namespace Database\Seeders;

use App\Actions\Inventory\ApproveStockTransfer;
use App\Actions\Inventory\DispatchStockTransfer;
use App\Actions\Inventory\PostGoodsReceipt;
use App\Actions\Inventory\PostStockIssue;
use App\Actions\Inventory\PostStocktake;
use App\Actions\Inventory\ReceiveStockTransfer;
use App\Actions\Inventory\RecordStocktakeCount;
use App\Actions\Inventory\ReturnTransferRemainder;
use App\Actions\Inventory\StartStocktake;
use App\Actions\Inventory\SubmitStockTransfer;
use App\Actions\Inventory\SubmitStocktakeForApproval;
use App\Enums\Role;
use App\Enums\TransferStatus;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\GoodsReceipt;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\StockIssue;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Riwayat 3 bulan untuk demo. Jalankan manual:
 *   php artisan db:seed --class=DemoDataSeeder
 * Tidak mengubah user, gudang, produk, atau kategori yang sudah ada.
 * Seluruh proses satu transaksi: gagal berarti tidak ada yang tersimpan.
 */
class DemoDataSeeder extends Seeder
{
    private const TAG = 'Data demo';

    private const DAYS = 90;

    /** Indeks hari => urutan gudang aktif (0 = pertama, 1 = kedua, dst.). */
    private const STOCKTAKES = [28 => 0, 43 => 1, 58 => 0, 73 => 1];

    /** @var array<int, Warehouse> */
    private array $warehouses = [];

    /** @var array<int, User> pelaksana per id gudang */
    private array $staff = [];

    private User $approver;

    /** @var array<int, int> */
    private array $productIds = [];

    /** @var array<int, int> produk contoh yang dibuat seeder ini */
    private array $demoProductIds = [];

    /** @var array<int, int> */
    private array $supplierIds = [];

    /** @var array<string, array<int, int>> */
    private array $due = [];

    private Carbon $now;

    private Carbon $today;

    private bool $simulating = false;

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Data demo tidak dijalankan di production.');

            return;
        }

        if (GoodsReceipt::where('notes', self::TAG)->exists()) {
            $this->command?->warn('Data demo sudah pernah dibuat. Seeder dihentikan agar data tidak ganda.');

            return;
        }

        mt_srand(20261025);
        $this->today = Carbon::today();

        try {
            DB::transaction(function () {
                $this->prepare();

                $this->simulating = true;
                $this->simulate();
                $this->simulating = false;

                Carbon::setTestNow();
                CarbonImmutable::setTestNow();

                $this->finish();
            });
        } finally {
            $this->simulating = false;
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }

        $this->command?->info(sprintf(
            'Selesai: %d barang masuk, %d barang keluar, %d transfer, %d opname, %d mutasi, %d audit log.',
            GoodsReceipt::count(),
            StockIssue::count(),
            StockTransfer::count(),
            Stocktake::count(),
            StockMovement::count(),
            AuditLog::count(),
        ));
    }

    /** Membaca data yang ada. Hanya menambah produk contoh dan master pendukung yang belum ada. */
    private function prepare(): void
    {
        $this->warehouses = Warehouse::where('is_active', true)->orderBy('id')->limit(3)->get()->keyBy('id')->all();

        if ($this->warehouses === []) {
            throw new \RuntimeException('Tidak ada gudang aktif. Buat atau aktifkan gudang dulu.');
        }

        $busy = Stocktake::whereIn('warehouse_id', array_keys($this->warehouses))
            ->whereIn('status', ['counting', 'pending_approval'])
            ->exists();

        if ($busy) {
            throw new \RuntimeException('Ada opname aktif di salah satu gudang. Selesaikan atau batalkan dulu.');
        }

        $active = fn () => User::query()->where('is_active', true);
        $admin = $active()->where('role', Role::Admin->value)->orderBy('id')->first();

        foreach ($this->warehouses as $id => $warehouse) {
            $user = $active()
                ->where('role', Role::WarehouseStaff->value)
                ->whereHas('warehouses', fn ($q) => $q->where('warehouses.id', $id))
                ->orderBy('id')
                ->first() ?? $admin;

            if (!$user) {
                throw new \RuntimeException("Tidak ada staf atau Admin aktif untuk gudang {$warehouse->code}.");
            }

            $this->staff[$id] = $user;
        }

        $this->approver = $active()->where('role', Role::Manager->value)->orderBy('id')->first() ?? $admin
            ?? throw new \RuntimeException('Tidak ada Manager atau Admin aktif.');

        $categories = [];

        foreach (['Makanan', 'Minuman', 'Kebersihan', 'Alat Tulis'] as $name) {
            $slug = Str::slug($name);
            $categories[$name] = (Category::where('name', $name)->orWhere('slug', $slug)->first()
                ?? Category::create(['name' => $name, 'slug' => $slug]))->id;
        }

        $units = Unit::pluck('id', 'symbol');

        if ($units->isEmpty()) {
            $units = collect(['pcs' => Unit::create(['name' => 'Pieces', 'symbol' => 'pcs'])->id]);
        }

        $fallbackUnit = $units->first();

        $products = [
            ['SKU-001', 'Tepung Terigu', 'Makanan', 'kg', 50],
            ['SKU-002', 'Gula Pasir', 'Makanan', 'kg', 50],
            ['SKU-003', 'Minyak Goreng', 'Makanan', 'L', 40],
            ['SKU-004', 'Beras Premium', 'Makanan', 'kg', 100],
            ['SKU-005', 'Mie Instan', 'Makanan', 'box', 30],
            ['SKU-006', 'Teh Celup', 'Minuman', 'box', 20],
            ['SKU-007', 'Kopi Bubuk', 'Minuman', 'kg', 25],
            ['SKU-008', 'Air Mineral 600ml', 'Minuman', 'ctn', 40],
            ['SKU-009', 'Sirup Rasa Jeruk', 'Minuman', 'pcs', 30],
            ['SKU-010', 'Sabun Cuci Piring', 'Kebersihan', 'pcs', 30],
            ['SKU-011', 'Deterjen Bubuk', 'Kebersihan', 'box', 20],
            ['SKU-012', 'Tisu Gulung', 'Kebersihan', 'ctn', 25],
            ['SKU-013', 'Pulpen Hitam', 'Alat Tulis', 'box', 15],
            ['SKU-014', 'Kertas A4', 'Alat Tulis', 'ctn', 20],
        ];

        // SKU yang sudah ada tidak disentuh sama sekali.
        foreach ($products as [$sku, $name, $category, $unit, $min]) {
            if (Product::where('sku', $sku)->exists()) {
                continue;
            }

            $this->demoProductIds[] = Product::create([
                'sku' => $sku,
                'name' => $name,
                'category_id' => $categories[$category],
                'unit_id' => $units[$unit] ?? $fallbackUnit,
                'min_stock' => $min,
                'is_active' => true,
            ])->id;
        }

        foreach (['PT Sumber Pangan Makmur', 'CV Berkah Abadi', 'PT Nusantara Distribusi', 'UD Maju Jaya'] as $i => $name) {
            Supplier::firstOrCreate(['name' => $name], [
                'email' => Str::slug($name) . '@supplier.test',
                'phone' => '0812000000' . ($i + 1),
                'address' => 'Alamat ' . $name,
                'is_active' => true,
            ]);
        }

        $this->productIds = Product::where('is_active', true)->orderBy('id')->pluck('id')->all();
        $this->supplierIds = Supplier::where('is_active', true)->pluck('id')->all();
    }

    private function simulate(): void
    {
        $start = $this->today->copy()->subDays(self::DAYS);

        for ($i = 0; $i < self::DAYS; $i++) {
            $day = $start->copy()->addDays($i);

            if ($day->isSunday()) {
                continue;
            }

            $this->now = $day->copy()->setTime(8, 0);

            $this->processDueTransfers($day);

            if ($i === 0) {
                $this->openingStock();
            } else {
                $this->dailyReceipts();
                $this->dailyIssues();
                $this->maybeTransfer($day);
            }

            $this->maybeStocktake($i);
        }
    }

    /** Majukan jam simulasi. Hanya aktif selama simulasi. */
    private function advance(): void
    {
        if (!$this->simulating) {
            return;
        }

        $this->now = $this->now->copy()->addMinutes(mt_rand(3, 20));
        Carbon::setTestNow($this->now->copy());
        CarbonImmutable::setTestNow($this->now->copy());
    }

    /** @return array<int, int> */
    private function pickProducts(int $count): array
    {
        $ids = $this->productIds;
        shuffle($ids);

        return array_slice($ids, 0, $count);
    }

    /** @return array<int, float> id produk => saldo */
    private function balance(int $warehouseId): array
    {
        return InventoryStock::where('warehouse_id', $warehouseId)
            ->pluck('quantity', 'product_id')
            ->map(fn ($q) => (float) $q)
            ->all();
    }

    private function supplier(): ?int
    {
        return $this->supplierIds === [] ? null : $this->supplierIds[array_rand($this->supplierIds)];
    }

    private function openingStock(): void
    {
        foreach ($this->warehouses as $warehouse) {
            $staff = $this->staff[$warehouse->id];
            $this->advance();

            $receipt = GoodsReceipt::create([
                'warehouse_id' => $warehouse->id,
                'supplier_id' => $this->supplier(),
                'reference' => 'SALDO-AWAL',
                'notes' => self::TAG,
                'created_by' => $staff->id,
            ]);

            foreach ($this->productIds as $productId) {
                $receipt->items()->create(['product_id' => $productId, 'quantity' => mt_rand(80, 200), 'unit_cost' => $this->unitCost($productId)]);
            }

            app(PostGoodsReceipt::class)->execute($receipt, $staff);
        }
    }

    private function dailyReceipts(): void
    {
        foreach ($this->warehouses as $warehouse) {
            if (mt_rand(1, 100) > 30) {
                continue;
            }

            $staff = $this->staff[$warehouse->id];
            $this->advance();

            $receipt = GoodsReceipt::create([
                'warehouse_id' => $warehouse->id,
                'supplier_id' => $this->supplier(),
                'reference' => 'PO-' . now()->format('ymd') . '-' . mt_rand(100, 999),
                'notes' => self::TAG,
                'created_by' => $staff->id,
            ]);

            foreach ($this->pickProducts(mt_rand(2, 4)) as $productId) {
                $receipt->items()->create(['product_id' => $productId, 'quantity' => mt_rand(30, 120), 'unit_cost' => $this->unitCost($productId)]);
            }

            app(PostGoodsReceipt::class)->execute($receipt, $staff);
        }
    }

    private function dailyIssues(): void
    {
        $reasons = ['Penjualan', 'Penjualan', 'Penjualan', 'Sampel', 'Barang rusak', 'Pemakaian internal'];

        foreach ($this->warehouses as $warehouse) {
            $staff = $this->staff[$warehouse->id];

            for ($d = mt_rand(1, 3); $d > 0; $d--) {
                $balance = $this->balance($warehouse->id);
                $lines = [];

                foreach ($this->pickProducts(mt_rand(1, 3)) as $productId) {
                    // Maksimal setengah stok tersedia, supaya tidak pernah ditolak.
                    $qty = min(mt_rand(1, 12), (int) floor(($balance[$productId] ?? 0) * 0.5));

                    if ($qty >= 1) {
                        $lines[$productId] = $qty;
                    }
                }

                if ($lines === []) {
                    continue;
                }

                $this->advance();

                $issue = StockIssue::create([
                    'warehouse_id' => $warehouse->id,
                    'reason' => $reasons[array_rand($reasons)],
                    'reference' => 'REQ-' . now()->format('ymd') . '-' . mt_rand(100, 999),
                    'notes' => self::TAG,
                    'created_by' => $staff->id,
                ]);

                foreach ($lines as $productId => $qty) {
                    $issue->items()->create(['product_id' => $productId, 'quantity' => $qty]);
                }

                app(PostStockIssue::class)->execute($issue, $staff);
            }
        }
    }

    /** @param array<int, int> $lines id produk => jumlah */
    private function createTransfer(int $from, int $to, array $lines): StockTransfer
    {
        $staff = $this->staff[$from];
        $this->advance();

        $transfer = StockTransfer::create([
            'source_warehouse_id' => $from,
            'destination_warehouse_id' => $to,
            'reference' => 'TRF-' . now()->format('ymd') . '-' . mt_rand(100, 999),
            'notes' => self::TAG,
            'created_by' => $staff->id,
        ]);

        foreach ($lines as $productId => $qty) {
            $transfer->items()->create(['product_id' => $productId, 'quantity' => $qty]);
        }

        app(SubmitStockTransfer::class)->execute($transfer, $staff);

        if ($transfer->fresh()->status === TransferStatus::PendingApproval) {
            $this->advance();
            app(ApproveStockTransfer::class)->execute($transfer, $this->approver);
        }

        return $transfer->fresh();
    }

    /** @return array<int, int> */
    private function transferLines(int $from, int $count, int $min, int $max): array
    {
        $balance = $this->balance($from);
        $lines = [];

        foreach ($this->pickProducts($count) as $productId) {
            $qty = min(mt_rand($min, $max), (int) floor(($balance[$productId] ?? 0) * 0.3));

            if ($qty >= 1) {
                $lines[$productId] = $qty;
            }
        }

        return $lines;
    }

    private function maybeTransfer(Carbon $day): void
    {
        $ids = array_keys($this->warehouses);

        // Selasa dan Kamis, dan butuh minimal dua gudang.
        if (count($ids) < 2 || !in_array($day->dayOfWeekIso, [2, 4], true) || mt_rand(1, 100) > 60) {
            return;
        }

        shuffle($ids);
        [$from, $to] = $ids;

        $lines = $this->transferLines($from, mt_rand(1, 3), 10, 40);

        if ($lines === []) {
            return;
        }

        $transfer = $this->createTransfer($from, $to, $lines);

        $this->advance();
        app(DispatchStockTransfer::class)->execute($transfer, $this->staff[$from]);

        $due = $day->copy()->addDays(mt_rand(1, 3));

        if ($due->isSunday()) {
            $due->addDay();
        }

        // Yang jatuh temponya belum lewat dibiarkan dalam perjalanan.
        if ($due->lt($this->today)) {
            $this->due[$due->toDateString()][] = $transfer->id;
        }
    }

    private function processDueTransfers(Carbon $day): void
    {
        foreach ($this->due[$day->toDateString()] ?? [] as $id) {
            $this->receiveTransfer($id);
        }
    }

    private function receiveTransfer(int $id): void
    {
        $transfer = StockTransfer::with('items')->findOrFail($id);
        $partial = mt_rand(1, 100) <= 15;
        $lines = [];

        foreach ($transfer->items as $item) {
            $remaining = (float) $item->remaining();
            $qty = ($partial && $remaining >= 2) ? floor($remaining * 0.6) : $remaining;

            $lines[] = ['item_id' => $item->id, 'quantity' => number_format($qty, 3, '.', '')];
        }

        $this->advance();

        app(ReceiveStockTransfer::class)->execute(
            $transfer,
            $this->staff[$transfer->destination_warehouse_id],
            $lines,
            (string) Str::uuid(),
        );

        // Penerimaan sebagian: sisanya dikembalikan ke gudang sumber.
        if ($transfer->fresh()->status === TransferStatus::PartiallyReceived) {
            $this->advance();

            app(ReturnTransferRemainder::class)->execute(
                $transfer->fresh(),
                $this->staff[$transfer->source_warehouse_id],
            );
        }
    }

    private function maybeStocktake(int $dayIndex): void
    {
        $position = self::STOCKTAKES[$dayIndex] ?? null;

        if ($position === null) {
            return;
        }

        $list = array_values($this->warehouses);

        $this->runStocktake($list[$position % count($list)]);
    }

    private function runStocktake(Warehouse $warehouse): void
    {
        $staff = $this->staff[$warehouse->id];
        $this->advance();

        $stocktake = Stocktake::create([
            'warehouse_id' => $warehouse->id,
            'notes' => self::TAG,
            'created_by' => $staff->id,
        ]);

        try {
            app(StartStocktake::class)->execute($stocktake, $staff);
        } catch (DomainException) {
            $stocktake->delete(); // tidak ada produk bersaldo

            return;
        }

        $items = $stocktake->items()->get();
        $ids = $items->pluck('id')->all();
        shuffle($ids);
        $withDifference = array_slice($ids, 0, 2);

        $lines = [];

        foreach ($items as $item) {
            $system = (float) $item->system_qty;
            $counted = $system;
            $reason = null;

            if (in_array($item->id, $withDifference, true) && $system > 0) {
                if (mt_rand(0, 1) === 0) {
                    $counted = max(0, $system - mt_rand(1, 3));
                    $reason = 'Barang rusak atau hilang';
                } else {
                    $counted = $system + mt_rand(1, 2);
                    $reason = 'Selisih pencatatan';
                }
            }

            $lines[] = [
                'item_id' => $item->id,
                'counted_qty' => number_format($counted, 3, '.', ''),
                'reason' => $counted === $system ? null : $reason,
            ];
        }

        $this->advance();
        app(RecordStocktakeCount::class)->execute($stocktake, $staff, $lines);

        $this->advance();
        app(SubmitStocktakeForApproval::class)->execute($stocktake, $staff);

        $this->advance();
        app(PostStocktake::class)->execute($stocktake, $this->approver);
    }

    /** Dijalankan dengan waktu nyata: pekerjaan yang masih menunggu hari ini. */
    private function finish(): void
    {
        // Hanya produk contoh yang dibuat seeder ini yang diubah stok minimumnya.
        if ($this->demoProductIds !== []) {
            InventoryStock::query()
                ->whereIn('product_id', $this->demoProductIds)
                ->where('quantity', '>', 0)
                ->orderBy('quantity')
                ->limit(3)
                ->with('product')
                ->get()
                ->each(fn (InventoryStock $s) => $s->product->update([
                    'min_stock' => (int) ceil((float) $s->quantity) + 5,
                ]));
        }

        $ids = array_keys($this->warehouses);
        $first = $ids[0];
        $second = $ids[1] ?? $first;

        $draft = GoodsReceipt::create([
            'warehouse_id' => $first,
            'supplier_id' => $this->supplier(),
            'reference' => 'PO-DRAFT-1',
            'notes' => self::TAG,
            'created_by' => $this->staff[$first]->id,
        ]);

        foreach ($this->pickProducts(3) as $productId) {
            $draft->items()->create(['product_id' => $productId, 'quantity' => 50]);
        }

        $issue = StockIssue::create([
            'warehouse_id' => $second,
            'reason' => 'Penjualan',
            'reference' => 'REQ-DRAFT-1',
            'notes' => self::TAG,
            'created_by' => $this->staff[$second]->id,
        ]);

        foreach ($this->transferLines($second, 2, 2, 5) as $productId => $qty) {
            $issue->items()->create(['product_id' => $productId, 'quantity' => $qty]);
        }

        if (count($ids) > 1) {
            foreach ([[$first, $second], [$second, $first]] as [$from, $to]) {
                $lines = $this->transferLines($from, 2, 5, 10);

                if ($lines !== []) {
                    $this->createTransfer($from, $to, $lines);
                }
            }
        }
    }
    private function unitCost(int $productId): string
    {
        return (string) (2000 + (($productId * 1370) % 48000));
    }
}
