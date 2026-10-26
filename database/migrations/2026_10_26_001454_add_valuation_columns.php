<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function checks(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb']);
    }

    /** MySQL memakai DROP CHECK, MariaDB memakai DROP CONSTRAINT. Coba keduanya. */
    private function dropCheck(string $table, string $name): void
    {
        foreach (["ALTER TABLE {$table} DROP CHECK {$name}", "ALTER TABLE {$table} DROP CONSTRAINT {$name}"] as $sql) {
            try {
                DB::statement($sql);

                return;
            } catch (\Throwable) {
                // coba bentuk berikutnya
            }
        }
    }

    public function up(): void
    {
        Schema::table('inventory_stocks', function (Blueprint $table) {
            $table->decimal('total_value', 18, 4)->default(0)->after('quantity');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('value_delta', 18, 4)->default(0)->after('quantity_delta');
        });

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 4)->default(0)->after('quantity');
        });

        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->decimal('value_sent', 18, 4)->default(0)->after('qty_returned');
            $table->decimal('value_received', 18, 4)->default(0)->after('value_sent');
            $table->decimal('value_returned', 18, 4)->default(0)->after('value_received');
        });

        if ($this->checks()) {
            // Nilai tidak boleh negatif, dan saldo nol harus bernilai nol.
            DB::statement('ALTER TABLE inventory_stocks ADD CONSTRAINT chk_inventory_stocks_value CHECK (total_value >= 0 AND (quantity > 0 OR total_value = 0))');
            DB::statement('ALTER TABLE goods_receipt_items ADD CONSTRAINT chk_gr_items_cost CHECK (unit_cost >= 0)');

            // Penilaian ulang tidak mengubah kuantitas, jadi satu-satunya mutasi yang boleh bernilai nol.
            $this->dropCheck('stock_movements', 'chk_stock_movements_delta');
            DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT chk_stock_movements_delta CHECK (quantity_delta <> 0 OR movement_type = 'revaluation')");
        }
    }

    public function down(): void
    {
        if ($this->checks()) {
            $this->dropCheck('inventory_stocks', 'chk_inventory_stocks_value');
            $this->dropCheck('goods_receipt_items', 'chk_gr_items_cost');
            $this->dropCheck('stock_movements', 'chk_stock_movements_delta');
            DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT chk_stock_movements_delta CHECK (quantity_delta <> 0)');
        }

        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->dropColumn(['value_sent', 'value_received', 'value_returned']);
        });
        Schema::table('goods_receipt_items', fn (Blueprint $table) => $table->dropColumn('unit_cost'));
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropColumn('value_delta'));
        Schema::table('inventory_stocks', fn (Blueprint $table) => $table->dropColumn('total_value'));
    }
};
