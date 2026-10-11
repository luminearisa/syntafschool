<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Titipan barang santri (laptop/HP/kamera) di pengasuhan.
     *
     * Satu deposit = satu kali penitipan oleh seorang santri dengan beberapa
     * barang. Tiap barang dapat dipinjam sementara lalu dikembalikan; setiap
     * siklus pinjam-kembali dicatat di tabel loans agar riwayat tidak hilang
     * ketika barang yang sama dipinjam berkali-kali.
     */
    public function up(): void
    {
        Schema::create('boarding_item_deposits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            // Petugas yang menerima titipan.
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('deposited_at');
            $table->enum('status', ['active', 'returned'])->default('active');
            $table->timestamp('returned_at')->nullable();
            // Petugas yang menutup titipan (serah terima akhir).
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['student_id', 'status']);
        });

        Schema::create('boarding_item_deposit_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deposit_id')->constrained('boarding_item_deposits')->cascadeOnDelete();
            $table->string('item_type', 20)->default('custom'); // laptop/ipad/hp/kamera/custom
            $table->string('item_name', 150);
            $table->enum('status', ['in_storage', 'borrowed', 'returned_permanently'])->default('in_storage');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['deposit_id', 'status']);
        });

        Schema::create('boarding_item_deposit_item_loans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('boarding_item_deposit_items')->cascadeOnDelete();
            $table->timestamp('borrowed_at');
            $table->timestamp('returned_at')->nullable();
            $table->text('notes')->nullable();
            // Petugas yang memproses peminjaman/pengembalian terakhir.
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Hanya satu pinjaman terbuka per barang — ditegakkan di service
            // lewat row lock (SQLite tidak punya partial unique index universal).
            $table->index(['item_id', 'returned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boarding_item_deposit_item_loans');
        Schema::dropIfExists('boarding_item_deposit_items');
        Schema::dropIfExists('boarding_item_deposits');
    }
};
