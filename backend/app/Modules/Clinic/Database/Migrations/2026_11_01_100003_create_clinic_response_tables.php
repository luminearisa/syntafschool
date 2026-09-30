<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Clinic / UKS — tabel respons & tata kelola: kegawatdaruratan, cedera,
 * rujukan, pemulangan, program skrining, imunisasi, surat keterangan sehat,
 * dokumen, kampanye kesehatan, jejak audit, amendment, dan log notifikasi.
 *
 * Dokumen kesehatan disimpan pada disk privat (`clinic` di config/filesystems)
 * dan hanya dapat diunduh via endpoint ber-otorisasi — bukan URL publik.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- Kegawatdaruratan ----------
        Schema::create('clinic_emergencies', function (Blueprint $table): void {
            $table->id();
            $table->string('incident_number', 30)->unique();
            $table->foreignId('visit_id')->nullable()->constrained('clinic_visits')->nullOnDelete();
            $table->foreignId('patient_id')->constrained('clinic_patients')->cascadeOnDelete();
            $table->foreignId('clinic_id')->nullable()->constrained('clinic_clinics')->nullOnDelete();
            $table->string('severity', 16)->default('URGENT'); // EMERGENCY | URGENT | SEMI_URGENT | NON_URGENT
            $table->dateTime('incident_at');
            $table->string('location', 160)->nullable();
            $table->text('description');
            $table->text('action_taken')->nullable();
            $table->text('stabilization')->nullable();
            $table->boolean('parent_contacted')->default(false);
            $table->dateTime('parent_contacted_at')->nullable();
            $table->foreignId('contacted_guardian_id')->nullable()->constrained('guardians')->nullOnDelete();
            $table->boolean('referral_required')->default(false);
            $table->boolean('ambulance_required')->default(false);
            $table->string('hospital', 191)->nullable();
            $table->string('transport_method', 60)->nullable();
            $table->string('status', 16)->default('OPEN'); // OPEN | STABILIZED | REFERRED | CLOSED
            $table->dateTime('closed_at')->nullable();
            $table->text('outcome')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'incident_at']);
            $table->index(['patient_id', 'incident_at']);
        });

        // ---------- Cedera / kecelakaan ----------
        Schema::create('clinic_injuries', function (Blueprint $table): void {
            $table->id();
            $table->string('incident_number', 30)->unique();
            $table->foreignId('patient_id')->constrained('clinic_patients')->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('clinic_visits')->nullOnDelete();
            $table->foreignId('emergency_id')->nullable()->constrained('clinic_emergencies')->nullOnDelete();
            $table->dateTime('incident_at');
            $table->string('location', 160)->nullable();
            $table->string('activity', 160)->nullable();
            $table->text('witnesses')->nullable();
            $table->string('injury_type', 40)->nullable();  // Fall | Cut | Bruise | Sprain | Burn | Sports Injury | Accident | Other
            $table->string('body_part', 80)->nullable();
            $table->text('mechanism')->nullable();
            $table->string('severity', 12)->default('MINOR'); // MINOR | MODERATE | SEVERE | CRITICAL
            $table->text('treatment')->nullable();
            $table->boolean('referral_required')->default(false);
            $table->boolean('parent_notified')->default(false);
            $table->string('status', 16)->default('OPEN'); // OPEN | TREATED | REFERRED | CLOSED
            $table->text('follow_up')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['patient_id', 'incident_at']);
            $table->index(['status', 'incident_at']);
            $table->index('injury_type');
        });

        // ---------- Rujukan ke fasilitas kesehatan ----------
        Schema::create('clinic_referrals', function (Blueprint $table): void {
            $table->id();
            $table->string('referral_number', 30)->unique();
            $table->foreignId('visit_id')->nullable()->constrained('clinic_visits')->nullOnDelete();
            $table->foreignId('patient_id')->constrained('clinic_patients')->cascadeOnDelete();
            $table->foreignId('emergency_id')->nullable()->constrained('clinic_emergencies')->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('facility', 191);
            $table->string('facility_type', 30)->nullable(); // PUSKESMAS | CLINIC | HOSPITAL | SPECIALIST | LAB | OTHER
            $table->text('reason');
            $table->string('diagnosis_summary', 255)->nullable();
            $table->string('urgency', 16)->default('ROUTINE'); // EMERGENCY | URGENT | ROUTINE
            $table->dateTime('referral_date');
            $table->string('transport', 60)->nullable();
            $table->string('status', 16)->default('PLANNED'); // PLANNED | REFERRED | IN_TRANSIT | ARRIVED | COMPLETED | CANCELLED
            $table->text('notes')->nullable();
            $table->dateTime('arrived_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->text('outcome')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'referral_date']);
            $table->index(['patient_id', 'referral_date']);
            $table->index('urgency');
        });

        Schema::table('clinic_follow_ups', function (Blueprint $table): void {
            $table->foreign('referral_id')->references('id')->on('clinic_referrals')->nullOnDelete();
        });

        // ---------- Pemulangan ----------
        Schema::create('clinic_discharges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->unique()->constrained('clinic_visits')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('clinic_patients')->cascadeOnDelete();
            $table->dateTime('discharge_time');
            $table->string('status', 24)->default('RETURN_TO_CLASS'); // RETURN_TO_CLASS | RETURN_TO_DORM | HOME | REFERRED | HOSPITALIZED | OTHER
            $table->string('condition', 24)->default('STABLE'); // RECOVERED | IMPROVING | STABLE | UNCHANGED | WORSENED
            $table->text('instructions')->nullable();
            $table->boolean('follow_up_required')->default(false);
            $table->date('follow_up_date')->nullable();
            $table->boolean('return_to_class')->default(true);
            $table->boolean('return_to_dorm')->default(false);
            $table->boolean('sent_home')->default(false);
            $table->boolean('referred')->default(false);
            $table->boolean('parent_notified')->default(false);
            $table->foreignId('discharged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_current')->default(true);
            $table->foreignId('amended_from_id')->nullable()->constrained('clinic_discharges')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'discharge_time']);
        });

        // ---------- Program skrining kesehatan ----------
        Schema::create('clinic_screening_programs', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 160);
            $table->string('type', 24)->default('GENERAL'); // GENERAL | VISION | HEARING | DENTAL | NUTRITION | GROWTH | OTHER
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->string('status', 16)->default('DRAFT'); // DRAFT | ACTIVE | COMPLETED | CANCELLED
            $table->text('description')->nullable();
            $table->boolean('notify_parent')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'period_start']);
        });

        // ---------- Hasil skrining ----------
        Schema::create('clinic_screenings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('program_id')->nullable()->constrained('clinic_screening_programs')->nullOnDelete();
            $table->foreignId('patient_id')->constrained('clinic_patients')->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('clinic_id')->nullable()->constrained('clinic_clinics')->nullOnDelete();
            $table->string('type', 24)->default('GENERAL');
            $table->date('screened_at');
            $table->decimal('height_cm', 6, 2)->nullable();
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->decimal('bmi', 5, 2)->nullable();
            $table->string('bmi_category', 24)->nullable();
            $table->string('vision_left', 12)->nullable();
            $table->string('vision_right', 12)->nullable();
            $table->string('hearing_left', 24)->nullable();
            $table->string('hearing_right', 24)->nullable();
            $table->string('dental_status', 60)->nullable();
            $table->string('result', 20)->default('NORMAL'); // NORMAL | NEEDS_ATTENTION | REFERRAL
            $table->text('findings')->nullable();
            $table->text('recommendation')->nullable();
            $table->boolean('follow_up_required')->default(false);
            $table->boolean('parent_notified')->default(false);
            $table->foreignId('screened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['patient_id', 'screened_at']);
            $table->index(['program_id', 'result']);
            $table->index(['type', 'screened_at']);
        });

        // ---------- Imunisasi ----------
        Schema::create('clinic_vaccinations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('clinic_patients')->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('clinic_id')->nullable()->constrained('clinic_clinics')->nullOnDelete();
            $table->string('vaccine', 120);
            $table->unsignedSmallInteger('dose')->default(1);
            $table->date('given_at');
            $table->string('batch_number', 60)->nullable();
            $table->string('provider', 160)->nullable();
            $table->string('location', 160)->nullable();
            $table->date('next_due_at')->nullable();
            $table->text('reaction')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('consent_obtained')->default(false);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['patient_id', 'given_at']);
            $table->index('next_due_at');
        });

        // ---------- Surat keterangan kesehatan / clearance ----------
        Schema::create('clinic_health_certificates', function (Blueprint $table): void {
            $table->id();
            $table->string('certificate_number', 40)->unique();
            $table->foreignId('patient_id')->constrained('clinic_patients')->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('clinic_visits')->nullOnDelete();
            $table->string('type', 32)->default('RETURN_TO_SCHOOL'); // SCHOOL_ACTIVITY | SPORTS | BOARDING | EVENT | RETURN_TO_SCHOOL | MEDICAL_LEAVE | OTHER
            $table->string('status', 16)->default('REQUESTED'); // REQUESTED | UNDER_REVIEW | APPROVED | REJECTED | EXPIRED
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->text('purpose')->nullable();
            $table->text('clinical_summary')->nullable();
            $table->text('restrictions')->nullable();
            $table->text('recommendation')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('verification_code', 16)->unique();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('issued_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'type']);
            $table->index(['patient_id', 'issued_at']);
        });

        // ---------- Dokumen kesehatan (versi & terkontrol) ----------
        Schema::create('clinic_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('document_number', 40)->unique();
            $table->string('documentable_type', 120)->nullable();
            $table->unsignedBigInteger('documentable_id')->nullable();
            $table->foreignId('patient_id')->nullable()->constrained('clinic_patients')->cascadeOnDelete();
            $table->string('type', 32)->default('OTHER'); // MEDICAL_CERTIFICATE | REFERRAL_LETTER | TREATMENT_NOTE | HEALTH_CLEARANCE | MEDICATION_INSTRUCTION | RETURN_TO_SCHOOL | OTHER
            $table->string('title', 191);
            $table->string('disk', 30)->default('clinic');
            $table->string('path');
            $table->string('original_name', 191)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_current')->default(true);
            $table->foreignId('replaces_id')->nullable()->constrained('clinic_documents')->nullOnDelete();
            $table->string('visibility', 20)->default('CLINICAL'); // CLINICAL | PARENT | STAFF | PUBLIC
            $table->boolean('is_signed')->default(false);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['documentable_type', 'documentable_id']);
            $table->index(['patient_id', 'type']);
            $table->index('visibility');
        });

        // ---------- Edukasi & kampanye kesehatan ----------
        Schema::create('clinic_health_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('title', 191);
            $table->string('type', 24)->default('EDUCATION'); // EDUCATION | CAMPAIGN | ANNOUNCEMENT | POSTER | ARTICLE | WORKSHOP | SEMINAR
            $table->string('topic', 80)->nullable();
            $table->text('summary')->nullable();
            $table->longText('content')->nullable();
            $table->string('audience', 40)->default('STUDENT'); // STUDENT | PARENT | STAFF | ALL
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 16)->default('DRAFT'); // DRAFT | PUBLISHED | ARCHIVED
            $table->string('attachment_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'start_date']);
        });

        // ---------- Jejak audit klinis (akses & perubahan) ----------
        Schema::create('clinic_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('action', 24); // VIEW | CREATE | UPDATE | DELETE | EXPORT | PRINT | SHARE | DISPENSE | ADMINISTER | AMEND
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('patient_id')->nullable()->constrained('clinic_patients')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 40)->nullable();
            $table->string('access_reason', 191)->nullable();
            $table->json('changes')->nullable();
            $table->json('context')->nullable(); // ip, user agent, request id
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['patient_id', 'occurred_at']);
            $table->index(['actor_id', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('action');
        });

        // ---------- Amendment/versi catatan klinis penting ----------
        Schema::create('clinic_record_amendments', function (Blueprint $table): void {
            $table->id();
            $table->string('record_type', 120);
            $table->unsignedBigInteger('record_id');
            $table->unsignedInteger('version');
            $table->string('reason', 255);
            $table->json('previous_value')->nullable();
            $table->json('new_value')->nullable();
            $table->foreignId('patient_id')->nullable()->constrained('clinic_patients')->nullOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['record_type', 'record_id']);
            $table->index(['patient_id', 'changed_at']);
        });

        // ---------- Log notifikasi (kebijakan privasi pemberitahuan) ----------
        Schema::create('clinic_notification_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('event', 40);
            $table->foreignId('patient_id')->nullable()->constrained('clinic_patients')->nullOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('clinic_visits')->nullOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 20)->default('DATABASE');
            $table->string('audience', 20)->default('PARENT'); // PARENT | STAFF | STUDENT
            $table->string('title', 191);
            $table->text('body')->nullable();
            $table->string('status', 16)->default('QUEUED'); // QUEUED | SENT | FAILED | SUPPRESSED
            $table->string('suppressed_reason', 120)->nullable();
            $table->string('dedupe_key', 64)->nullable()->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['event', 'created_at']);
            $table->index(['patient_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('clinic_follow_ups', function (Blueprint $table): void {
            $table->dropForeign(['referral_id']);
        });

        foreach ([
            'clinic_notification_logs', 'clinic_record_amendments', 'clinic_audit_logs',
            'clinic_health_campaigns', 'clinic_documents', 'clinic_health_certificates',
            'clinic_vaccinations', 'clinic_screenings', 'clinic_screening_programs',
            'clinic_discharges', 'clinic_referrals', 'clinic_injuries', 'clinic_emergencies',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
