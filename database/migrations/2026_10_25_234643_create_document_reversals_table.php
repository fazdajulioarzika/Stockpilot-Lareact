<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_reversals', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 120);
            $table->unsignedBigInteger('document_id');
            $table->string('reason');
            $table->foreignId('reversed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            // Satu dokumen hanya boleh dibalik sekali, termasuk saat dua request bersamaan.
            $table->unique(['document_type', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_reversals');
    }
};
