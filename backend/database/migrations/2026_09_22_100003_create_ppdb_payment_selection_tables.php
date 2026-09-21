<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PPDB — tagihan, pembayaran, seleksi, pengumuman.
 *
 * Pembayaran manual memakai master `payment_methods` yang sudah ada; tidak ada
 * sistem akuntansi baru. Kolom provider disiapkan untuk Midtrans (online).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppdb_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained('ppdb_registrations')->cascadeOnDelete();
            $table->string('number', 50)->unique();
            // registration | re_registration | other
            $table->enum('type', ['registration', 're_registration', 'other'])->default('registration');
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->enum('status', ['unpaid', 'pending', 'paid', 'failed', 'expired', 'cancelled', 'refunded'])
                ->default('unpaid');
            $table->date('due_date')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['registration_id', 'status']);
            $table->index('status');
        });

        Schema::create('ppdb_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('ppdb_invoices')->cascadeOnDelete();
            $table->foreignId('fee_id')->nullable()->constrained('ppdb_registration_fees')->nullOnDelete();
            // Nama & nominal disalin agar tagihan tetap terbaca bila biaya diubah.
            $table->string('name', 150);
            $table->decimal('amount', 14, 2)->default(0);
            $table->timestamps();

            $table->index('invoice_id');
        });

        Schema::create('ppdb_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('ppdb_invoices')->cascadeOnDelete();
            $table->enum('method', ['manual', 'midtrans'])->default('manual');
            $table->decimal('amount', 14, 2);
            $table->enum('status', ['pending', 'paid', 'failed', 'expired', 'refunded'])->default('pending');
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->string('provider', 30)->nullable();
            $table->string('provider_reference', 100)->nullable();
            $table->string('proof_path')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['invoice_id', 'status']);
            $table->index('provider_reference');
        });

        // Tahap seleksi (administratif, tes akademik, wawancara, tahfidz, dll).
        Schema::create('ppdb_selection_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('ppdb_periods')->cascadeOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('ppdb_programs')->cascadeOnDelete();
            $table->enum('type', [
                'administrative', 'academic_test', 'interview', 'tahfidz',
                'psychological_test', 'health_check', 'other',
            ])->default('administrative');
            $table->string('name', 150);
            $table->timestamp('scheduled_at')->nullable();
            $table->string('location', 150)->nullable();
            $table->unsignedSmallInteger('quota')->nullable();
            $table->decimal('passing_score', 8, 2)->nullable();
            $table->enum('status', ['draft', 'scheduled', 'ongoing', 'done', 'cancelled'])->default('draft');
            $table->unsignedSmallInteger('order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['period_id', 'status']);
        });

        Schema::create('ppdb_selection_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_id')->constrained('ppdb_selection_stages')->cascadeOnDelete();
            $table->foreignId('registration_id')->constrained('ppdb_registrations')->cascadeOnDelete();
            $table->enum('status', ['invited', 'attended', 'absent', 'scored'])->default('invited');
            $table->decimal('score', 8, 2)->nullable();
            $table->enum('result', ['passed', 'failed', 'waitlisted'])->nullable();
            $table->timestamp('attended_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('scored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Satu pendaftaran sekali per tahap.
            $table->unique(['stage_id', 'registration_id']);
            $table->index(['registration_id', 'status']);
        });

        Schema::create('ppdb_announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('ppdb_periods')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('body');
            $table->enum('channel', ['portal', 'email', 'whatsapp', 'all'])->default('portal');
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['period_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppdb_announcements');
        Schema::dropIfExists('ppdb_selection_participants');
        Schema::dropIfExists('ppdb_selection_stages');
        Schema::dropIfExists('ppdb_payments');
        Schema::dropIfExists('ppdb_invoice_items');
        Schema::dropIfExists('ppdb_invoices');
    }
};