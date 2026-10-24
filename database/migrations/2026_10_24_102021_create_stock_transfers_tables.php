<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->nullable()->unique();
            $table->foreignId('source_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('destination_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['source_warehouse_id', 'status']);
            $table->index(['destination_warehouse_id', 'status']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('qty_sent', 15, 3)->default(0);
            $table->decimal('qty_received', 15, 3)->default(0);
            $table->decimal('qty_returned', 15, 3)->default(0);
            $table->timestamps();

            $table->unique(['stock_transfer_id', 'product_id']);
        });

        Schema::create('stock_transfer_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->restrictOnDelete();
            $table->string('type', 20); // receive | return
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT chk_st_items_qty CHECK (quantity > 0)');
            DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT chk_st_items_flow CHECK (qty_sent >= 0 AND qty_received >= 0 AND qty_returned >= 0 AND qty_received + qty_returned <= qty_sent)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_events');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
