<?php

namespace Database\Seeders;

use App\Actions\Inventory\ApproveStockTransfer;
use App\Actions\Inventory\CancelStockTransfer;
use App\Actions\Inventory\DispatchStockTransfer;
use App\Actions\Inventory\PostGoodsReceipt;
use App\Actions\Inventory\PostStockIssue;
use App\Actions\Inventory\PostStocktake;
use App\Actions\Inventory\ReceiveStockTransfer;
use App\Actions\Inventory\RecordStocktakeCount;
use App\Actions\Inventory\RejectStocktake;
use App\Actions\Inventory\ReturnTransferRemainder;
use App\Actions\Inventory\ReverseStockDocument;
use App\Actions\Inventory\StartStocktake;
use App\Actions\Inventory\SubmitStockTransfer;
use App\Actions\Inventory\SubmitStocktakeForApproval;
use App\Actions\Purchasing\ApprovePurchaseOrder;
use App\Actions\Purchasing\CancelPurchaseOrder;
use App\Actions\Purchasing\ClosePurchaseOrder;
use App\Actions\Purchasing\CreateReceiptFromPurchaseOrder;
use App\Actions\Purchasing\SubmitPurchaseOrder;
use App\Enums\PurchaseOrderStatus;
use App\Enums\Role;
use App\Enums\TransferStatus;
use App\Models\Category;
use App\Models\GoodsReceipt;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockIssue;
use App\Models\StockMovement;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Riwayat operasional beberapa bulan yang tampak nyata. Dijalankan lewat:
 *   php artisan stockpilot:reset-demo
 * Semua mutasi dibuat lewat action aplikasi, jadi saldo, nilai, ledger, dan audit konsisten.
 * Seluruh proses satu transaksi: gagal berarti tidak ada yang tersimpan.
 */
class RealisticDemoSeeder extends Seeder
{
    /** Jumlah hari riwayat. Tes memakai nilai lebih kecil. */
    public static int $days = 90;

    /** [kode, nama, alamat, skala permintaan, email staf, nama staf] */
    private const WAREHOUSES = [
        ['GD-JKT', 'Gudang Jakarta', 'Jl. Raya Cakung Cilincing, Jakarta Utara', 1.0, 'staff@stockpilot.test', 'Staff Jakarta'],
        ['GD-SBY', 'Gudang Surabaya', 'Jl. Rungkut Industri, Surabaya', 0.7, 'staff.sby@stockpilot.test', 'Staff Surabaya'],
        ['GD-BDG', 'Gudang Bandung', 'Jl. Soekarno-Hatta, Bandung', 0.5, 'staff.bdg@stockpilot.test', 'Staff Bandung'],
    ];

    /** [nama, telepon, kota] */
    private const SUPPLIERS = [
        ['PT Sumber Pangan Nusantara', '0215550101', 'Jakarta'],
        ['CV Mitra Sembako Jaya', '0315550102', 'Surabaya'],
        ['PT Tirta Segar Abadi', '0215550103', 'Bekasi'],
        ['UD Berkah Snack', '0225550104', 'Bandung'],
        ['PT Bersih Sejahtera', '0215550105', 'Tangerang'],
        ['CV Karya ATK Mandiri', '0215550106', 'Jakarta'],
    ];

