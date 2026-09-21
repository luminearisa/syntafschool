<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengadaan & stock opname (Fase 4).
 *
 * - `library_acquisition_requests` menampung usulan/pesanan pengadaan;
 *   penerimaan (receiving) membuat bibliographic record + eksemplar sehingga
 *   alur "Pengadaan → Terima → Eksemplar → Tersedia" berjalan utuh.
 * - `library_inventory_sessions` + `lines` menjalankan stock opname:
 *   buat sesi → scan → bandingkan (ditemukan/hilang/tak terduga) → finalkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_acquisition_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained('libraries')->cascadeOnDelete();

            $table->string('code', 50);
            // Supplier memakai master `publishers` (sudah punya kontak) —
            // bukan master vendor baru.
            $table->foreignId('supplier_id')->nullable()->constrained('publishers')->nullOnDelete();
            $table->string('supplier_name', 150)->nullable();

            // draft | submitted | approved | ordered | received | cancelled
            $table->enum('status', ['draft', 'submitted', 'approved', 'ordered', 'received', 'cancelled'])
                ->default('draft');

            $table->date('requested_at');
            $table->date('expected_at')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('received_at')->nullable();

            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['library_id', 'code']);
            $table->index(['library_id', 'status']);
        });

        Schema::create('library_acquisition_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('library_acquisition_requests')->cascadeOnDelete();

            // Judul yang diminta; bila sudah ada di katalog, tautkan record-nya.
            $table->string('title', 255);
            $table->string('isbn', 30)->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedSmallInteger('received_quantity')->default(0);
            $table->decimal('unit_price', 14, 2)->default(0);

            $table->foreignId('bibliography_id')->nullable()->constrained('library_bibliographies')->nullOnDelete();
            $table->foreignId('collection_type_id')->nullable()->constrained('library_collection_types')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('request_id');
        });

        Schema::create('library_inventory_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained('libraries')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 150);

            // draft | ongoing | finalized | cancelled
            $table->enum('status', ['draft', 'ongoing', 'finalized', 'cancelled'])->default('draft');
            // Sesi dapat dibatasi pada satu lokasi.
            $table->foreignId('location_id')->nullable()->constrained('library_locations')->nullOnDelete();

            $table->date('started_at');
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();

            // Hasil perhitungan saat finalisasi (ringkasan tersimpan agar
            // laporan tidak perlu menghitung ulang seluruh baris).
            $table->unsignedInteger('expected_count')->default(0);
            $table->unsignedInteger('found_count')->default(0);
            $table->unsignedInteger('missing_count')->default(0);
            $table->unsignedInteger('unexpected_count')->default(0);
            $table->unsignedInteger('damaged_count')->default(0);

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['library_id', 'code']);
            $table->index(['library_id', 'status']);
        });

        Schema::create('library_inventory_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('library_inventory_sessions')->cascadeOnDelete();
            // Item yang diharapkan (null = tak terduga / di luar cakupan sesi).
            $table->foreignId('item_id')->nullable()->constrained('library_items')->nullOnDelete();

            $table->string('scanned_code', 100)->nullable();
            // found | missing | unexpected | damaged
            $table->enum('result', ['found', 'missing', 'unexpected', 'damaged'])->nullable();
            $table->foreignId('condition_id')->nullable()->constrained('library_collection_conditions')->nullOnDelete();
            $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scanned_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'result']);
            $table->index('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_inventory_lines');
        Schema::dropIfExists('library_inventory_sessions');
        Schema::dropIfExists('library_acquisition_items');
        Schema::dropIfExists('library_acquisition_requests');
    }
};