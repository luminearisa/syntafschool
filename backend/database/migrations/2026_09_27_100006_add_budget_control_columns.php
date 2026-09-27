<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Budget control (§10) butuh dua hal yang belum ada.
 *
 * 1. Hasil pemeriksaan anggaran di-stamp pada pengajuan itu sendiri
 *    (`budget_control`, `budget_shortfall`, `budget_override_by/at`). Committed
 *    dihitung dari status pengajuan, jadi tidak ada kolom budget yang berubah
 *    saat pengajuan dibuat (§10 — actual tidak boleh bergerak). Yang perlu
 *    dibekukan justru keputusannya: kebijakan mana yang berlaku saat diajukan,
 *    seberapa besar kelebihan nominalnya, dan siapa yang menerimanya. Tanpa
 *    itu, perubahan konfigurasi di tengah alur membuat pengajuan yang sama
 *    menuntut persetujuan yang berbeda, dan override tak terlacak.
 *
 * 2. §8 menambahkan dua status penutup yang belum ada jalurnya: CLOSED dan
 *    CANCELLED. Keduanya butuh tanggal dan penanggung jawab yang sama seperti
 *    approval, dan kolom status dilebarkan dari enum ke string karena
 *    `cancelled` tidak ada di daftar aslinya (sama seperti §3 untuk transfer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_expense_requests', function (Blueprint $table) {
            $table->string('budget_control', 20)->nullable()->after('required_approval_levels');
            $table->decimal('budget_shortfall', 15, 2)->default(0)->after('budget_control');
            $table->foreignId('budget_override_by')->nullable()->constrained('users')->nullOnDelete()->after('budget_shortfall');
            $table->timestamp('budget_override_at')->nullable()->after('budget_override_by');
        });

        Schema::table('finance_budgets', function (Blueprint $table) {
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete()->after('approved_at');
            $table->timestamp('closed_at')->nullable()->after('closed_by');
            $table->string('cancel_reason', 500)->nullable()->after('reject_reason');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete()->after('cancel_reason');
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');

            $table->string('status', 20)->default('draft')->change();
        });

        // `budget_control` diisi surut hanya untuk pengajuan yang memang sudah
        // memakan komitmen, supaya laporan tidak menampilkan kebijakan yang
        // tidak pernah berlaku atasnya.
        DB::table('finance_expense_requests')
            ->whereIn('status', ['submitted', 'under_review', 'approved'])
            ->whereNull('budget_control')
            ->update(['budget_control' => 'none']);
    }

    public function down(): void
    {
        Schema::table('finance_budgets', function (Blueprint $table) {
            $table->enum('status', ['draft', 'submitted', 'approved', 'active', 'rejected', 'closed'])->default('draft')->change();
            $table->dropConstrainedForeignId('closed_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['closed_at', 'cancel_reason', 'cancelled_at']);
        });

        Schema::table('finance_expense_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('budget_override_by');
            $table->dropColumn(['budget_control', 'budget_shortfall', 'budget_override_at']);
        });
    }
};