    /** [sku, nama, kategori, satuan, harga beli dasar, permintaan harian di Jakarta, supplier] */
    private const PRODUCTS = [
        ['BRS-001', 'Beras Premium 5 kg', 'Sembako', 'pcs', 68000, 14, 'PT Sumber Pangan Nusantara'],
        ['GUL-001', 'Gula Pasir 1 kg', 'Sembako', 'pcs', 16500, 30, 'PT Sumber Pangan Nusantara'],
        ['MYK-001', 'Minyak Goreng 2 L', 'Sembako', 'pcs', 37000, 28, 'PT Sumber Pangan Nusantara'],
        ['TPG-001', 'Tepung Terigu 1 kg', 'Sembako', 'pcs', 11500, 20, 'PT Sumber Pangan Nusantara'],
        ['GRM-001', 'Garam Beryodium 250 g', 'Sembako', 'pcs', 3500, 25, 'PT Sumber Pangan Nusantara'],
        ['TLR-001', 'Telur Ayam', 'Sembako', 'kg', 28500, 40, 'CV Mitra Sembako Jaya'],
        ['KCP-001', 'Kecap Manis 600 ml', 'Sembako', 'pcs', 21000, 12, 'CV Mitra Sembako Jaya'],
        ['MIE-001', 'Mie Instan Goreng (isi 40)', 'Sembako', 'ctn', 105000, 6, 'CV Mitra Sembako Jaya'],
        ['SRD-001', 'Sarden Kaleng 155 g (isi 24)', 'Sembako', 'ctn', 168000, 2, 'CV Mitra Sembako Jaya'],
        ['AIR-001', 'Air Mineral 600 ml (isi 24)', 'Minuman', 'ctn', 38000, 9, 'PT Tirta Segar Abadi'],
        ['AIR-002', 'Air Mineral Galon 19 L', 'Minuman', 'pcs', 19000, 12, 'PT Tirta Segar Abadi'],
        ['TEH-001', 'Teh Celup (25 kantong)', 'Minuman', 'box', 9500, 10, 'PT Tirta Segar Abadi'],
        ['KOP-001', 'Kopi Bubuk 200 g', 'Minuman', 'pcs', 16000, 12, 'PT Tirta Segar Abadi'],
        ['SUS-001', 'Susu UHT 1 L (isi 12)', 'Minuman', 'ctn', 190000, 3, 'PT Tirta Segar Abadi'],
        ['SRP-001', 'Sirup Rasa Jeruk 460 ml', 'Minuman', 'pcs', 17500, 8, 'PT Tirta Segar Abadi'],
        ['TBT-001', 'Minuman Teh Botol 350 ml (isi 24)', 'Minuman', 'ctn', 62000, 5, 'PT Tirta Segar Abadi'],
        ['BSK-001', 'Biskuit Kelapa 300 g', 'Makanan Ringan', 'pcs', 12500, 15, 'UD Berkah Snack'],
        ['KRP-001', 'Keripik Singkong 200 g', 'Makanan Ringan', 'pack', 9000, 14, 'UD Berkah Snack'],
        ['WFR-001', 'Wafer Cokelat (isi 20)', 'Makanan Ringan', 'box', 24000, 7, 'UD Berkah Snack'],
        ['PMN-001', 'Permen Mint (isi 50)', 'Makanan Ringan', 'pack', 11000, 5, 'UD Berkah Snack'],
        ['DTR-001', 'Deterjen Bubuk 800 g', 'Perawatan Rumah', 'pcs', 18500, 14, 'PT Bersih Sejahtera'],
        ['SCP-001', 'Sabun Cuci Piring 800 ml', 'Perawatan Rumah', 'pcs', 14500, 12, 'PT Bersih Sejahtera'],
        ['PLT-001', 'Pembersih Lantai 1 L', 'Perawatan Rumah', 'pcs', 17000, 8, 'PT Bersih Sejahtera'],
        ['TIS-001', 'Tisu Gulung (isi 10)', 'Perawatan Rumah', 'pack', 21000, 10, 'PT Bersih Sejahtera'],
        ['SBN-001', 'Sabun Mandi Batang 85 g (isi 12)', 'Perawatan Pribadi', 'box', 26000, 8, 'PT Bersih Sejahtera'],
        ['SMP-001', 'Sampo Sachet (isi 12 renceng)', 'Perawatan Pribadi', 'box', 17500, 6, 'PT Bersih Sejahtera'],
        ['PST-001', 'Pasta Gigi 120 g', 'Perawatan Pribadi', 'pcs', 11500, 13, 'PT Bersih Sejahtera'],
        ['SKG-001', 'Sikat Gigi Dewasa (isi 12)', 'Perawatan Pribadi', 'box', 32000, 4, 'PT Bersih Sejahtera'],
        ['KRT-001', 'Kertas A4 70 gsm (5 rim)', 'ATK', 'box', 165000, 3, 'CV Karya ATK Mandiri'],
        ['PLP-001', 'Pulpen Hitam (isi 12)', 'ATK', 'box', 19000, 4, 'CV Karya ATK Mandiri'],
        ['BKT-001', 'Buku Tulis 38 Lembar (isi 10)', 'ATK', 'pack', 28000, 5, 'CV Karya ATK Mandiri'],
    ];

    private const CUSTOMERS = [
        'Toko Makmur Jaya', 'Warung Bu Sari', 'Minimarket Sentosa', 'Toko Sumber Rejeki',
        'Kantin Sekolah Harapan', 'Koperasi Karyawan Mandiri', 'Warung Pak Haji', 'Toko Berkah Sejahtera',
        'Resto Selera Nusantara', 'Hotel Melati Indah', 'Katering Dapur Ibu', 'Toko Sinar Abadi',
    ];

    private const LOSS_REASONS = ['Barang rusak', 'Kemasan bocor', 'Kedaluwarsa', 'Selisih pencatatan', 'Barang hilang'];

    private const GAIN_REASONS = ['Ditemukan di rak lain', 'Selisih pencatatan', 'Retur belum tercatat'];

    /** [pecahan periode, posisi gudang, ditolak dulu?] */
    private const STOCKTAKE_PLAN = [
        [0.27, 0, false], [0.30, 1, false], [0.32, 2, false],
        [0.60, 0, true], [0.63, 1, false], [0.66, 2, false],
        [0.94, 0, false], [0.96, 1, false], [0.98, 2, false],
    ];

    private User $admin;

    private User $manager;

    /** @var array<int, array{id: int, code: string, scale: float, staff: User}> */
    private array $warehouses = [];

    /** @var array<int, int> id gudang berurutan */
    private array $warehouseIds = [];

    /** @var array<int, array{id: int, name: string, cost: int, daily: int, supplier: string}> */
    private array $products = [];

    /** @var array<string, int> */
    private array $supplierIds = [];

    /** @var array<string, array<int, Closure>> */
    private array $queue = [];

    private Carbon $today;

