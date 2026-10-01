<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rencana pengembangan siswa (Student Development Plan).
 *
 * Dipakai untuk menumbuhkan, bukan hanya menangani: setiap sasaran membawa
 * baseline, target, dan cara mengukurnya, serta riwayat peninjauan berurutan.
 *
 * Catatan desain: kolom status/kategori memakai `string` dengan daftar nilai yang
 * dijaga PHP Enum, bukan `enum()` basis data. Alasannya: modul BK sudah menambah
 * nilai beberapa kali, dan CHECK constraint bawaan `enum()` membuat penambahan
 * nilai baru selalu berarti migrasi pemecahan tabel — di MySQL maupun SQLite.
 * Validasi tetap ada, hanya dipindahkan ke tempat yang benar (enum + Form Request).
 *
 * `school_id` nullable adalah penopang isolasi unit sekolah. Instalasi satu
 * sekolah membiarkannya kosong dan perilaku modelnya sama seperti sebelumnya;
 * instalasi yayasan dengan beberapa unit mengisinya, dan global scope
 * `BelongsToSchool` menutup data antar-unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bk_development_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('plan_number', 32)->unique();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('bk_cases')->nullOnDelete();
            /*
             | Pengembangan, bukan penanganan masalah. Domainnya mencakup hal yang
             | tumbuh pada anak sehat, sehingga rencana tidak menuntut adanya kasus.
             */
            $table->string('domain', 32);                            // ACADEMIC|CHARACTER|LEADERSHIP|SOCIAL|COMMUNICATION|CAREER|SPIRITUAL_DEVELOPMENT|EXTRACURRICULAR|INDEPENDENCE
            $table->string('title', 180);
            $table->text('rationale')->nullable();
            $table->text('baseline_summary')->nullable();
            $table->string('status', 20)->default('DRAFT');          // DRAFT|ACTIVE|IN_PROGRESS|ON_HOLD|COMPLETED|CANCELLED
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('target_end_date')->nullable();
            $table->unsignedInteger('review_interval_days')->default(30);
            $table->date('next_review_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('achievement_note')->nullable();
            $table->string('confidentiality_level', 24)->default('STAFF_ONLY');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['student_id', 'domain', 'status'], 'bk_dev_plans_student_domain');
            $table->index(['school_id', 'status'], 'bk_dev_plans_school');
            $table->index(['next_review_date', 'status'], 'bk_dev_plans_review');
        });

        Schema::create('bk_development_goals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('bk_development_plans')->cascadeOnDelete();
            $table->string('goal', 200);
            // Baseline → target → pengukuran: tanpa tiga ini, "kemajuan" hanya klaim.
            $table->decimal('baseline_value', 12, 4)->nullable();
            $table->decimal('target_value', 12, 4)->nullable();
            $table->string('measurement', 160)->nullable();
            $table->string('measurement_unit', 32)->nullable();
            $table->decimal('current_value', 12, 4)->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('status', 20)->default('NOT_STARTED');    // NOT_STARTED|IN_PROGRESS|ON_TRACK|AT_RISK|ACHIEVED|CANCELLED
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('target_date')->nullable();
            $table->unsignedInteger('weight')->default(1);
            $table->timestamp('achieved_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['plan_id', 'status'], 'bk_dev_goals_plan');
            $table->index(['status', 'target_date'], 'bk_dev_goals_due');
        });

        Schema::create('bk_development_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('bk_development_plans')->cascadeOnDelete();
            $table->foreignId('goal_id')->nullable()->constrained('bk_development_goals')->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('activity_type', 32)->default('OTHER');   // TRAINING|MENTORING|PRACTICE|PROJECT|ASSIGNMENT|OBSERVATION|WORKSHOP|VOLUNTEERING|COMPETITION|OTHER
            $table->date('scheduled_date')->nullable();
            $table->date('completed_date')->nullable();
            $table->string('status', 20)->default('PLANNED');        // PLANNED|SCHEDULED|IN_PROGRESS|COMPLETED|MISSED|CANCELLED
            $table->foreignId('facilitator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('evidence')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('bk_case_documents')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['plan_id', 'status'], 'bk_dev_activities_plan');
            $table->index(['scheduled_date', 'status'], 'bk_dev_activities_date');
        });

        Schema::create('bk_development_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('bk_development_plans')->cascadeOnDelete();
            $table->foreignId('goal_id')->nullable()->constrained('bk_development_goals')->nullOnDelete();
            // Baseline → Review 1..n → Final: urutan dijaga kolom review_number +
            // review_type, sehingga riwayat kemajuan tidak dapat ditulis mundur.
            $table->unsignedInteger('review_number')->default(1);
            $table->string('review_type', 20)->default('REVIEW');    // BASELINE|REVIEW|FINAL|ADHOC
            $table->date('reviewed_on');
            $table->foreignId('reviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('progress_value', 12, 4)->nullable();
            $table->unsignedTinyInteger('progress_percent')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('progress_note')->nullable();
            $table->string('status_before', 20)->nullable();
            $table->string('status_after', 20)->nullable();
            $table->date('next_review_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['plan_id', 'goal_id', 'review_number'], 'bk_dev_reviews_sequence');
            $table->index(['plan_id', 'reviewed_on'], 'bk_dev_reviews_plan_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bk_development_reviews');
        Schema::dropIfExists('bk_development_activities');
        Schema::dropIfExists('bk_development_goals');
        Schema::dropIfExists('bk_development_plans');
    }
};
