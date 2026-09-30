<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Clinic / UKS — tabel klinis: asesmen (SOAP), diagnosis, tindakan,
 * observasi, master obat & batch, resep, dispensing, pemberian obat, follow-up.
 *
 * Aturan penting: **clinic_medications tidak menyimpan saldo stok**. Saldo
 * tinggal di `inventory_items.quantity` (modul Inventory) dan setiap
 * pergerakan lewat `InventoryProviderInterface`. Baris `clinic_medication_*`
 * hanya mencatat pemakaian klinis beserta jurnal auditnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- Asesmen klinis (SOAP) ----------
        Schema::create('clinic_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->constrained('clinic_visits')->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('assessment_at');
            $table->string('method', 12)->default('SOAP'); // SOAP | FREE_TEXT
            $table->text('subjective')->nullable();
            $table->text('objective')->nullable();
            $table->text('assessment')->nullable();
            $table->text('plan')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_current')->default(true);
            $table->foreignId('amended_from_id')->nullable()->constrained('clinic_assessments')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['visit_id', 'is_current']);
            $table->index(['provider_id', 'assessment_at']);
        });

        // ---------- Master kode diagnosis (dapat diganti ke ICD lewat provider) ----------
        Schema::create('clinic_diagnoses_master', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name', 191);
            $table->string('category', 60)->nullable();
            $table->string('coding_system', 24)->default('INTERNAL'); // INTERNAL | ICD10 | ICD11 | SNOMED
            $table->text('description')->nullable();
            $table->boolean('is_infectious')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'coding_system']);
        });

        // ---------- Diagnosis per kunjungan ----------
        Schema::create('clinic_diagnoses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->constrained('clinic_visits')->cascadeOnDelete();
            $table->foreignId('master_id')->nullable()->constrained('clinic_diagnoses_master')->nullOnDelete();
            $table->string('diagnosis_code', 24)->nullable();
            $table->string('diagnosis_name', 191);
            $table->string('type', 16)->default('PRIMARY'); // PRIMARY | SECONDARY | SYMPTOM | PROVISIONAL
            $table->text('notes')->nullable();
            $table->foreignId('diagnosed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('diagnosed_at');
            $table->boolean('is_current')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('amended_from_id')->nullable()->constrained('clinic_diagnoses')->nullOnDelete();
            $table->timestamps();

            $table->index(['visit_id', 'type']);
        });

        // ---------- Master jenis tindakan (configurable) ----------
        Schema::create('clinic_procedure_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->string('category', 40)->default('BASIC_CARE'); // BASIC_CARE | WOUND_CARE | MONITORING | DIAGNOSTIC | OTHER
            $table->boolean('requires_consent')->default(false);
            $table->boolean('is_billable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'category']);
        });

        // ---------- Tindakan yang dikerjakan ----------
        Schema::create('clinic_procedures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->constrained('clinic_visits')->cascadeOnDelete();
            $table->foreignId('type_id')->nullable()->constrained('clinic_procedure_types')->nullOnDelete();
            $table->string('procedure', 191);
            $table->text('notes')->nullable();
            $table->json('supplies')->nullable(); // daftar bahan habis pakai yang dipakai
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('performed_at');
            $table->timestamps();

            $table->index(['visit_id', 'performed_at']);
        });

        // ---------- Observasi (ruang/bed) ----------
        Schema::create('clinic_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->constrained('clinic_visits')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('clinic_patients')->cascadeOnDelete();
            $table->foreignId('clinic_id')->constrained('clinic_clinics')->restrictOnDelete();
            $table->string('location', 120)->nullable();
            $table->string('bed', 20)->nullable();
            $table->string('reason', 191);
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->string('status', 16)->default('ACTIVE'); // ACTIVE | COMPLETED | ESCALATED | REFERRED
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'started_at']);
            $table->index(['clinic_id', 'status']);
            $table->index(['patient_id', 'started_at']);
        });

        // ---------- Catatan berkala selama observasi ----------
        Schema::create('clinic_observation_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('observation_id')->constrained('clinic_observations')->cascadeOnDelete();
            $table->dateTime('recorded_at');
            $table->string('condition', 24)->default('STABLE'); // IMPROVING | STABLE | WORSENING | CRITICAL
            $table->text('notes')->nullable();
            $table->text('action')->nullable();
            $table->foreignId('vital_sign_id')->nullable()->constrained('clinic_vital_signs')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['observation_id', 'recorded_at']);
        });

        // ---------- Master obat (klien dari inventory_items untuk saldo) ----------
        Schema::create('clinic_medications', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 160);
            $table->string('generic_name', 160)->nullable();
            $table->string('brand_name', 160)->nullable();
            $table->string('dosage_form', 24)->default('TABLET'); // TABLET | CAPSULE | SYRUP | CREAM | OINTMENT | DROPS | INJECTION | OTHER
            $table->string('strength', 60)->nullable();           // mis. "500 mg"
            $table->string('unit', 20)->default('tablet');
            $table->string('route', 16)->default('ORAL');          // ORAL | TOPICAL | OPHTHALMIC | OTIC | NASAL | INJECTION | OTHER
            $table->string('category', 60)->nullable();
            $table->boolean('requires_prescription')->default(true);
            $table->boolean('is_controlled')->default(false);      // obat terkendali / narkotika
            $table->string('status', 16)->default('ACTIVE');       // ACTIVE | INACTIVE | DISCONTINUED
            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'name']);
            $table->index('inventory_item_id');
        });

        // ---------- Batch & kedaluwarsa ----------
        Schema::create('clinic_medication_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('medication_id')->constrained('clinic_medications')->cascadeOnDelete();
            $table->string('batch_number', 60);
            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->date('manufacture_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity_received', 12, 2)->default(0);
            $table->string('source', 120)->nullable();
            $table->string('status', 16)->default('AVAILABLE'); // AVAILABLE | QUARANTINED | EXPIRED | DISPOSED
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['medication_id', 'batch_number']);
            $table->index(['expiry_date', 'status']);
        });

        // ---------- Resep ----------
        Schema::create('clinic_prescriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('prescription_number', 30)->unique();
            $table->foreignId('visit_id')->constrained('clinic_visits')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('clinic_patients')->cascadeOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('prescribed_at');
            $table->string('status', 20)->default('DRAFT'); // DRAFT | ISSUED | PARTIALLY_DISPENSED | DISPENSED | CANCELLED
            $table->text('notes')->nullable();
            $table->json('allergy_alerts')->nullable();      // jejak peringatan saat resep dibuat
            $table->boolean('allergy_override')->default(false);
            $table->text('override_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'prescribed_at']);
            $table->index(['patient_id', 'prescribed_at']);
        });

        Schema::create('clinic_prescription_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prescription_id')->constrained('clinic_prescriptions')->cascadeOnDelete();
            $table->foreignId('medication_id')->nullable()->constrained('clinic_medications')->nullOnDelete();
            $table->string('medication_name', 160); // snapshot agar resep tetap terbaca bila master berubah
            $table->string('dose', 60)->nullable();
            $table->string('frequency', 60)->nullable();
            $table->string('duration', 60)->nullable();
            $table->string('route', 16)->default('ORAL');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('dispensed_quantity', 10, 2)->default(0);
            $table->string('unit', 20)->nullable();
            $table->text('instruction')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('prescription_id');
            $table->index('medication_id');
        });

        // ---------- Dispensing (serah obat) ----------
        Schema::create('clinic_dispensings', function (Blueprint $table): void {
            $table->id();
            $table->string('dispensing_number', 30)->unique();
            $table->foreignId('prescription_id')->constrained('clinic_prescriptions')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('clinic_patients')->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('clinic_visits')->nullOnDelete();
            $table->foreignId('dispensed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('dispensed_at');
            $table->string('status', 16)->default('COMPLETED'); // COMPLETED | PARTIAL | REJECTED
            $table->text('notes')->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['prescription_id', 'dispensed_at']);
        });

        Schema::create('clinic_dispensing_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dispensing_id')->constrained('clinic_dispensings')->cascadeOnDelete();
            $table->foreignId('prescription_item_id')->nullable()->constrained('clinic_prescription_items')->nullOnDelete();
            $table->foreignId('medication_id')->nullable()->constrained('clinic_medications')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('clinic_medication_batches')->nullOnDelete();
            $table->string('batch_number', 60)->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity', 10, 2);
            $table->string('unit', 20)->nullable();
            // Hasil pemanggilan InventoryProviderInterface — modul ini tidak pernah
            // menulis saldo sendiri.
            $table->string('inventory_result', 24)->default('PENDING'); // PENDING | SUCCESS | INSUFFICIENT_STOCK | EXPIRED | NOT_AVAILABLE | FAILED
            $table->string('inventory_reference', 120)->nullable();
            $table->foreignId('inventory_movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('dispensing_id');
            $table->index('medication_id');
        });

        // ---------- Pemberian obat (MAR) ----------
        Schema::create('clinic_medication_administrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->nullable()->constrained('clinic_visits')->nullOnDelete();
            $table->foreignId('patient_id')->constrained('clinic_patients')->cascadeOnDelete();
            $table->foreignId('prescription_item_id')->nullable()->constrained('clinic_prescription_items')->nullOnDelete();
            $table->foreignId('medication_id')->nullable()->constrained('clinic_medications')->nullOnDelete();
            $table->string('medication_name', 160);
            $table->string('dose', 60)->nullable();
            $table->string('route', 16)->default('ORAL');
            $table->dateTime('administered_at')->nullable();
            $table->foreignId('administered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('GIVEN'); // GIVEN | REFUSED | HELD | MISSED | CANCELLED
            $table->text('notes')->nullable();
            // Koreksi tidak destruktif: baris lama ditandai digantikan, bukan dihapus.
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_current')->default(true);
            $table->foreignId('corrected_from_id')->nullable()->constrained('clinic_medication_administrations')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['patient_id', 'administered_at']);
            $table->index(['visit_id', 'status']);
            $table->index(['medication_id', 'administered_at']);
        });

        // ---------- Tindak lanjut ----------
        Schema::create('clinic_follow_ups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('clinic_patients')->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('clinic_visits')->nullOnDelete();
            // FK ke `clinic_referrals` dipasang pada migrasi respons klinis
            // (tabel rujukan dibuat setelah tabel ini).
            $table->unsignedBigInteger('referral_id')->nullable();
            $table->date('due_date');
            $table->string('type', 24)->default('REVIEW'); // REVIEW | MEDICATION | WOUND_CARE | OBSERVATION | RESULT_REVIEW | OTHER
            $table->string('status', 16)->default('PENDING'); // PENDING | SCHEDULED | COMPLETED | CANCELLED | OVERDUE
            $table->string('instruction', 255)->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'due_date']);
            $table->index(['patient_id', 'due_date']);
        });
    }

    public function down(): void
    {
        foreach ([
            'clinic_follow_ups', 'clinic_medication_administrations', 'clinic_dispensing_items',
            'clinic_dispensings', 'clinic_prescription_items', 'clinic_prescriptions',
            'clinic_medication_batches', 'clinic_medications', 'clinic_observation_records',
            'clinic_observations', 'clinic_procedures', 'clinic_procedure_types',
            'clinic_diagnoses', 'clinic_diagnoses_master', 'clinic_assessments',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
