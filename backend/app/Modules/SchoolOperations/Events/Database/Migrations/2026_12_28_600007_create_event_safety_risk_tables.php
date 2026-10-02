<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Event (School Operations Fase 4) — 600007_create_event_safety_risk_tables.
 *
 * Catatan: kolom `_id` diberi indeks; kunci asing keras hanya dipakai pada
 * tabel anak yang memang terikat siklus hidup induknya (cascade), sehingga
 * pengujian lintas modul tidak bergantung urutan penghapusan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_security_plans', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->string('plan_code', 50)->nullable();
            $t->string('title', 200)->nullable();
            $t->integer('crowd_capacity')->nullable();
            $t->integer('expected_crowd')->nullable();
            $t->integer('entrance_count')->nullable();
            $t->integer('exit_count')->nullable();
            $t->text('emergency_exit')->nullable();
            $t->json('restricted_areas')->nullable();
            $t->string('vip_area', 200)->nullable();
            $t->text('parking_plan')->nullable();
            $t->integer('security_staff_required')->nullable();
            $t->integer('security_staff_assigned')->default(0);
            $t->string('emergency_contact_name', 150)->nullable();
            $t->string('emergency_contact_phone', 30)->nullable();
            $t->text('coordination_note')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->string('status', 30)->index()->default('DRAFT');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_security_assignments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('security_plan_id')->index()->constrained('event_security_plans')->cascadeOnDelete();
            $t->unsignedBigInteger('event_id')->nullable()->index();
            $t->unsignedBigInteger('employee_id')->nullable()->index();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('personnel_name', 150)->nullable();
            $t->string('personnel_type', 30)->default('INTERNAL');
            $t->string('post', 100)->nullable();
            $t->string('area', 100)->nullable();
            $t->dateTime('shift_start')->nullable();
            $t->dateTime('shift_end')->nullable();
            $t->string('contact_phone', 30)->nullable();
            $t->boolean('attended')->default(false);
            $t->string('status', 20)->index()->default('ASSIGNED');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_medical_plans', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->string('medical_room', 150)->nullable();
            $t->string('first_aid_post', 150)->nullable();
            $t->integer('clinic_staff_required')->nullable();
            $t->integer('clinic_staff_assigned')->default(0);
            $t->boolean('ambulance_required')->default(false);
            $t->string('ambulance_provider', 150)->nullable();
            $t->string('emergency_contact_name', 150)->nullable();
            $t->string('emergency_contact_phone', 30)->nullable();
            $t->string('referral_facility', 200)->nullable();
            $t->json('equipment_list')->nullable();
            $t->string('provider_reference', 80)->nullable();
            $t->string('provider_status', 30)->index()->default('PENDING');
            $t->string('status', 30)->index()->default('DRAFT');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_risks', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->string('risk_code', 50)->nullable();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->string('category', 40)->index()->default('OPERATIONAL');
            $t->string('probability', 20)->index()->default('MEDIUM');
            $t->string('impact', 20)->index()->default('MEDIUM');
            $t->string('risk_level', 20)->index()->default('MEDIUM');
            $t->integer('risk_score')->nullable();
            $t->text('mitigation')->nullable();
            $t->text('contingency')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable()->index();
            $t->string('owner_name', 150)->nullable();
            $t->date('review_date')->nullable();
            $t->string('status', 20)->index()->default('OPEN');
            $t->dateTime('closed_at')->nullable();
            $t->text('closure_note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_contingencies', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->string('contingency_type', 40)->index();
            $t->unsignedBigInteger('primary_reference_id')->nullable();
            $t->string('primary_description', 255)->nullable();
            $t->unsignedBigInteger('backup_reference_id')->nullable();
            $t->string('backup_description', 255)->nullable();
            $t->text('trigger_condition')->nullable();
            $t->boolean('activated')->default(false);
            $t->dateTime('activated_at')->nullable();
            $t->unsignedBigInteger('activated_by')->nullable();
            $t->string('status', 20)->index()->default('STANDBY');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_incidents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('schedule_id')->nullable()->index();
            $t->unsignedBigInteger('session_id')->nullable()->index();
            $t->string('incident_code', 50)->unique();
            $t->string('incident_type', 30)->index()->default('OTHER');
            $t->string('category', 40)->nullable();
            $t->string('severity', 20)->index()->default('LOW');
            $t->string('title', 200);
            $t->text('description');
            $t->string('location', 255)->nullable();
            $t->dateTime('occurred_at')->index();
            $t->unsignedBigInteger('reported_by')->nullable()->index();
            $t->string('reporter_name', 150)->nullable();
            $t->string('reporter_type', 30)->nullable();
            $t->string('affected_person_name', 150)->nullable();
            $t->unsignedBigInteger('affected_participant_id')->nullable()->index();
            $t->text('witness_note')->nullable();
            $t->text('response_action')->nullable();
            $t->boolean('escalated')->index()->default(false);
            $t->string('escalated_to', 200)->nullable();
            $t->dateTime('escalated_at')->nullable();
            $t->dateTime('resolved_at')->nullable();
            $t->text('resolution')->nullable();
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->boolean('follow_up_required')->default(false);
            $t->text('follow_up_note')->nullable();
            $t->unsignedBigInteger('linked_case_id')->nullable();
            $t->boolean('documented')->default(false);
            $t->string('status', 30)->index()->default('REPORTED');
            $t->text('note')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_daily_closings', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('schedule_id')->nullable()->index();
            $t->date('closing_date')->index();
            $t->integer('day_number')->default(1);
            $t->integer('attendance_present')->default(0);
            $t->integer('attendance_absent')->default(0);
            $t->integer('attendance_no_show')->default(0);
            $t->decimal('expense_amount')->default(0);
            $t->integer('incident_count')->default(0);
            $t->integer('resource_issued')->default(0);
            $t->integer('resource_returned')->default(0);
            $t->integer('meals_served')->default(0);
            $t->integer('trip_completed')->default(0);
            $t->integer('participant_count')->default(0);
            $t->text('summary')->nullable();
            $t->text('issue_note')->nullable();
            $t->unsignedBigInteger('closed_by')->nullable()->index();
            $t->dateTime('closed_at')->nullable();
            $t->string('status', 30)->index()->default('OPEN');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_closings', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->boolean('attendance_finalized')->default(false);
            $t->boolean('participants_finalized')->default(false);
            $t->boolean('finance_reconciled')->default(false);
            $t->boolean('assets_returned')->default(false);
            $t->boolean('inventory_reconciled')->default(false);
            $t->boolean('catering_reconciled')->default(false);
            $t->boolean('transportation_closed')->default(false);
            $t->boolean('incidents_reviewed')->default(false);
            $t->boolean('documents_archived')->default(false);
            $t->boolean('media_uploaded')->default(false);
            $t->boolean('certificates_processed')->default(false);
            $t->boolean('survey_completed')->default(false);
            $t->boolean('report_generated')->default(false);
            $t->integer('checklist_completed')->default(0);
            $t->integer('checklist_total')->default(13);
            $t->integer('open_incident_count')->default(0);
            $t->integer('critical_incident_count')->default(0);
            $t->boolean('override_used')->default(false);
            $t->text('override_reason')->nullable();
            $t->unsignedBigInteger('override_by')->nullable();
            $t->integer('finalized_attendance')->nullable();
            $t->integer('final_participant_count')->nullable();
            $t->decimal('budget_planned')->nullable();
            $t->decimal('budget_actual')->nullable();
            $t->decimal('variance')->nullable();
            $t->unsignedBigInteger('closed_by')->nullable()->index();
            $t->dateTime('closed_at')->nullable();
            $t->string('status', 30)->index()->default('OPEN');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_closing_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('closing_id')->index()->constrained('event_closings')->cascadeOnDelete();
            $t->unsignedBigInteger('event_id')->nullable()->index();
            $t->string('item_code', 60)->index();
            $t->string('title', 200);
            $t->boolean('is_mandatory')->default(true);
            $t->boolean('completed')->index()->default(false);
            $t->unsignedBigInteger('completed_by')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->text('note')->nullable();
            $t->integer('sort_order')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_closing_items');
        Schema::dropIfExists('event_closings');
        Schema::dropIfExists('event_daily_closings');
        Schema::dropIfExists('event_incidents');
        Schema::dropIfExists('event_contingencies');
        Schema::dropIfExists('event_risks');
        Schema::dropIfExists('event_medical_plans');
        Schema::dropIfExists('event_security_assignments');
        Schema::dropIfExists('event_security_plans');
    }
};
