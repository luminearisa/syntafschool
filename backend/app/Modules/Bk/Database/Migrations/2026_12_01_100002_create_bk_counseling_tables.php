<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul BK — tabel layanan: screening, mesin asesmen, janji temu, sesi
 * konseling, rencana & sasaran, intervensi, tindak lanjut, dan permintaan
 * dukungan dari portal siswa/orang tua.
 *
 * Catatan privasi: kolom `counselor_note` pada sesi konseling adalah catatan
 * konselor (COUNSELOR_ONLY) dan **tidak pernah** ikut pada daftar, notifikasi,
 * log aplikasi, atau ekspor. Pemisahan kolom `session_summary` (dapat dibagikan
 * sesuai level) dari `counselor_note` (rahasia) dilakukan di skema agar tidak
 * bergantung pada disiplin penulis kode.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- Screening awal ----------
        Schema::create('bk_screenings', function (Blueprint $table): void {
            $table->id();
            $table->string('screening_number', 32)->unique();       // BK-S-2026-000001
            $table->foreignId('referral_id')->nullable()->constrained('bk_referrals')->nullOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('bk_cases')->nullOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('screened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('screened_at');
            $table->text('presenting_concern');
            $table->text('observation')->nullable();
            $table->text('student_perspective')->nullable();
            $table->text('teacher_observation')->nullable();
            $table->text('parent_observation')->nullable();
            $table->json('academic_indicators')->nullable();
            $table->json('attendance_indicators')->nullable();
            $table->json('behavioral_indicators')->nullable();
            $table->json('boarding_indicators')->nullable();
            $table->text('previous_support')->nullable();
            $table->boolean('immediate_support_needed')->default(false);
            $table->string('recommended_outcome', 32)->default('MONITOR'); // ScreeningOutcome
            $table->text('recommendation_notes')->nullable();
            $table->date('follow_up_date')->nullable();
            $table->string('confidentiality_level', 24)->default('RESTRICTED');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['student_id', 'screened_at'], 'bk_screenings_student');
            $table->index(['referral_id', 'screened_at'], 'bk_screenings_referral');
        });

        // ---------- Mesin asesmen yang dapat dikonfigurasi ----------
        Schema::create('bk_assessment_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 160);
            $table->string('target', 16)->default('STUDENT');       // AssessmentTarget
            $table->text('description')->nullable();
            $table->string('purpose', 120)->nullable();             // mis. "memetakan kebiasaan belajar"
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('version')->default(1);
            $table->boolean('requires_consent')->default(false);     // asesmen ke orang tua/siswa
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['target', 'is_active']);
        });

        Schema::create('bk_assessment_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained('bk_assessment_templates')->cascadeOnDelete();
            $table->string('code', 40)->nullable();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['template_id', 'sort_order'], 'bk_sections_order');
        });

        Schema::create('bk_assessment_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained('bk_assessment_templates')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('bk_assessment_sections')->cascadeOnDelete();
            $table->string('code', 40);
            $table->text('question');
            $table->string('question_type', 24)->default('TEXT');   // AssessmentQuestionType
            $table->json('options')->nullable();                    // untuk pilihan/peringkat
            $table->decimal('min_value', 8, 2)->nullable();         // untuk SCALE/NUMBER
            $table->decimal('max_value', 8, 2)->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_sensitive')->default(false);        // jawabannya tidak ikut ringkasan umum
            $table->boolean('is_scored')->default(false);           // ikut dihitung skor total
            $table->string('help_text', 191)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['template_id', 'code']);
            $table->index(['section_id', 'sort_order'], 'bk_questions_order');
        });

        Schema::create('bk_assessments', function (Blueprint $table): void {
            $table->id();
            $table->string('assessment_number', 32)->unique();      // BK-A-2026-000001
            $table->foreignId('template_id')->constrained('bk_assessment_templates')->restrictOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('bk_cases')->nullOnDelete();
            $table->foreignId('referral_id')->nullable()->constrained('bk_referrals')->nullOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('target', 16)->default('STUDENT');       // kepada siapa asesmen diberikan
            $table->foreignId('respondent_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('respondent_name', 120)->nullable();     // responden tanpa akun (mis. orang tua via tautan)
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->date('due_date')->nullable();
            $table->string('status', 24)->default('DRAFT');         // AssessmentStatus
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('summary')->nullable();                    // ringkasan konselor, bukan diagnosis
            $table->decimal('total_score', 10, 2)->nullable();
            $table->string('confidentiality_level', 24)->default('COUNSELOR_ONLY');
            $table->boolean('consent_required')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['case_id', 'status'], 'bk_assessments_case');
            $table->index(['student_id', 'status'], 'bk_assessments_student');
            $table->index(['target', 'status'], 'bk_assessments_target');
        });

        Schema::create('bk_assessment_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assessment_id')->constrained('bk_assessments')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('bk_assessment_questions')->cascadeOnDelete();
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 10, 2)->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->date('value_date')->nullable();
            $table->json('value_options')->nullable();
            $table->decimal('score', 10, 2)->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['assessment_id', 'question_id'], 'bk_responses_unique');
        });

        // ---------- Janji temu & ketersediaan ----------
        Schema::create('bk_appointments', function (Blueprint $table): void {
            $table->id();
            $table->string('appointment_number', 32)->unique();     // BK-APT-2026-000001
            $table->foreignId('case_id')->nullable()->constrained('bk_cases')->nullOnDelete();
            $table->foreignId('referral_id')->nullable()->constrained('bk_referrals')->nullOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('counselor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('session_type', 24)->default('INDIVIDUAL');
            $table->string('mode', 16)->default('IN_PERSON');
            $table->timestamp('scheduled_start');
            $table->timestamp('scheduled_end');
            $table->string('location', 120)->nullable();
            $table->unsignedBigInteger('room_id')->nullable();      // rujukan ke modul Sarpras
            $table->text('purpose')->nullable();                    // ringkasan non-sensitif
            $table->string('status', 16)->default('SCHEDULED');     // AppointmentStatus
            $table->string('attendance_status', 16)->default('UNKNOWN');
            $table->unsignedBigInteger('rescheduled_from_id')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 191)->nullable();
            $table->string('recurrence_rule', 60)->nullable();      // mis. "WEEKLY:2"
            $table->unsignedBigInteger('series_parent_id')->nullable();
            $table->string('conflict_state', 16)->default('NONE');  // NONE | CONFLICT | OVERRIDDEN
            $table->json('conflict_details')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->string('confidentiality_level', 24)->default('COUNSELOR_ONLY');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['counselor_id', 'scheduled_start'], 'bk_appointments_counselor');
            $table->index(['student_id', 'scheduled_start'], 'bk_appointments_student');
            $table->index(['status', 'scheduled_start'], 'bk_appointments_queue');
        });

        // ---------- Sesi konseling ----------
        Schema::create('bk_counseling_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('session_number', 32)->unique();         // BK-SES-2026-000001
            $table->foreignId('case_id')->constrained('bk_cases')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('counselor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('bk_appointments')->nullOnDelete();
            $table->string('session_type', 24)->default('INDIVIDUAL');
            $table->string('mode', 16)->default('IN_PERSON');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('location', 120)->nullable();
            $table->string('attendance_status', 16)->default('UNKNOWN');
            $table->string('status', 16)->default('SCHEDULED');     // SessionStatus
            $table->text('objective')->nullable();
            $table->text('session_summary')->nullable();            // dapat dibagikan sesuai level
            $table->text('counselor_note')->nullable();             // RAHASIA (COUNSELOR_ONLY)
            $table->text('next_action')->nullable();
            $table->string('risk_indicator', 24)->nullable();       // NONE | LOW | MODERATE | HIGH (operasional)
            $table->boolean('parent_informed')->default(false);
            $table->string('confidentiality_level', 24)->default('COUNSELOR_ONLY');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['case_id', 'scheduled_at'], 'bk_sessions_case');
            $table->index(['counselor_id', 'scheduled_at'], 'bk_sessions_counselor');
        });

        // ---------- Rencana konseling & sasaran ----------
        Schema::create('bk_counseling_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('plan_number', 32)->unique();            // BK-P-2026-000001
            $table->foreignId('case_id')->constrained('bk_cases')->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('problem_statement');
            $table->foreignId('counselor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('started_on');
            $table->date('target_end_on')->nullable();
            $table->string('status', 16)->default('DRAFT');         // PlanStatus
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->string('confidentiality_level', 24)->default('COUNSELOR_ONLY');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['case_id', 'status']);
        });

        Schema::create('bk_goals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('bk_counseling_plans')->cascadeOnDelete();
            $table->foreignId('case_id')->constrained('bk_cases')->cascadeOnDelete();
            $table->text('goal');
            $table->text('objective')->nullable();
            $table->text('success_indicator')->nullable();
            $table->string('intervention_approach', 160)->nullable();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('timeline_start')->nullable();
            $table->date('timeline_end')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);     // 0–100, ringkasan kemajuan
            $table->string('status', 24)->default('PENDING');       // GoalStatus
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['plan_id', 'status']);
            $table->index(['case_id', 'status']);
        });

        // ---------- Intervensi ----------
        Schema::create('bk_interventions', function (Blueprint $table): void {
            $table->id();
            $table->string('intervention_number', 32)->unique();    // BK-I-2026-000001
            $table->foreignId('case_id')->constrained('bk_cases')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('bk_counseling_plans')->nullOnDelete();
            $table->foreignId('goal_id')->nullable()->constrained('bk_goals')->nullOnDelete();
            $table->string('type', 32);                             // InterventionType
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('owner_role', 40)->nullable();           // bila pelaksana bukan pengguna sistem
            $table->string('owner_name', 120)->nullable();
            $table->date('start_date');
            $table->date('target_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 16)->default('PLANNED');       // InterventionStatus
            $table->text('result')->nullable();
            $table->boolean('follow_up_required')->default(false);
            $table->text('notes')->nullable();
            $table->string('confidentiality_level', 24)->default('COUNSELOR_ONLY');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['case_id', 'status'], 'bk_interventions_case');
            $table->index(['owner_user_id', 'status'], 'bk_interventions_owner');
            $table->index(['target_date', 'status'], 'bk_interventions_due');
        });

        // ---------- Tindak lanjut ----------
        Schema::create('bk_follow_ups', function (Blueprint $table): void {
            $table->id();
            $table->string('follow_up_number', 32)->unique();       // BK-F-2026-000001
            $table->foreignId('case_id')->constrained('bk_cases')->cascadeOnDelete();
            $table->foreignId('intervention_id')->nullable()->constrained('bk_interventions')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 24)->default('CHECK_IN');        // FollowUpType
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->date('due_date');
            $table->time('due_time')->nullable();
            $table->string('status', 16)->default('PENDING');       // FollowUpStatus
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('outcome')->nullable();
            $table->text('next_action')->nullable();
            $table->unsignedSmallInteger('reschedule_count')->default(0);
            $table->date('rescheduled_to')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->boolean('is_overdue')->default(false);          // penanda untuk widget & perintah terjadwal
            $table->string('confidentiality_level', 24)->default('COUNSELOR_ONLY');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['assigned_to', 'status', 'due_date'], 'bk_followups_owner');
            $table->index(['case_id', 'status'], 'bk_followups_case');
            $table->index(['status', 'due_date'], 'bk_followups_due');
        });

        // ---------- Permintaan dukungan dari portal siswa/orang tua ----------
        Schema::create('bk_support_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_number', 32)->unique();         // BK-SR-2026-000001
            $table->string('request_type', 16)->default('STUDENT_SELF'); // SupportRequestType
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('guardian_id')->nullable()->constrained('guardians')->nullOnDelete();
            $table->string('topic', 160);                            // cukup topik, bukan detail sensitif
            $table->string('preferred_time_slot', 60)->nullable();
            $table->text('message')->nullable();                     // opsional & sukarela
            $table->boolean('urgent')->default(false);
            $table->string('status', 16)->default('QUEUED');        // SupportRequestStatus
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('referral_id')->nullable()->constrained('bk_referrals')->nullOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('bk_cases')->nullOnDelete();
            $table->string('decline_reason', 191)->nullable();
            $table->string('confidentiality_level', 24)->default('RESTRICTED');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'request_type'], 'bk_requests_queue');
            $table->index(['student_id', 'status'], 'bk_requests_student');
        });
    }

    public function down(): void
    {
        foreach ([
            'bk_support_requests', 'bk_follow_ups', 'bk_interventions', 'bk_goals', 'bk_counseling_plans',
            'bk_counseling_sessions', 'bk_appointments', 'bk_assessment_responses', 'bk_assessments',
            'bk_assessment_questions', 'bk_assessment_sections', 'bk_assessment_templates', 'bk_screenings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
