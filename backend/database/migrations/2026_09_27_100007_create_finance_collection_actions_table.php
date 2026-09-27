<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance Phase 4 / Collection (§6): riwayat aksi penagihan per invoice.
 *
 * Tabel ini sengaja HANYA mencatat tindakan penagihan. Tidak ada satu pun kolom
 * padanya yang menyimpan sisa tagihan: outstanding tetap hasil perhitungan
 * invoice − alokasi payment confirmed − adjustment/write-off yang disetujui (§6).
 * Karena itu "halaman collection" tidak pernah bisa mengubah angka piutang —
 * ia hanya menambah sejarah tentang apa yang sudah dilakukan atas piutang itu,
 * yang memang satu-satunya hal yang boleh ditulis sebuah penagihan.
 *
 * `stage` dibekukan pada saat aksi dicatat. Stage sebuah invoice bergerak seiring
 * waktu (due soon → overdue → eskalasi), jadi tanpa salinan ini tidak ada cara
 * membaca kembali "atas dasar apa penagih menghubungi wali ini". Konvensi yang
 * sama dipakai `budget_control` pada pengajuan pengeluaran (§10).
 *
 * `reference_type`/`reference_id` menautkan aksi yang melahirkan dokumen:
 * waiver/write-off request merujuk FinanceAdjustment, dan payment plan (§7) akan
 * menautkan plan-nya sendiri. Aksi tanpa tautan tetap sah (panggilan telepon,
 * kunjungan); aksi yang menautkan dokumen wajib menautkan dokumen yang benar,
 * dan itu ditegakkan di service, bukan di UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_collection_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('finance_invoices')->restrictOnDelete();
            $table->string('action_type', 30);
            $table->string('stage', 30);
            $table->text('notes')->nullable();
            $table->dateTime('occurred_at');
            $table->unsignedInteger('notified_count')->default(0);
            $table->string('reference_type', 120)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['invoice_id', 'occurred_at']);
            $table->index('action_type');
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_collection_actions');
    }
};
