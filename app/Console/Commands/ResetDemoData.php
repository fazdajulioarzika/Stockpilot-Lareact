<?php

namespace App\Console\Commands;

use Database\Seeders\RealisticDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetDemoData extends Command
{
    protected $signature = 'stockpilot:reset-demo {--yes : Lewati konfirmasi (tetap menolak di production)}';

    protected $description = 'Hapus semua data transaksi dan master, lalu isi ulang dengan riwayat demo yang tampak nyata. Akun pengguna dipertahankan.';

    /** Urutan tidak penting karena pengecekan foreign key dimatikan sementara. */
    private const TABLES = [
        'stock_movements', 'inventory_stocks',
        'goods_receipt_items', 'goods_receipts',
        'stock_issue_items', 'stock_issues',
        'stock_transfer_events', 'stock_transfer_items', 'stock_transfers',
        'stocktake_items', 'stocktakes',
        'purchase_order_items', 'purchase_orders',
        'document_reversals', 'audit_logs',
        'user_warehouses', 'products', 'categories', 'units', 'suppliers', 'warehouses',
    ];

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Perintah ini tidak boleh dijalankan di production.');

            return self::FAILURE;
        }

        $database = (string) DB::connection()->getDatabaseName();

        $this->warn("Database: {$database}");
        $this->line('Akan DIHAPUS: seluruh transaksi, saldo, ledger, audit log, dan master data (produk, kategori, satuan, supplier, gudang).');
        $this->line('Dipertahankan: akun pengguna. Data demo lalu dibuat ulang.');

        if (!$this->option('yes')) {
            if (!$this->confirm('Apakah Anda sudah membuat backup database?', false)) {
                $this->error('Dibatalkan. Buat backup dulu dengan mysqldump.');

                return self::FAILURE;
            }

            if (trim((string) $this->ask("Ketik nama database ({$database}) untuk melanjutkan")) !== $database) {
                $this->error('Nama database tidak cocok. Dibatalkan, tidak ada yang dihapus.');

                return self::FAILURE;
            }
        }

        Schema::disableForeignKeyConstraints();

        try {
            foreach (self::TABLES as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->truncate();
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->info('Data lama dihapus. Membuat riwayat demo, ini bisa memakan waktu beberapa menit...');

        return $this->call('db:seed', ['--class' => RealisticDemoSeeder::class]);
    }
}
