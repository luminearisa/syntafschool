<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Policy jatah gratis berversi (§5), entitlement per periode (§6/§7), dan
 * ledger entitlement (§13).
 *
 * Prinsip: policy dipublikasikan sebagai versi yang tidak pernah diubah;
 * entitlement historis tidak pernah dimodifikasi oleh perubahan policy
 * (Rule 22). Setiap perubahan jatah tercatat sebagai baris ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laundry_policies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('laundry_policy_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_policy_id')->constrained('laundry_policies')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            // WEIGHT / ORDER / PIECE / AMOUNT (§6).
            $table->string('quota_type', 10)->default('WEIGHT');
            // kg / order / piece / rupiah.
            $table->decimal('quota_amount', 12, 3)->default(0);
            // DAILY/WEEKLY/MONTHLY/SEMESTER/ACADEMIC_YEAR/CUSTOM (§7).
            $table->string('period_type', 20)->default('MONTHLY');
            $table->date('custom_period_start')->nullable();
            $table->date('custom_period_end')->nullable();
            // NO_CARRY_OVER / FULL_CARRY_OVER / LIMITED_CARRY_OVER (§8).
            $table->string('carry_over_mode', 20)->default('NO_CARRY_OVER');
            $table->decimal('carry_over_limit', 12, 3)->nullable();
            // Batas berat gratis per order utk quota ORDER (null = tanpa batas).
            $table->decimal('max_free_weight_per_order', 8, 3)->nullable();
            // Scope penerapan (§5) — null berarti tidak membatasi.
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('dormitory_id')->nullable()
                ->constrained('boarding_dormitories')->nullOnDelete();
            $table->foreignId('education_level_id')->nullable()
                ->constrained('education_levels')->nullOnDelete();
            // Kategori santri, mis. BOARDING / DAY.
            $table->string('student_category', 30)->nullable();
            $table->foreignId('academic_year_id')->nullable()
                ->constrained('academic_years')->nullOnDelete();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->integer('priority')->default(0);
            // DRAFT / PUBLISHED / ARCHIVED.
            $table->string('status', 20)->default('DRAFT');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['laundry_policy_id', 'version']);
            $table->index(['status', 'effective_from', 'effective_until']);
            $table->index(['dormitory_id', 'status']);
            $table->index(['education_level_id', 'status']);
        });

        Schema::create('laundry_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('laundry_policy_id')->nullable()
                ->constrained('laundry_policies')->nullOnDelete();
            $table->foreignId('laundry_policy_version_id')->nullable()
                ->constrained('laundry_policy_versions')->nullOnDelete();
            $table->string('period_type', 20)->default('MONTHLY');
            // Kunci periode stabil, mis. 2026-10 / 2026-W41 / SEM-3.
            $table->string('period_key', 40);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('quota_type', 10)->default('WEIGHT');
            $table->decimal('allocated_quota', 12, 3)->default(0);
            // Sisa periode sebelumnya yang dibawa (§8).
            $table->decimal('carry_in_quota', 12, 3)->default(0);
            // Net adjustment admin (+/-), selalu beralasan & teraudit (§14).
            $table->decimal('adjusted_quota', 12, 3)->default(0);
            $table->decimal('used_quota', 12, 3)->default(0);
            // ACTIVE / EXPIRED / EXHAUSTED / CLOSED.
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            // Satu entitlement per santri per periode (Rule: new period → new
            // entitlement; historical tidak diubah).
            $table->unique(['student_id', 'period_key']);
            $table->index(['student_id', 'status']);
            $table->index(['period_start', 'period_end']);
            $table->index('status');
        });

        Schema::create('laundry_entitlement_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laundry_entitlement_id')
                ->constrained('laundry_entitlements')->restrictOnDelete();
            // Denormalisasi untuk laporan per santri tanpa join.
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            // ALLOCATE / CARRY_IN / USE / ADJUST / EXPIRE / REVERSE (§13).
            $table->string('transaction_type', 20);
            // Bertanda: ALLOCATE +, USE -, ADJUST +/-, EXPIRE -, REVERSE +.
            $table->decimal('amount', 12, 3);
            // Sisa jatah setelah baris ini — rantai audit seperti wallet ledger.
            $table->decimal('balance_after', 12, 3);
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['laundry_entitlement_id', 'id']);
            $table->index(['student_id', 'transaction_type']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laundry_entitlement_transactions');
        Schema::dropIfExists('laundry_entitlements');
        Schema::dropIfExists('laundry_policy_versions');
        Schema::dropIfExists('laundry_policies');
    }
};
