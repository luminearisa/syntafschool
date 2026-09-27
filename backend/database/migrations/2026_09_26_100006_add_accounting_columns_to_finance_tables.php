<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — pelengkap buku besar (§3–§5, §20, §33).
 *
 * Tabel `finance_transactions` + `finance_transaction_lines` sudah menjadi
 * jurnal double-entry sejak Phase 1. Yang hilang adalah jejak yang membuat
 * jurnal bisa diaudit dan dialokasikan: metadata dokumen sumber, siapa dan
 * kapan mem-posting, cost center per baris (supaya satu jurnal multi-tagihan
 * tetap bisa dipisahkan per unit), dan nomor referensi baris.
 *
 * Sengaja TIDAK dibuat tabel `finance_journals` terpisah: header jurnal IS
 * financial transaction (§71, jangan duplikasi entitas). `transaction_number`
 * (format JRN-…) adalah nomor jurnal.
 *
 * Kolom pemetaan akun (`finance_fee_types.receivable_account_id`) membuat §20
 * nyata: satu jenis tagihan bisa menunjuk pendapatan DAN piutangnya sendiri.
 * `finance_invoices.written_off_amount` + adjustment `kind`/`invoice_id`
 * menopang write-off piutang (§33) tanpa tabel penagihan baru.
 *
 * `finance_transactions.type` adalah enum: nilainya (invoice/payable/write_off)
 * ditambahkan di file CREATE untuk sqlite (constraint dibuat ulang saat migrate),
 * dan di-ALTER di sini untuk MySQL yang tabelnya sudah terbentuk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('description');
            $table->timestamp('posted_at')->nullable()->after('status');
            $table->foreignId('posted_by')->nullable()->after('posted_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('finance_transaction_lines', function (Blueprint $table) {
            $table->foreignId('cost_center_id')->nullable()->after('account_id')->constrained('finance_cost_centers')->nullOnDelete();
            $table->string('reference', 80)->nullable()->after('description');
            $table->index('cost_center_id');
        });

        Schema::table('finance_fee_types', function (Blueprint $table) {
            $table->foreignId('receivable_account_id')->nullable()->after('account_id')->constrained('finance_accounts')->nullOnDelete();
        });

        Schema::table('finance_invoices', function (Blueprint $table) {
            $table->decimal('written_off_amount', 15, 2)->default(0)->after('paid_amount');
        });

        Schema::table('finance_adjustments', function (Blueprint $table) {
            $table->string('kind', 20)->default('general')->after('adjustment_number');
            $table->foreignId('invoice_id')->nullable()->after('kind')->constrained('finance_invoices')->nullOnDelete();
        });

        // MySQL menyimpan enum sebagai daftar nilai inline — perlu MODIFY eksplisit.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE finance_transactions MODIFY type ENUM('revenue','expense','payment','refund','transfer','adjustment','opening_balance','invoice','payable','write_off') NOT NULL DEFAULT 'payment'");
        }
    }

    public function down(): void
    {
        Schema::table('finance_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropColumn('kind');
        });

        Schema::table('finance_invoices', function (Blueprint $table) {
            $table->dropColumn('written_off_amount');
        });

        Schema::table('finance_fee_types', function (Blueprint $table) {
            $table->dropConstrainedForeignId('receivable_account_id');
        });

        Schema::table('finance_transaction_lines', function (Blueprint $table) {
            $table->dropIndex(['cost_center_id']);
            $table->dropConstrainedForeignId('cost_center_id');
            $table->dropColumn('reference');
        });

        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('posted_by');
            $table->dropColumn(['posted_at', 'metadata']);
        });
    }
};