    private Carbon $day;

    private Carbon $lastDay;

    private Carbon $clock;

    private int $dayIndex = 0;

    private int $counter = 0;

    private bool $simulating = false;

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Data demo tidak dijalankan di production.');

            return;
        }

        if (StockMovement::query()->exists()) {
            $this->command?->warn('Sudah ada mutasi stok. Gunakan php artisan stockpilot:reset-demo untuk memulai dari kosong.');

            return;
        }

        mt_srand(20261027);
        $this->today = Carbon::today();

        try {
            DB::transaction(function () {
                $this->prepare();

                $this->simulating = true;
                $this->simulate();
                $this->simulating = false;

                Carbon::setTestNow();
                CarbonImmutable::setTestNow();

                $this->finalState();
            });
        } finally {
            $this->simulating = false;
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }

        $this->command?->info(sprintf(
            'Selesai: %d produk, %d barang masuk, %d barang keluar, %d PO, %d transfer, %d opname, %d mutasi.',
            Product::count(),
            GoodsReceipt::count(),
            StockIssue::count(),
            PurchaseOrder::count(),
            StockTransfer::count(),
            Stocktake::count(),
            StockMovement::count(),
        ));
    }

    // ---------------------------------------------------------------- persiapan

    private function prepare(): void
    {
        $this->admin = User::where('role', Role::Admin->value)->where('is_active', true)->orderBy('id')->first()
            ?? throw new \RuntimeException('Tidak ada Admin aktif. Buat akun Admin dulu.');

        $this->manager = User::where('role', Role::Manager->value)->where('is_active', true)->orderBy('id')->first()
            ?? $this->admin;

        $units = [];

        foreach (['pcs' => 'Pieces', 'kg' => 'Kilogram', 'ctn' => 'Karton', 'box' => 'Box', 'pack' => 'Pack', 'L' => 'Liter'] as $symbol => $name) {
            $units[$symbol] = Unit::firstOrCreate(['symbol' => $symbol], ['name' => $name])->id;
        }

        $categories = [];

        foreach (self::PRODUCTS as [,, $category]) {
            $categories[$category] ??= Category::firstOrCreate(['name' => $category], ['slug' => Str::slug($category)])->id;
        }

        foreach (self::SUPPLIERS as [$name, $phone, $city]) {
            $this->supplierIds[$name] = Supplier::firstOrCreate(['name' => $name], [
                'email' => Str::slug($name) . '@supplier.test',
                'phone' => $phone,
                'address' => "Kawasan industri {$city}",
                'is_active' => true,
            ])->id;
        }

        foreach (self::WAREHOUSES as [$code, $name, $address, $scale, $email, $staffName]) {
            $warehouse = Warehouse::firstOrCreate(['code' => $code], ['name' => $name, 'address' => $address, 'is_active' => true]);

            // Akun yang sudah ada tidak ditimpa; hanya penugasan gudangnya dipulihkan.
            $user = User::firstOrNew(['email' => $email]);

            if (!$user->exists) {
                $user->forceFill([
                    'name' => $staffName,
                    'password' => Hash::make('password'),
                    'role' => Role::WarehouseStaff,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ])->save();
            }

            $user->warehouses()->syncWithoutDetaching([$warehouse->id]);

            $this->warehouseIds[] = $warehouse->id;
            $this->warehouses[$warehouse->id] = ['id' => $warehouse->id, 'code' => $code, 'scale' => $scale, 'staff' => $user];
        }

        foreach (self::PRODUCTS as [$sku, $name, $category, $unit, $cost, $daily, $supplier]) {
            $product = Product::firstOrCreate(['sku' => $sku], [
                'name' => $name,
                'category_id' => $categories[$category],
                'unit_id' => $units[$unit],
                'min_stock' => max(5, (int) (round($daily * 0.5 * 7 / 5) * 5)),
                'is_active' => true,
            ]);

            $this->products[$product->id] = [
                'id' => $product->id,
                'name' => $name,
                'cost' => $cost,
                'daily' => $daily,
                'supplier' => $supplier,
            ];
        }
    }

    // ---------------------------------------------------------------- jam simulasi

    private function setClock(Carbon $clock): void
    {
        $this->clock = $clock;
        Carbon::setTestNow($clock->copy());
        CarbonImmutable::setTestNow($clock->copy());
    }

    private function tick(int $min = 2, int $max = 8): void
    {
        if (!$this->simulating) {
            return;
        }

        $next = $this->clock->copy()->addMinutes(mt_rand($min, $max));

        if ($next->hour >= 22) {
            $next = $next->copy()->setTime(22, 0);
        }

        $this->setClock($next);
    }

    /** Jadwalkan pekerjaan pada hari kerja berikutnya. Yang jatuh setelah periode simulasi dibiarkan menggantung. */
    private function later(int $days, Closure $callback): void
    {
        $date = $this->day->copy()->addDays($days);

        if ($date->isSunday()) {
            $date->addDay();
        }

        if ($date->gt($this->lastDay)) {
            return;
        }

        $this->queue[$date->toDateString()][] = $callback;
    }

    // ---------------------------------------------------------------- simulasi

    private function simulate(): void
    {
        $start = $this->today->copy()->subDays(self::$days);
        $this->lastDay = $start->copy()->addDays(self::$days - 1);

        $plan = [];

        foreach (self::STOCKTAKE_PLAN as [$fraction, $position, $reject]) {
            $index = (int) floor(self::$days * $fraction);

            if ($index < 1 || $index >= self::$days) {
                continue;
            }

            $date = $start->copy()->addDays($index);

            if ($date->isSunday()) {
                $date->addDay();
            }

            if ($date->lte($this->lastDay)) {
                $plan[$date->toDateString()][] = [$position, $reject];
            }
        }

        for ($i = 0; $i < self::$days; $i++) {
            $day = $start->copy()->addDays($i);

            if ($day->isSunday()) {
                continue;
            }

            $this->day = $day;
            $this->dayIndex = $i;
            $this->setClock($day->copy()->setTime(8, 0));

            $key = $day->toDateString();
            $events = $this->queue[$key] ?? [];
            unset($this->queue[$key]);

            foreach ($events as $event) {
                $event();
            }

            if ($i === 0) {
                $this->openingStock();
            } else {
                foreach ($this->warehouseIds as $warehouseId) {
                    $this->dailyIssues($warehouseId);

                    if ($day->dayOfWeekIso === 3 && mt_rand(1, 100) <= 50) {
                        $this->adhocReceipt($warehouseId);
                    }

                    if (in_array($day->dayOfWeekIso, [1, 4], true)) {
                        $this->reorder($warehouseId);
                    }
                }

                if (in_array($day->dayOfWeekIso, [2, 5], true)) {
                    $this->maybeTransfer();
                }
            }

            foreach ($plan[$key] ?? [] as [$position, $reject]) {
                $this->runStocktake($position, $reject);
            }
        }
    }

    // ---------------------------------------------------------------- pembantu

    /** @return array<int, float> id produk => saldo */
    private function balance(int $warehouseId): array
    {
        return InventoryStock::where('warehouse_id', $warehouseId)
            ->pluck('quantity', 'product_id')
            ->map(fn ($q) => (float) $q)
            ->all();
    }

    /** @return array<int, float> pesanan yang belum diterima per produk */
    private function onOrder(int $warehouseId): array
    {
        return PurchaseOrderItem::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.warehouse_id', $warehouseId)
            ->whereIn('purchase_orders.status', ['submitted', 'approved', 'partially_received'])
            ->selectRaw('purchase_order_items.product_id AS pid, SUM(purchase_order_items.quantity - purchase_order_items.qty_received) AS qty')
            ->groupBy('purchase_order_items.product_id')
            ->pluck('qty', 'pid')
            ->map(fn ($q) => (float) $q)
            ->all();
    }

    private function dayFactor(): float
    {
        $factor = match ($this->day->dayOfWeekIso) {
            1 => 1.1,
            6 => 0.8,
            default => 1.0,
        };

        if ($this->day->day >= 25 || $this->day->day <= 2) {
            $factor *= 1.25;
        }

        return $factor;
    }

    /** Harga beli naik pelan dengan variasi kecil, dibulatkan ke kelipatan 50 rupiah. */
    private function cost(array $product): int
    {
        $drift = 0.036 * ($this->dayIndex / max(1, self::$days));
        $value = $product['cost'] * (1 + $drift) * (1 + mt_rand(-100, 100) / 10000);

        return max(50, (int) (round($value / 50) * 50));
    }

    private function roundPack(float $quantity): int
    {
        $q = max(1, (int) ceil($quantity));

        if ($q >= 100) {
            return (int) (ceil($q / 10) * 10);
        }

        if ($q >= 20) {
            return (int) (ceil($q / 5) * 5);
        }

        return $q;
    }

    private function issueReason(): string
    {
        $roll = mt_rand(1, 100);

        return match (true) {
            $roll <= 82 => 'Penjualan',
            $roll <= 87 => 'Barang rusak',
            $roll <= 91 => 'Sampel',
            $roll <= 95 => 'Pemakaian internal',
            default => 'Retur ke supplier',
        };
    }

    // ---------------------------------------------------------------- stok awal dan barang keluar

    private function openingStock(): void
    {
        foreach ($this->warehouseIds as $warehouseId) {
            $warehouse = $this->warehouses[$warehouseId];
            $this->tick();

            $receipt = GoodsReceipt::create([
                'warehouse_id' => $warehouseId,
                'reference' => 'SALDO-AWAL',
                'notes' => 'Saldo awal',
                'created_by' => $warehouse['staff']->id,
            ]);

            foreach ($this->products as $productId => $product) {
                $receipt->items()->create([
                    'product_id' => $productId,
                    'quantity' => (string) $this->roundPack($product['daily'] * $warehouse['scale'] * mt_rand(22, 32)),
                    'unit_cost' => (string) max(50, (int) (round($product['cost'] * 0.97 / 50) * 50)),
                ]);
            }

            $this->tick();
            app(PostGoodsReceipt::class)->execute($receipt, $warehouse['staff']);
        }
    }

    private function dailyIssues(int $warehouseId): void
    {
        $warehouse = $this->warehouses[$warehouseId];
        $factor = $this->dayFactor();
        $docCount = max(2, (int) round((4 + mt_rand(0, 2)) * $warehouse['scale']));
        $docs = array_fill(0, $docCount, []);
        $balance = $this->balance($warehouseId);

        foreach ($this->products as $productId => $product) {
            $demand = $product['daily'] * $warehouse['scale'] * $factor * mt_rand(50, 150) / 100;
            $units = (int) floor($demand) + (mt_rand(0, 99) < (($demand - floor($demand)) * 100) ? 1 : 0);
            $units = min($units, (int) floor(($balance[$productId] ?? 0) * 0.6)); // tidak pernah melebihi stok

            if ($units < 1) {
                continue;
            }

            $parts = ($units >= 6 && mt_rand(0, 1)) ? 2 : 1;
            $left = $units;

            for ($k = 0; $k < $parts; $k++) {
                $take = $k === $parts - 1 ? $left : (int) max(1, floor($left / 2));
                $left -= $take;

                if ($take >= 1) {
                    $d = mt_rand(0, $docCount - 1);
                    $docs[$d][$productId] = ($docs[$d][$productId] ?? 0) + $take;
                }
            }
        }

        foreach ($docs as $lines) {
            if ($lines !== []) {
                $this->postIssue($warehouseId, $lines);
            }
        }
    }

    /** @param array<int, int> $lines id produk => jumlah */
    private function postIssue(int $warehouseId, array $lines): void
    {
        $staff = $this->warehouses[$warehouseId]['staff'];
        $reason = $this->issueReason();
        $sale = $reason === 'Penjualan';
        $this->tick();

        $issue = StockIssue::create([
            'warehouse_id' => $warehouseId,
            'reason' => $reason,
            'reference' => $sale ? sprintf('INV/%s/%04d', $this->clock->format('Y/m'), ++$this->counter) : null,
            'notes' => $sale ? self::CUSTOMERS[array_rand(self::CUSTOMERS)] : null,
            'created_by' => $staff->id,
        ]);

        foreach ($lines as $productId => $quantity) {
            $issue->items()->create(['product_id' => $productId, 'quantity' => (string) $quantity]);
        }

        $this->tick();
        app(PostStockIssue::class)->execute($issue, $staff);

        if (mt_rand(1, 100) <= 2) {
            $this->tick();
            app(ReverseStockDocument::class)->execute($issue->fresh(), $this->manager, 'Salah input pelanggan');
        }
    }

    // ---------------------------------------------------------------- pembelian

    private function adhocReceipt(int $warehouseId): void
    {
        $warehouse = $this->warehouses[$warehouseId];
        $supplierName = self::SUPPLIERS[array_rand(self::SUPPLIERS)][0];
        $products = array_filter($this->products, fn ($p) => $p['supplier'] === $supplierName);
        $ids = array_keys($products);
        shuffle($ids);
        $this->tick();

        $receipt = GoodsReceipt::create([
            'warehouse_id' => $warehouseId,
            'supplier_id' => $this->supplierIds[$supplierName],
            'reference' => sprintf('SJ-%05d', ++$this->counter),
            'notes' => 'Pembelian tambahan tanpa PO',
            'created_by' => $warehouse['staff']->id,
        ]);

        foreach (array_slice($ids, 0, mt_rand(2, 3)) as $productId) {
            $product = $this->products[$productId];

            $receipt->items()->create([
                'product_id' => $productId,
                'quantity' => (string) max(1, (int) ceil($product['daily'] * $warehouse['scale'] * mt_rand(2, 4))),
                'unit_cost' => (string) $this->cost($product),
            ]);
        }

        $this->tick();
        app(PostGoodsReceipt::class)->execute($receipt, $warehouse['staff']);
    }

    private function reorder(int $warehouseId): void
    {
        $warehouse = $this->warehouses[$warehouseId];
        $balance = $this->balance($warehouseId);
        $onOrder = $this->onOrder($warehouseId);
        $bySupplier = [];

        foreach ($this->products as $productId => $product) {
            $average = $product['daily'] * $warehouse['scale'];
            $projected = ($balance[$productId] ?? 0) + ($onOrder[$productId] ?? 0);

            // Pesan lagi saat stok (termasuk yang sudah dipesan) kurang dari 12 hari, isi sampai 30 hari.
            if ($projected >= $average * 12) {
                continue;
            }

            $bySupplier[$product['supplier']][$productId] = $this->roundPack($average * 30 - $projected);
        }

        foreach ($bySupplier as $supplier => $lines) {
            $this->createOrder($warehouseId, $supplier, $lines);
        }
    }

    /** @param array<int, int> $lines id produk => jumlah */
    private function createOrder(int $warehouseId, string $supplier, array $lines): void
    {
        $staff = $this->warehouses[$warehouseId]['staff'];
        $lead = mt_rand(2, 4);
        $this->tick();

        $order = PurchaseOrder::create([
            'supplier_id' => $this->supplierIds[$supplier],
            'warehouse_id' => $warehouseId,
            'reference' => sprintf('QT-%s-%03d', $this->clock->format('ymd'), mt_rand(100, 999)),
            'expected_date' => $this->clock->copy()->addDays($lead + 1)->toDateString(),
            'created_by' => $staff->id,
        ]);

        foreach ($lines as $productId => $quantity) {
            $order->items()->create([
                'product_id' => $productId,
                'quantity' => (string) $quantity,
                'unit_cost' => (string) $this->cost($this->products[$productId]),
            ]);
        }

        $this->tick();
        app(SubmitPurchaseOrder::class)->execute($order, $staff);

        $id = $order->id;

        if ($order->fresh()->status === PurchaseOrderStatus::Submitted) {
            $this->later(1, fn () => $this->approveOrder($id));
        } else {
            $this->later($lead, fn () => $this->receiveOrder($id));
        }
    }

    private function approveOrder(int $orderId): void
    {
        $order = PurchaseOrder::find($orderId);

        if (!$order || $order->status !== PurchaseOrderStatus::Submitted) {
            return;
        }

        $this->tick();

        // Sesekali supplier tidak sanggup memenuhi dan PO dibatalkan.
        if (mt_rand(1, 100) <= 4) {
            app(CancelPurchaseOrder::class)->execute($order, $this->manager);

            return;
        }

        app(ApprovePurchaseOrder::class)->execute($order, $this->manager);

        $this->later(mt_rand(1, 3), fn () => $this->receiveOrder($orderId));
    }

    private function receiveOrder(int $orderId): void
    {
        $order = PurchaseOrder::find($orderId);

        if (!$order || !$order->status->canReceive()) {
            return;
        }

        $staff = $this->warehouses[$order->warehouse_id]['staff'];
        $this->tick();

        $receipt = app(CreateReceiptFromPurchaseOrder::class)->execute($order, $staff);
        $partial = mt_rand(1, 100) <= 12;

        if ($partial) {
            foreach ($receipt->items as $item) {
                $quantity = (int) floor((float) $item->quantity * mt_rand(55, 90) / 100);

                if ($quantity >= 1) {
                    $item->forceFill(['quantity' => (string) $quantity])->save();
                }
            }
        }

        $this->tick();
        app(PostGoodsReceipt::class)->execute($receipt, $staff);

        // Salah input jumlah: dibalik, lalu diterima ulang keesokan harinya.
        if (!$partial && mt_rand(1, 100) <= 3) {
            $this->tick();
            app(ReverseStockDocument::class)->execute($receipt->fresh(), $this->manager, 'Salah input jumlah');
            $this->later(1, fn () => $this->receiveOrder($orderId));

            return;
        }

        if ($partial) {
            if (mt_rand(1, 100) <= 75) {
                $this->later(mt_rand(2, 4), fn () => $this->receiveOrder($orderId));
            } else {
                $this->later(mt_rand(2, 3), fn () => $this->closeOrder($orderId));
            }
        }
    }

    private function closeOrder(int $orderId): void
    {
        $order = PurchaseOrder::find($orderId);

        if (!$order || $order->status !== PurchaseOrderStatus::PartiallyReceived) {
            return;
        }

        $this->tick();
        app(ClosePurchaseOrder::class)->execute($order, $this->manager, 'Supplier kehabisan stok');
    }

    // ---------------------------------------------------------------- transfer

    private function maybeTransfer(): void
    {
        if (count($this->warehouseIds) < 2 || mt_rand(1, 100) > 70) {
            return;
        }

        // Jakarta pusat distribusi; sesekali Surabaya mengirim ke Bandung.
        if (mt_rand(1, 100) <= 80) {
            $from = $this->warehouseIds[0];
            $to = $this->warehouseIds[mt_rand(1, count($this->warehouseIds) - 1)];
        } else {
            $from = $this->warehouseIds[1];
            $to = $this->warehouseIds[2] ?? $this->warehouseIds[0];
        }

        $source = $this->balance($from);
        $destination = $this->balance($to);
        $lines = [];

        foreach ($this->products as $productId => $product) {
            $sourceAverage = $product['daily'] * $this->warehouses[$from]['scale'];
            $destinationAverage = $product['daily'] * $this->warehouses[$to]['scale'];

            if (($source[$productId] ?? 0) < $sourceAverage * 20 || ($destination[$productId] ?? 0) >= $destinationAverage * 15) {
                continue;
            }

            $quantity = (int) min(floor($source[$productId] * 0.3), ceil($destinationAverage * 20 - ($destination[$productId] ?? 0)));

            if ($quantity >= 1) {
                $lines[$productId] = $quantity;
            }

            if (count($lines) >= 4) {
                break;
            }
        }

        if ($lines === []) {
            return;
        }

        $transfer = $this->createTransfer($from, $to, $lines, 'Pemerataan stok');

        if (mt_rand(1, 100) <= 5) {
            $this->tick();
            app(CancelStockTransfer::class)->execute($transfer, $this->manager);

            return;
        }

        $this->tick();
        app(DispatchStockTransfer::class)->execute($transfer, $this->warehouses[$from]['staff']);

        $id = $transfer->id;
        $this->later(mt_rand(1, 3), fn () => $this->receiveTransfer($id));
    }

    /** @param array<int, int> $lines id produk => jumlah */
    private function createTransfer(int $from, int $to, array $lines, ?string $notes = null): StockTransfer
    {
        $staff = $this->warehouses[$from]['staff'];
        $this->tick();

        $transfer = StockTransfer::create([
            'source_warehouse_id' => $from,
            'destination_warehouse_id' => $to,
            'reference' => sprintf('TRF-%s-%03d', now()->format('ymd'), mt_rand(100, 999)),
            'notes' => $notes,
            'created_by' => $staff->id,
        ]);

        foreach ($lines as $productId => $quantity) {
            $transfer->items()->create(['product_id' => $productId, 'quantity' => (string) $quantity]);
        }

        $this->tick();
        app(SubmitStockTransfer::class)->execute($transfer, $staff);

        if ($transfer->fresh()->status === TransferStatus::PendingApproval) {
            $this->tick();
            app(ApproveStockTransfer::class)->execute($transfer, $this->manager);
        }

        return $transfer->fresh();
    }

    private function receiveTransfer(int $transferId): void
    {
        $transfer = StockTransfer::with('items')->find($transferId);

        if (!$transfer || !$transfer->status->hasStockInTransit()) {
            return;
        }

        $partial = mt_rand(1, 100) <= 15;
        $lines = [];

        foreach ($transfer->items as $item) {
            $remaining = (float) $item->remaining();
            $quantity = ($partial && $remaining >= 2) ? floor($remaining * 0.6) : $remaining;

            $lines[] = ['item_id' => $item->id, 'quantity' => number_format($quantity, 3, '.', '')];
        }

        $this->tick();

        app(ReceiveStockTransfer::class)->execute(
            $transfer,
            $this->warehouses[$transfer->destination_warehouse_id]['staff'],
            $lines,
            (string) Str::uuid(),
        );

        if ($partial) {
            $this->later(2, fn () => $this->returnTransfer($transferId));
        }
    }

    private function returnTransfer(int $transferId): void
    {
        $transfer = StockTransfer::find($transferId);

        if (!$transfer || !$transfer->status->hasStockInTransit()) {
            return;
        }

        $this->tick();
        app(ReturnTransferRemainder::class)->execute($transfer, $this->warehouses[$transfer->source_warehouse_id]['staff']);
    }

    // ---------------------------------------------------------------- opname

    private function runStocktake(int $position, bool $reject): void
    {
        $warehouseId = $this->warehouseIds[$position % count($this->warehouseIds)];
        $staff = $this->warehouses[$warehouseId]['staff'];
        $this->tick();

        $stocktake = Stocktake::create(['warehouse_id' => $warehouseId, 'notes' => 'Opname bulanan', 'created_by' => $staff->id]);

        $this->tick();
        app(StartStocktake::class)->execute($stocktake, $staff);

        $items = $stocktake->items()->get();
        $ids = $items->pluck('id')->all();
        shuffle($ids);
        $differences = array_slice($ids, 0, mt_rand(3, 5));
        $lines = [];

        foreach ($items as $item) {
            $system = (float) $item->system_qty;
            $counted = $system;
            $reason = null;

            if (in_array($item->id, $differences, true) && $system > 0) {
                if (mt_rand(0, 99) < 75) {
                    $counted = max(0, $system - mt_rand(1, max(1, (int) ceil($system * 0.05))));
                    $reason = self::LOSS_REASONS[array_rand(self::LOSS_REASONS)];
                } else {
                    $counted = $system + mt_rand(1, 3);
                    $reason = self::GAIN_REASONS[array_rand(self::GAIN_REASONS)];
                }
            }

            $lines[] = [
                'item_id' => $item->id,
                'counted_qty' => number_format($counted, 3, '.', ''),
                'reason' => $counted === $system ? null : $reason,
            ];
        }

        $this->tick();
        app(RecordStocktakeCount::class)->execute($stocktake, $staff, $lines);

        $this->tick();
        app(SubmitStocktakeForApproval::class)->execute($stocktake, $staff);

        if ($reject) {
            $this->tick();
            app(RejectStocktake::class)->execute($stocktake, $this->manager, 'Hitung ulang rak C, selisih terlalu besar');

            $this->tick(10, 25);
            app(RecordStocktakeCount::class)->execute($stocktake, $staff, $lines);

            $this->tick();
            app(SubmitStocktakeForApproval::class)->execute($stocktake, $staff);
        }

        $this->tick();
        app(PostStocktake::class)->execute($stocktake, $this->manager);
    }

    // ---------------------------------------------------------------- kondisi hari ini

    /** Pekerjaan yang masih menunggu saat ini, dibuat dengan waktu nyata. */
    private function finalState(): void
    {
        $this->dayIndex = self::$days;

        [$jakarta, $surabaya, $bandung] = array_pad($this->warehouseIds, 3, $this->warehouseIds[0]);

        $this->makeOrder($jakarta, 'PT Bersih Sejahtera', submit: true, approve: false);
        $this->makeOrder($surabaya, 'PT Tirta Segar Abadi', submit: true, approve: true);
        $this->makeOrder($bandung, 'UD Berkah Snack', submit: false, approve: false);

        $this->draftReceipt($jakarta);
        $this->draftIssue($surabaya);

        $ready = $this->transferLines($jakarta);

        if ($ready !== []) {
            $this->createTransfer($jakarta, $bandung, $ready, 'Menunggu pengiriman');
        }

        $moving = $this->transferLines($jakarta, 8);

        if ($moving !== []) {
            $transfer = $this->createTransfer($jakarta, $surabaya, $moving, 'Dalam perjalanan');
            app(DispatchStockTransfer::class)->execute($transfer, $this->warehouses[$jakarta]['staff']);
        }

        Stocktake::create([
            'warehouse_id' => $bandung,
            'notes' => 'Opname bulan depan',
            'created_by' => $this->warehouses[$bandung]['staff']->id,
        ]);

        $this->makeSomeLow($jakarta);
    }

    private function makeOrder(int $warehouseId, string $supplier, bool $submit, bool $approve): void
    {
        $warehouse = $this->warehouses[$warehouseId];
        $products = array_slice(array_filter($this->products, fn ($p) => $p['supplier'] === $supplier), 0, 3, true);

        $order = PurchaseOrder::create([
            'supplier_id' => $this->supplierIds[$supplier],
            'warehouse_id' => $warehouseId,
            'reference' => sprintf('QT-%s-%03d', now()->format('ymd'), mt_rand(100, 999)),
            'expected_date' => now()->addDays(4)->toDateString(),
            'created_by' => $warehouse['staff']->id,
        ]);

        foreach ($products as $productId => $product) {
            $order->items()->create([
                'product_id' => $productId,
                'quantity' => (string) $this->roundPack($product['daily'] * $warehouse['scale'] * 20),
                'unit_cost' => (string) $this->cost($product),
            ]);
        }

        if ($submit) {
            app(SubmitPurchaseOrder::class)->execute($order, $warehouse['staff']);

            if ($approve && $order->fresh()->status === PurchaseOrderStatus::Submitted) {
                app(ApprovePurchaseOrder::class)->execute($order, $this->manager);
            }
        }
    }

    private function draftReceipt(int $warehouseId): void
    {
        $warehouse = $this->warehouses[$warehouseId];
        $supplier = 'CV Karya ATK Mandiri';

        $receipt = GoodsReceipt::create([
            'warehouse_id' => $warehouseId,
            'supplier_id' => $this->supplierIds[$supplier],
            'reference' => sprintf('SJ-%05d', ++$this->counter),
            'notes' => 'Menunggu diperiksa',
            'created_by' => $warehouse['staff']->id,
        ]);

        foreach (array_slice(array_filter($this->products, fn ($p) => $p['supplier'] === $supplier), 0, 2, true) as $productId => $product) {
            $receipt->items()->create([
                'product_id' => $productId,
                'quantity' => (string) max(1, (int) ceil($product['daily'] * $warehouse['scale'] * 3)),
                'unit_cost' => (string) $this->cost($product),
            ]);
        }
    }

    private function draftIssue(int $warehouseId): void
    {
        $balance = $this->balance($warehouseId);
        arsort($balance);

        $issue = StockIssue::create([
            'warehouse_id' => $warehouseId,
            'reason' => 'Penjualan',
            'reference' => sprintf('INV/%s/%04d', now()->format('Y/m'), ++$this->counter),
            'notes' => self::CUSTOMERS[array_rand(self::CUSTOMERS)],
            'created_by' => $this->warehouses[$warehouseId]['staff']->id,
        ]);

        foreach (array_slice($balance, 0, 2, true) as $productId => $quantity) {
            $take = (int) floor($quantity * 0.08);

            if ($take >= 1) {
                $issue->items()->create(['product_id' => $productId, 'quantity' => (string) $take]);
            }
        }
    }

    /** @return array<int, int> dua produk dengan stok terbanyak di gudang asal */
    private function transferLines(int $from, int $percent = 6): array
    {
        $balance = $this->balance($from);
        arsort($balance);
        $lines = [];

        foreach (array_slice($balance, 0, 2, true) as $productId => $quantity) {
            $take = (int) floor($quantity * $percent / 100);

            if ($take >= 1) {
                $lines[$productId] = $take;
            }
        }

        return $lines;
    }

    /** Naikkan stok minimum tiga produk yang paling tipis supaya indikator stok rendah terlihat. */
    private function makeSomeLow(int $warehouseId): void
    {
        $cover = [];

        foreach ($this->balance($warehouseId) as $productId => $quantity) {
            $cover[$productId] = $quantity / max(1, $this->products[$productId]['daily']);
        }

        asort($cover);

        foreach (array_slice(array_keys($cover), 0, 3) as $productId) {
            $balance = $this->balance($warehouseId)[$productId] ?? 0;

            Product::whereKey($productId)->update(['min_stock' => (int) ceil($balance) + 10]);
        }
    }
}
