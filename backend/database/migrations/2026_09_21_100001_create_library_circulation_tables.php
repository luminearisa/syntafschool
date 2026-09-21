<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sirkulasi, denda, dan reservasi (Fase 4).
 *
 * Status eksemplar `on_loan` / `reserved` yang disiapkan pada Fase 3 kini
 * benar-benar dikendalikan di sini. Policy pinjam (durasi, jumlah maksimum,
 * batas perpanjangan) diambil dari `library_membership_types` — bukan
 * hardcode — dan tarif denda dari `library_settings` (`fine.*`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained('libraries')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('library_members')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('library_items')->cascadeOnDelete();
            $table->foreignId('bibliography_id')->nullable()->constrained('library_bibliographies')->nullOnDelete();

            $table->string('loan_code', 50);
            $table->date('borrowed_at');
            $table->date('due_at');
            $table->date('returned_on')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->unsignedSmallInteger('renewed_count')->default(0);

            // active | returned | overdue | lost
            $table->enum('status', ['active', 'returned', 'overdue', 'lost'])->default('active');

            $table->foreignId('borrowed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Penomoran per-perpustakaan → keunikan per-perpustakaan.
            $table->unique(['library_id', 'loan_code']);
            // Satu eksemplar hanya boleh punya satu pinjaman aktif — dijaga di
            // service; indeks ini mempercepat pencarian pinjaman aktif.
            $table->index(['item_id', 'status']);
            $table->index(['member_id', 'status']);
            $table->index(['library_id', 'status', 'due_at']);
        });

        Schema::create('library_loan_renewals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('library_loans')->cascadeOnDelete();
            $table->date('previous_due_at');
            $table->date('new_due_at');
            $table->foreignId('renewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('loan_id');
        });

        Schema::create('library_fines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained('libraries')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('library_members')->cascadeOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained('library_loans')->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('library_items')->nullOnDelete();

            // overdue | damage | lost | other
            $table->enum('type', ['overdue', 'damage', 'lost', 'other'])->default('overdue');
            $table->decimal('amount', 14, 2);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('waived_amount', 14, 2)->default(0);
            // unpaid | partial | paid | waived
            $table->enum('status', ['unpaid', 'partial', 'paid', 'waived'])->default('unpaid');

            $table->date('assessed_at');
            $table->unsignedSmallInteger('overdue_days')->nullable();
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('waived_reason')->nullable();
            $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('waived_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['library_id', 'status']);
            $table->index(['member_id', 'status']);
            // Satu pinjaman hanya boleh punya satu denda keterlambatan
            // (idempoten saat scheduler berulang).
            $table->unique(['loan_id', 'type']);
        });

        Schema::create('library_fine_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fine_id')->constrained('library_fines')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('paid_at');
            // Memakai master `payment_methods` yang sudah ada — bukan sistem
            // akuntansi baru.
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->string('reference', 100)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('fine_id');
        });

        Schema::create('library_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained('libraries')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('library_members')->cascadeOnDelete();
            $table->foreignId('bibliography_id')->constrained('library_bibliographies')->cascadeOnDelete();
            // Eksemplar yang disiapkan untuk diambil (diisi saat tersedia).
            $table->foreignId('item_id')->nullable()->constrained('library_items')->nullOnDelete();

            $table->string('reservation_code', 50);
            // waiting | available | picked_up | completed | expired | cancelled
            $table->enum('status', ['waiting', 'available', 'picked_up', 'completed', 'expired', 'cancelled'])
                ->default('waiting');

            $table->unsignedSmallInteger('queue_position')->nullable();
            $table->date('reserved_at');
            $table->timestamp('available_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->date('picked_up_at')->nullable();
            $table->date('cancelled_at')->nullable();

            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['library_id', 'reservation_code']);
            $table->index(['bibliography_id', 'status']);
            $table->index(['member_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_reservations');
        Schema::dropIfExists('library_fine_payments');
        Schema::dropIfExists('library_fines');
        Schema::dropIfExists('library_loan_renewals');
        Schema::dropIfExists('library_loans');
    }
};