<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance Phase 4 / Payment Plan (§7): jadwal cicilan untuk SATU invoice.
 *
 * Tidak ada satu pun angka uang yang lahir di sini. Sebuah plan hanya membagi
 * sisa tagihan yang sudah ada menjadi beberapa jatuh tempo — revenue tetap dari
 * invoice, pelunasan tetap lewat payment yang dialokasikan ke invoice asli
 * (§7: "Payment plan tidak membuat revenue baru", "Payment tetap dialokasikan
 * ke invoice asli"). Karena itu tabel ini tidak punya FK ke payment, ke jurnal,
 * maupun kolom yang menyimpan penerimaan: `paid_amount` sebuah angsuran adalah
 * hasil bagi atas pelunasan invoice, bukan catatan uang masuk kedua.
 *
 * Konsekuensi yang sengaja diterima: pembayaran yang datang lebih dulu dari
 * rencana bukan bagian dari rencana. Itu sebabnya `baseline_paid_amount`
 * dibekukan saat plan dibuat — progress angsuran dihitung dari selisih
 * pelunasan invoice terhadap titik awal itu, supaya cicilan lama yang baru
 * dibayar tidak terlihat seperti angsuran plan yang terlambat.
 *
 * "Satu invoice satu plan aktif" ditegakkan di service dengan lock pada baris
 * invoice, bukan unique index: SQLite maupun MySQL tidak punya partial unique
 * index, dan unique biasa di (invoice_id, status) akan melarang invoice punya
 * plan batal lalu plan baru — sejarah yang sah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_payment_plans', function (Blueprint $table) {
            $table->id();
            $table->string('plan_number', 30)->unique();
            $table->foreignId('invoice_id')->constrained('finance_invoices')->restrictOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('finance_cost_centers')->nullOnDelete();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->unsignedTinyInteger('installment_count');
            $table->date('start_date');
            // Saldo invoice saat plan dibuat. Angka ini yang membuat "sudah
            // berapa angsuran terbayar" punya satu jawaban yang tidak berubah
            // tiap kali ada pembayaran lain masuk ke invoice yang sama.
            $table->decimal('baseline_paid_amount', 15, 2)->default(0);
            $table->enum('status', ['planned', 'due', 'partial', 'paid', 'overdue', 'cancelled'])->default('planned');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'status']);
            $table->index(['status', 'start_date']);
            $table->index('student_id');
        });

        Schema::create('finance_payment_plan_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_plan_id')->constrained('finance_payment_plans')->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->date('due_date');
            $table->decimal('amount', 15, 2)->default(0);
            // Padanan §7 (`paid_amount`, `outstanding`). Keduanya turunan dari
            // pelunasan invoice — lihat FinancePaymentPlanService::sync().
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('outstanding_amount', 15, 2)->default(0);
            $table->enum('status', ['planned', 'due', 'partial', 'paid', 'overdue', 'cancelled'])->default('planned');
            $table->timestamps();

            $table->unique(['payment_plan_id', 'sequence']);
            $table->index(['due_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_payment_plan_installments');
        Schema::dropIfExists('finance_payment_plans');
    }
};
