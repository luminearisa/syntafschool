<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda dukungan siswa + mesin aturan peringatan dini.
 *
 * Satu tabel aturan (`bk_early_warning_rules`) menyimpan ambang sebagai data,
 * dan satu tabel peristiwa (`bk_early_warning_events`) menyimpan jejak setiap
 * evaluasi beserta kunci idempotensinya.
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
        Schema::create('bk_risk_indicators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            /*
             | Istilah yang dipakai sengaja bukan bahasa klinis. Penanda ini adalah
             | *perhatian*, bukan diagnosis: yang dikatakan hanyalah "ada yang perlu
             | dilihat", bukan "anak ini mengalami …".
             */
            $table->string('indicator_type', 24)->default('RISK_INDICATOR');   // RISK_INDICATOR|SUPPORT_INDICATOR|ATTENTION_REQUIRED
            $table->string('indicator_code', 48);                              // REPEATED_ABSENCE|ACADEMIC_DECLINE|REPEATED_DISCIPLINE_INCIDENT|MULTIPLE_REFERRALS|SUDDEN_WITHDRAWAL|BOARDING_ADJUSTMENT_CONCERN|REPEATED_FOLLOW_UP_FAILURE|CLINIC_REPEAT_VISIT|ACTIVITY_DISENGAGEMENT|CUSTOM
            $table->string('label', 160)->nullable();
            $table->string('severity', 16)->default('MODERATE');                // LOW|MODERATE|HIGH|CRITICAL
            $table->string('source_module', 32);                                // ATTENDANCE|ACADEMIC|DISCIPLINE|BOARDING|ACTIVITY|CLINIC|TEACHER_REFERRAL|PARENT_REFERRAL|SELF_REFERRAL|SYSTEM
            $table->string('status', 20)->default('OPEN');                      // OPEN|ACKNOWLEDGED|UNDER_REVIEW|REVIEWED|RESOLVED|DISMISSED|EXPIRED
            $table->timestamp('detected_at');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('observed_value', 12, 4)->nullable();
            $table->string('observed_unit', 32)->nullable();
            $table->decimal('threshold_value', 12, 4)->nullable();
            $table->json('evidence')->nullable();
            $table->text('summary')->nullable();
            $table->foreignId('rule_id')->nullable()->constrained('bk_early_warning_rules')->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('bk_early_warning_events')->nullOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('bk_cases')->nullOnDelete();
            $table->foreignId('referral_id')->nullable()->constrained('bk_referrals')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            // Kunci anti-duplikasi: satu indikator per siswa/aturan/periode,
            // sehingga evaluasi aturan berulang tidak menumpuk penanda sama.
            $table->string('dedup_key', 191)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['student_id', 'status'], 'bk_risk_indicators_student');
            $table->index(['school_id', 'status', 'severity'], 'bk_risk_indicators_school');
            $table->index(['indicator_code', 'detected_at'], 'bk_risk_indicators_code');
            $table->index(['source_module', 'status'], 'bk_risk_indicators_source');
        });

        Schema::create('bk_early_warning_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('code', 64);
            $table->unsignedInteger('version')->default(1);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            /*
             | Ambang TIDAK PERNAH ditulis di kode: setiap aturan membawa metrik,
             | operator, dan ambangnya sendiri di basis data, sehingga sekolah dapat
             | mengubah kebijakan tanpa deploy.
             */
            $table->string('metric', 48);                            // ATTENDANCE_RATE|ABSENCE_COUNT|LATE_COUNT|DISCIPLINE_COUNT|DISCIPLINE_POINTS|ACADEMIC_AVERAGE|ACADEMIC_CHANGE|SUBJECT_BELOW_THRESHOLD_COUNT|REFERRAL_COUNT|FOLLOW_UP_FAILURE_COUNT|CLINIC_VISIT_COUNT|BOARDING_ADJUSTMENT_COUNT|ACTIVITY_DISENGAGEMENT_DAYS
            $table->string('operator', 4);                           // LT|LTE|GT|GTE|EQ|NE
            $table->decimal('threshold', 12, 4);
            $table->string('threshold_unit', 24)->nullable();
            $table->unsignedInteger('window_days')->default(30);
            $table->string('action', 40);                            // CREATE_SUPPORT_INDICATOR|CREATE_BK_REVIEW|CREATE_ACADEMIC_SUPPORT_INDICATOR|CREATE_REFERRAL|NOTIFY_COORDINATOR
            $table->json('action_params')->nullable();
            $table->string('indicator_code', 48)->nullable();
            $table->string('severity', 16)->default('MODERATE');
            $table->unsignedInteger('cooldown_days')->default(7);
            $table->json('scope')->nullable();                        // {"grades":[7,8],"class_ids":[],"genders":[]}
            $table->boolean('requires_review')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'code', 'version'], 'bk_ew_rules_version');
            $table->index(['is_active', 'metric'], 'bk_ew_rules_active_metric');
        });

        Schema::create('bk_early_warning_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained('bk_early_warning_rules')->nullOnDelete();
            $table->unsignedInteger('rule_version')->default(1);
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('metric', 48);
            $table->decimal('observed_value', 12, 4)->nullable();
            $table->decimal('threshold', 12, 4)->nullable();
            $table->unsignedInteger('window_days')->default(30);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('evaluation_date');
            $table->boolean('matched')->default(false);
            $table->string('status', 20)->default('DETECTED');       // DETECTED|ACTIONED|SUPPRESSED|SKIPPED|FAILED|COOLDOWN
            $table->string('action_taken', 40)->nullable();
            $table->foreignId('indicator_id')->nullable()->constrained('bk_risk_indicators')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('context')->nullable();
            $table->foreignId('evaluated_by')->nullable()->constrained('users')->nullOnDelete();
            /*
             | Idempotensi: kunci ini membuat evaluasi yang dijalankan berkali-kali
             | (retry antrean, cron ganda, dua pekerja paralel) tetap menghasilkan
             | satu peristiwa dan satu tindakan.
             */
            $table->string('dedup_key', 191)->nullable()->unique();
            $table->timestamp('actioned_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'evaluation_date'], 'bk_ew_events_student');
            $table->index(['rule_id', 'status'], 'bk_ew_events_rule');
            $table->index(['school_id', 'status'], 'bk_ew_events_school');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bk_early_warning_events');
        Schema::dropIfExists('bk_early_warning_rules');
        Schema::dropIfExists('bk_risk_indicators');
    }
};
