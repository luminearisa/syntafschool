<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Clinic / UKS — tabel inti: lokasi klinik, petugas, pasien, antrean,
 * kunjungan, triase, tanda vital, riwayat, alergi, dan kondisi khusus.
 *
 * Seluruh tabel berprefix `clinic_` dan bersifat **tambahan** — tidak ada tabel
 * inti yang diubah. Kolom jenis/status memakai `string` yang nilainya diikat
 * enum PHP di `App\Modules\Clinic\Domain\Enums`, sehingga menambah jenis tidak
 * memerlukan ALTER TABLE.
 *
 * Privasi: siapa boleh melihat baris mana ditegakkan di `ClinicAccessService`
 * (petugas medis: seluruh pasien dalam scope kliniknya, guru: tidak otomatis,
 * orang tua: hanya anaknya, siswa: hanya dirinya) — bukan di skema.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- Lokasi layanan (UKS Putra, UKS Putri, Klinik Boarding, ...) ----------
        Schema::create('clinic_clinics', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->string('type', 24)->default('UKS'); // UKS | CLINIC | BOARDING_CLINIC | OTHER
            $table->string('description', 255)->nullable();
            $table->string('location', 160)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('status', 16)->default('ACTIVE'); // ACTIVE | INACTIVE | MAINTENANCE
            $table->json('operating_hours')->nullable();     // {"mon":"07:00-16:00", ...}
            $table->boolean('is_default')->default(false);
            $table->boolean('supports_observation')->default(true);
            $table->boolean('supports_emergency')->default(true);
            $table->unsignedSmallInteger('bed_capacity')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'type']);
        });

        // ---------- Petugas klinik (nakes & operator UKS) ----------
        Schema::create('clinic_staff', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('clinic_id')->constrained('clinic_clinics')->cascadeOnDelete();
            $table->string('role', 24); // CLINIC_ADMIN | DOCTOR | NURSE | UKS_OFFICER | PHARMACY_OFFICER | CLINIC_RECEPTIONIST
            $table->string('license_number', 60)->nullable();   // STR/SIP untuk tenaga medis
            $table->string('specialization', 120)->nullable();
            $table->string('status', 16)->default('ACTIVE');     // ACTIVE | INACTIVE | SUSPENDED
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Satu orang boleh bertugas di beberapa klinik, tapi hanya satu baris
            // per (user, klinik) agar pengecekan wewenang tidak ambigu.
            $table->unique(['user_id', 'clinic_id']);
            $table->index(['clinic_id', 'status', 'role']);
        });

        // ---------- Rekam medis induk (bukan duplikat master siswa) ----------
        Schema::create('clinic_patients', function (Blueprint $table): void {
            $table->id();
            $table->string('medical_record_number', 24)->unique(); // MR-2026-000001, immutable
            $table->string('patient_type', 20)->default('STUDENT'); // STUDENT | TEACHER | STAFF | BOARDING_STAFF | GUEST | OTHER
            $table->foreignId('student_id')->nullable()->unique()->constrained('students')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('external_ref', 60)->nullable();  // untuk GUEST/OTHER tanpa master siswa
            $table->string('status', 16)->default('ACTIVE'); // ACTIVE | ARCHIVED | RESTRICTED
            $table->string('blood_type', 4)->nullable();
            $table->string('emergency_contact_name', 120)->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();
            $table->string('emergency_contact_relation', 40)->nullable();
            $table->string('insurance_provider', 120)->nullable();
            $table->string('insurance_number', 60)->nullable();
            $table->boolean('allergy_alert')->default(false);    // denormalisasi aman: hanya penanda, bukan isi
            $table->boolean('special_care_alert')->default(false);
            $table->text('internal_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['patient_type', 'status']);
        });

        // ---------- Janji temu ----------
        Schema::create('clinic_appointments', function (Blueprint $table): void {
            $table->id();
            $table->string('appointment_number', 30)->unique();
            $table->foreignId('patient_id')->constrained('clinic_patients')->cascadeOnDelete();
            $table->foreignId('clinic_id')->constrained('clinic_clinics')->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('appointment_date');
            $table->time('appointment_time')->nullable();
            $table->string('purpose', 191);
            $table->string('status', 16)->default('SCHEDULED'); // SCHEDULED | CHECKED_IN | IN_PROGRESS | COMPLETED | CANCELLED | NO_SHOW
            $table->text('notes')->nullable();
            // Kolom ini diisi setelah kunjungan dibuat; batasan foreign key
            // dipasang di akhir up() karena `clinic_visits` didefinisikan setelah
            // tabel ini.
            $table->unsignedBigInteger('visit_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['clinic_id', 'appointment_date', 'status']);
            $table->index(['patient_id', 'appointment_date']);
        });

        // ---------- Kunjungan (inti operasional klinis) ----------
        Schema::create('clinic_visits', function (Blueprint $table): void {
            $table->id();
            $table->string('visit_number', 30)->unique();
            $table->foreignId('patient_id')->constrained('clinic_patients')->cascadeOnDelete();
            $table->foreignId('clinic_id')->constrained('clinic_clinics')->restrictOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete(); // pintasan query scope
            $table->foreignId('appointment_id')->nullable()->constrained('clinic_appointments')->nullOnDelete();
            $table->dateTime('visit_at');
            $table->dateTime('closed_at')->nullable();
            $table->string('visit_type', 20)->default('GENERAL');
            $table->string('source', 16)->default('SELF');     // SELF | TEACHER | PARENT | BOARDING | CLINIC | EMERGENCY
            $table->string('priority', 16)->default('NON_URGENT');
            $table->string('status', 20)->default('REGISTERED');
            $table->string('chief_complaint', 255)->nullable();
            $table->foreignId('complaint_id')->nullable()->constrained('clinic_complaints')->nullOnDelete();
            $table->foreignId('attended_by')->nullable()->constrained('users')->nullOnDelete(); // provider
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('school_class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->boolean('is_emergency')->default(false);
            $table->boolean('requires_observation')->default(false);
            $table->boolean('parent_notified')->default(false);
            $table->dateTime('parent_notified_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique(); // cegah duplikat saat retry jaringan
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'visit_at']);
            $table->index(['clinic_id', 'visit_at']);
            $table->index(['student_id', 'visit_at']);
            $table->index(['patient_id', 'visit_at']);
            $table->index(['visit_type', 'visit_at']);
            $table->index('priority');
        });

        // ---------- Antrean ----------
        Schema::create('clinic_queue_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->unique()->constrained('clinic_visits')->cascadeOnDelete();
            $table->foreignId('clinic_id')->constrained('clinic_clinics')->cascadeOnDelete();
            $table->string('queue_number', 12);
            $table->unsignedInteger('sequence')->default(0);
            $table->date('queue_date');
            $table->string('stage', 20)->default('WAITING'); // WAITING | TRIAGE | WAITING_PROVIDER | IN_EXAMINATION | PHARMACY | DISCHARGE | COMPLETED
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('called_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->index(['clinic_id', 'queue_date', 'stage']);
            $table->index(['clinic_id', 'queue_date', 'sequence']);
        });

        // ---------- Triase ----------
        Schema::create('clinic_triages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->unique()->constrained('clinic_visits')->cascadeOnDelete();
            $table->string('priority', 16)->default('NON_URGENT'); // EMERGENCY | URGENT | SEMI_URGENT | NON_URGENT
            $table->string('chief_complaint', 255);
            $table->unsignedTinyInteger('pain_score')->nullable();          // 0-10
            $table->string('consciousness', 20)->default('ALERT');          // ALERT | VOICE | PAIN | UNRESPONSIVE
            $table->string('general_condition', 20)->default('GOOD');       // GOOD | FAIR | WEAK | CRITICAL
            $table->string('duration', 60)->nullable();
            $table->string('onset', 20)->nullable();                        // SUDDEN | GRADUAL | UNKNOWN
            $table->string('severity_self', 16)->nullable();                // MILD | MODERATE | SEVERE
            $table->json('associated_symptoms')->nullable();
            $table->boolean('is_infectious_suspect')->default(false);
            $table->boolean('requires_isolation')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('triaged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('triaged_at');
            $table->timestamps();

            $table->index(['priority', 'triaged_at']);
        });

        // ---------- Tanda vital (serial; grafik riwayat) ----------
        Schema::create('clinic_vital_signs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->nullable()->constrained('clinic_visits')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('clinic_patients')->cascadeOnDelete();
            $table->decimal('temperature', 4, 1)->nullable();          // °C
            $table->unsignedSmallInteger('systolic')->nullable();
            $table->unsignedSmallInteger('diastolic')->nullable();
            $table->unsignedSmallInteger('heart_rate')->nullable();
            $table->unsignedSmallInteger('respiratory_rate')->nullable();
            $table->unsignedSmallInteger('oxygen_saturation')->nullable();
            $table->unsignedSmallInteger('pain_score')->nullable();
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->decimal('height_cm', 6, 2)->nullable();
            $table->decimal('bmi', 5, 2)->nullable();
            $table->string('bmi_category', 24)->nullable();
            $table->string('blood_pressure_note', 60)->nullable();
            $table->decimal('blood_glucose', 6, 2)->nullable();
            $table->string('device_ref', 60)->nullable();              // asset/serial alat ukur, bila dicatat
            $table->dateTime('measured_at');
            $table->foreignId('measured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['patient_id', 'measured_at']);
            $table->index(['visit_id', 'measured_at']);
        });

        // ---------- Riwayat medis ----------
        Schema::create('clinic_medical_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('clinic_patients')->cascadeOnDelete();
            $table->string('category', 24); // PAST_ILLNESS | SURGERY | HOSPITALIZATION | CHRONIC_CONDITION | MEDICATION_HISTORY | FAMILY_HISTORY | RELEVANT_HISTORY
            $table->string('title', 191);
            $table->text('description')->nullable();
            $table->string('year', 12)->nullable();
            $table->string('disclosure', 16)->default('KNOWN'); // KNOWN | UNKNOWN | NOT_DISCLOSED
            $table->string('status', 16)->default('ACTIVE');    // ACTIVE | RESOLVED | ARCHIVED
            $table->date('recorded_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['patient_id', 'category', 'status']);
        });

        // ---------- Alergi ----------
        Schema::create('clinic_allergies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('clinic_patients')->cascadeOnDelete();
            $table->string('category', 16)->default('MEDICATION'); // MEDICATION | FOOD | ENVIRONMENT | OTHER
            $table->string('allergen', 120);
            $table->string('reaction', 191)->nullable();
            $table->string('severity', 16)->default('UNKNOWN');    // MILD | MODERATE | SEVERE | UNKNOWN
            $table->string('status', 16)->default('ACTIVE');       // ACTIVE | RESOLVED | REFUTED
            $table->foreignId('medication_id')->nullable()->constrained('clinic_medications')->nullOnDelete();
            $table->date('identified_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['patient_id', 'status']);
            $table->index(['patient_id', 'category']);
        });

        // ---------- Kondisi khusus / perawatan khusus ----------
        Schema::create('clinic_special_conditions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('clinic_patients')->cascadeOnDelete();
            $table->string('category', 24); // CHRONIC_CONDITION | SPECIAL_CARE | DIETARY_RESTRICTION | ACTIVITY_RESTRICTION | EMERGENCY_INSTRUCTION | OTHER
            $table->string('condition', 191);
            $table->text('description')->nullable();
            $table->text('care_instruction')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 16)->default('ACTIVE'); // ACTIVE | RESOLVED | EXPIRED
            $table->boolean('visible_to_kitchen')->default(false);     // dietary saja, tanpa diagnosis
            $table->boolean('visible_to_boarding')->default(false);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['patient_id', 'status', 'category']);
        });

        // ---------- Master keluhan (configurable) ----------
        Schema::create('clinic_complaints', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->string('category', 40)->nullable();
            $table->json('keywords')->nullable();
            $table->boolean('is_infectious')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        // Foreign key appointments.visit_id dipasang di sini karena
        // `clinic_visits` baru dibuat setelah tabel appointments.
        Schema::table('clinic_appointments', function (Blueprint $table): void {
            $table->foreign('visit_id')->references('id')->on('clinic_visits')->nullOnDelete();
        });
    }

    public function down(): void
    {
        foreach ([
            'clinic_complaints', 'clinic_special_conditions', 'clinic_allergies',
            'clinic_medical_histories', 'clinic_vital_signs', 'clinic_triages',
            'clinic_queue_entries', 'clinic_visits', 'clinic_appointments',
            'clinic_patients', 'clinic_staff', 'clinic_clinics',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
