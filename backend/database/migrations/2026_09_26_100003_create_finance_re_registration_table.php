<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finance / Daftar Ulang (§2–§7 Phase 2) — workflow re-registrasi tahun ajaran.
 *
 * Satu baris = satu santri mendaftar ulang ke satu tahun ajaran tujuan. Baris
 * ini TIDAK menduplikasi mesin tagihan: ia hanya menautkan santri + tahun ajaran
 * ke Billing/Invoice Phase 1 (`billing_id`, `invoice_id`) dan melacak status
 * workflow (ELIGIBLE→BILLED→UNPAID→PARTIAL→PAID→COMPLETED).
 *
 * Kunci idempotensi generation (§24): unique (`student_id`, `next_academic_year_id`)
 * — dua permintaan generate bersamaan tidak pernah membuat dua pendaftaran.
 *
 * `revenue_account_id` / `receivable_account_id` / `cost_center_id` disimpan agar
 * jejak akuntansi lengkap (§33) meski jurnal sesungguhnya diposting mesin ledger
 * lewat billing/invoice — tidak ada "accounting palsu" di sini (§50).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_re_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('registration_number', 30)->unique();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('current_academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->foreignId('next_academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('fee_package_id')->nullable()->constrained('finance_fee_packages')->nullOnDelete();
            $table->foreignId('billing_id')->nullable()->constrained('finance_billings')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('finance_invoices')->nullOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->foreignId('cost_center_id')->nullable()->constrained('finance_cost_centers')->nullOnDelete();
            $table->foreignId('revenue_account_id')->nullable()->constrained('finance_accounts')->nullOnDelete();
            $table->foreignId('receivable_account_id')->nullable()->constrained('finance_accounts')->nullOnDelete();
            $table->enum('status', [
                'eligible', 'billed', 'unpaid', 'partial', 'paid', 'completed', 'expired', 'cancelled',
            ])->default('eligible');
            // Penghitung percobaan generasi: dinaikkan saat reopen setelah cancel agar
            // billing paket berikutnya mendapat `reference` baru (yang lama sudah batal).
            $table->unsignedSmallInteger('generation_attempt')->default(1);
            $table->timestamp('billed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // Aktivasi tahun ajaran berikutnya (§7) — ditandai setelah completed.
            $table->boolean('activated')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Idempotensi generation: satu pendaftaran per santri per tahun tujuan.
            $table->unique(['student_id', 'next_academic_year_id'], 'finance_re_reg_student_year_unique');
            $table->index(['status', 'next_academic_year_id']);
            $table->index('billing_id');
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_re_registrations');
    }
};
