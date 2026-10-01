<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lapisan akuntansi standar: unit bisnis, dana, saldo awal, dan permohonan
 * (buka periode & void).
 *
 * Seluruh migrasi ini ADITIF. Tidak ada tabel atau kolom lama yang diubah
 * maknanya; yang ditambahkan hanya dimensi & entitas yang selama ini hilang,
 * sehingga angka yang sudah ada tetap sama sementara laporan baru menjadi
 * mungkin.
 *
 * Catatan penting soal foreign key: kode_unit & kode_dana pada baris jurnal
 * memakai RESTRICT. Bukan tanpa alasan — keduanya adalah dimensi pelaporan, dan
 * menghapusnya akan menggeser angka laporan historis secara diam-diam. Bila
 * sebuah unit/dana tidak dipakai lagi, ia dinonaktifkan, bukan dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------- unit bisnis
        Schema::create('finance_business_units', function (Blueprint $table): void {
            $table->string('code', 30)->primary();
            $table->string('name', 120);
            $table->string('parent_code', 30)->nullable();
            $table->unsignedTinyInteger('level')->default(1);
            $table->boolean('is_active')->default(true);
            $table->string('description', 500)->nullable();
            $table->timestamps();

            $table->index('parent_code');
            $table->index('is_active');
        });

        Schema::table('finance_business_units', function (Blueprint $table): void {
            $table->foreign('parent_code')->references('code')->on('finance_business_units')->nullOnDelete();
        });

        // Unit bawaan per modul: dipakai HANYA bila pemanggil tidak menentukan unit.
        Schema::create('finance_unit_defaults', function (Blueprint $table): void {
            $table->id();
            $table->string('sumber_modul', 48)->unique();
            $table->string('business_unit_code', 30);
            $table->timestamps();

            $table->foreign('business_unit_code')->references('code')->on('finance_business_units')->restrictOnDelete();
        });

        // ---------------------------------------------------------------- dana
        Schema::create('finance_funds', function (Blueprint $table): void {
            $table->id();
            $table->string('kode_dana', 30)->unique();
            $table->string('nama_dana', 120);
            // bebas = tidak terikat; terikat = hanya untuk peruntukan tertentu;
            // wakaf/zakat/infaq/sedekah = dana syariah dengan aturan sendiri.
            $table->enum('jenis', ['bebas', 'terikat', 'wakaf', 'zakat', 'infaq', 'sedekah', 'temporer'])->default('bebas');
            $table->string('peruntukan', 500)->nullable();
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->index(['jenis', 'status']);
        });

        // Akun yang boleh dibebani dana terikat. Dana tanpa daftar = tidak membatasi.
        Schema::create('finance_fund_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('kode_dana', 30);
            $table->foreignId('account_id')->constrained('finance_accounts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['kode_dana', 'account_id'], 'finance_fund_accounts_unique');
            $table->foreign('kode_dana')->references('kode_dana')->on('finance_funds')->cascadeOnDelete();
        });

        // Akun pengurang dana bebas: pemakaian dana bebas untuk hal yang dibiayai
        // dana terikat (mis. beasiswa dari dana yayasan).
        Schema::create('finance_fund_contra_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('kode_dana', 30);
            $table->foreignId('account_id')->constrained('finance_accounts')->cascadeOnDelete();
            $table->string('keterangan', 300)->nullable();
            $table->timestamps();

            $table->unique(['kode_dana', 'account_id'], 'finance_fund_contra_unique');
            $table->foreign('kode_dana')->references('kode_dana')->on('finance_funds')->cascadeOnDelete();
        });

        // ---------------------------------------------------------- saldo awal
        Schema::create('finance_opening_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('finance_accounts')->restrictOnDelete();
            $table->enum('jenis_saldo', ['debet', 'kredit']);
            $table->decimal('saldo', 18, 2)->default(0);
            $table->boolean('posted')->default(false);
            // Tanpa FK, disengaja: jurnal saldo awal boleh dibatalkan (void) lalu
            // diganti, dan penunjuk lama harus tetap terbaca sebagai jejak.
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('keterangan', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('account_id');
            $table->index('posted');
            $table->index('transaction_id');
        });

        // ------------------------------------------------- permohonan buka periode
        Schema::create('finance_period_reopen_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('period_id')->constrained('finance_periods')->cascadeOnDelete();
            $table->string('reason', 500);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();

            $table->index(['period_id', 'status']);
        });

        // ------------------------------------------------------- permohonan void
        Schema::create('finance_journal_void_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')->constrained('finance_transactions')->cascadeOnDelete();
            $table->string('reason', 500);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->unsignedBigInteger('reversal_transaction_id')->nullable();
            $table->timestamps();

            $table->index(['transaction_id', 'status']);
        });

        // ------------------------------------------- dimensi pada header & baris
        Schema::table('finance_transactions', function (Blueprint $table): void {
            $table->string('sumber_modul', 48)->nullable()->after('type');
            $table->index('sumber_modul');
        });

        Schema::table('finance_transaction_lines', function (Blueprint $table): void {
            $table->string('account_name', 150)->nullable()->after('account_id');
            $table->string('kode_unit', 30)->nullable()->after('cost_center_id');
            $table->string('kode_dana', 30)->nullable()->after('kode_unit');

            $table->index('kode_unit');
            $table->index('kode_dana');
            $table->foreign('kode_unit')->references('code')->on('finance_business_units')->restrictOnDelete();
            $table->foreign('kode_dana')->references('kode_dana')->on('finance_funds')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('finance_transaction_lines', function (Blueprint $table): void {
            $table->dropForeign(['kode_unit']);
            $table->dropForeign(['kode_dana']);
            $table->dropIndex(['kode_unit']);
            $table->dropIndex(['kode_dana']);
            $table->dropColumn(['account_name', 'kode_unit', 'kode_dana']);
        });

        Schema::table('finance_transactions', function (Blueprint $table): void {
            $table->dropIndex(['sumber_modul']);
            $table->dropColumn('sumber_modul');
        });

        Schema::dropIfExists('finance_journal_void_requests');
        Schema::dropIfExists('finance_period_reopen_requests');
        Schema::dropIfExists('finance_opening_balances');
        Schema::dropIfExists('finance_fund_contra_accounts');
        Schema::dropIfExists('finance_fund_accounts');
        Schema::dropIfExists('finance_funds');
        Schema::dropIfExists('finance_unit_defaults');
        Schema::table('finance_business_units', function (Blueprint $table): void {
            $table->dropForeign(['parent_code']);
        });
        Schema::dropIfExists('finance_business_units');
    }
};
