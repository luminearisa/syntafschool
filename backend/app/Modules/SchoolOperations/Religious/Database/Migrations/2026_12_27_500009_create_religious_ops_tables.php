<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prestasi, sertifikat, pengumuman, penyesuaian, audit, event bus, idempotensi, dan impor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('religious_achievements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->unsignedBigInteger('participant_id')->nullable()->index();
            $table->string('code', 40);
            $table->string('title', 200);
            $table->string('achievement_type', 30)->default('QURAN_MEMORIZATION');
            $table->string('category', 60)->nullable();
            $table->string('level', 20)->default('SCHOOL');
            $table->string('rank_label', 20)->nullable();
            $table->decimal('score', 5, 2)->nullable();
            $table->unsignedBigInteger('activity_id')->nullable()->index();
            $table->unsignedBigInteger('assessment_id')->nullable()->index();
            $table->date('achievement_date');
            $table->string('organizer', 160)->nullable();
            $table->string('location', 160)->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('student_activity_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->string('status', 20)->default('RECORDED');
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('religious_certificates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('achievement_id')->nullable()->index();
            $table->unsignedBigInteger('participant_id')->nullable()->index();
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->string('code', 40)->unique();
            $table->string('certificate_type', 20)->default('COMPLETION');
            $table->string('title', 200);
            $table->date('issued_date')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable()->index();
            $table->string('certificate_number', 80)->nullable();
            $table->unsignedBigInteger('document_id')->nullable()->index();
            $table->string('provider_reference', 80)->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('religious_announcements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mosque_id')->nullable()->index();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->string('code', 40);
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('announcement_type', 20)->default('GENERAL');
            $table->json('audience')->nullable();
            $table->string('priority', 20)->default('NORMAL');
            $table->dateTime('publish_from')->nullable();
            $table->dateTime('publish_until')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->dateTime('published_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('religious_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 60);
            $table->unsignedInteger('entity_id')->nullable();
            $table->string('adjustment_type', 20)->default('ATTENDANCE');
            $table->json('from_value')->nullable();
            $table->json('to_value')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable()->index();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->dateTime('approved_at')->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->date('effective_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('religious_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event', 80);
            $table->string('auditable_type', 160)->nullable();
            $table->unsignedInteger('auditable_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('actor_type', 20)->nullable();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->unsignedBigInteger('mosque_id')->nullable()->index();
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->text('reason')->nullable();
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('religious_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_name', 80);
            $table->string('aggregate_type', 160)->nullable();
            $table->unsignedInteger('aggregate_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('PUBLISHED');
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->dateTime('occurred_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('religious_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 40)->unique();
            $table->string('scope', 60);
            $table->string('source', 60)->nullable();
            $table->string('external_reference', 120)->nullable();
            $table->string('device_id', 60)->nullable();
            $table->json('response')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('religious_import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('import_type', 30)->default('PRAYER_SCHEDULE');
            $table->string('file_name', 200)->nullable();
            $table->string('file_path', 255)->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->string('status', 20)->default('PREVIEW');
            $table->json('error_report')->nullable();
            $table->json('preview')->nullable();
            $table->unsignedBigInteger('imported_by')->nullable()->index();
            $table->dateTime('committed_at')->nullable();
            $table->dateTime('rolled_back_at')->nullable();
            $table->string('rollback_reference', 80)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('religious_import_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('batch_id')->index();
            $table->unsignedInteger('row_number')->default(0);
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->json('errors')->nullable();
            $table->string('entity_type', 60)->nullable();
            $table->unsignedInteger('entity_id')->nullable();
            $table->string('action', 20)->default('CREATE');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('religious_import_rows');
        Schema::dropIfExists('religious_import_batches');
        Schema::dropIfExists('religious_idempotency_keys');
        Schema::dropIfExists('religious_events');
        Schema::dropIfExists('religious_audit_logs');
        Schema::dropIfExists('religious_adjustments');
        Schema::dropIfExists('religious_announcements');
        Schema::dropIfExists('religious_certificates');
        Schema::dropIfExists('religious_achievements');
    }
};
