<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inspeksi (dengan butir yang dapat dikonfigurasi) serta laporan kerusakan dan insiden.
 *
 * Laporan kerusakan terpisah dari insiden: kerusakan adalah keadaan barang, insiden
 * adalah kejadian (hilang, kecelakaan, penyalahgunaan) yang sering menuntut
 * investigasi dan pihak yang bertanggung jawab.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_inspection_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('asset_categories')->nullOnDelete();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('inspection_type', 32)->default('ROUTINE');
            $table->json('checklist')->comment('Daftar butir: [{code,label,hint}] — dapat dikonfigurasi, bukan kode');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['school_id', 'code']);
        });
        Schema::create('asset_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('inspection_number', 40)->unique();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->foreignId('facility_id')->nullable()->constrained('facilities')->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('asset_inspection_templates')->nullOnDelete();
            $table->string('inspection_type', 32)->default('ROUTINE');
            $table->string('status', 24)->default('SCHEDULED');
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('performed_at')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('result_summary', 40)->nullable();
            $table->decimal('score', 6, 2)->nullable();
            $table->text('findings')->nullable();
            $table->string('condition_found', 24)->nullable();
            $table->boolean('follow_up_required')->default(false);
            $table->unsignedBigInteger('work_order_id')->nullable();
            $table->string('document_reference', 200)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['inspection_type', 'status']);
        });
        Schema::create('asset_inspection_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inspection_id')->constrained('asset_inspections')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('label', 160);
            $table->string('result', 24)->comment('PASS | FAIL | NOT_APPLICABLE');
            $table->string('value', 120)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('damage_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('report_number', 40)->unique();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->foreignId('facility_id')->nullable()->constrained('facilities')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('reported_by')->constrained('users')->cascadeOnDelete();
            $table->dateTime('reported_at');
            $table->string('severity', 24)->default('MODERATE');
            $table->string('status', 24)->default('REPORTED');
            $table->string('damage_type', 60)->nullable();
            $table->text('description');
            $table->string('photo_path', 200)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->unsignedBigInteger('inspection_id')->nullable();
            $table->unsignedBigInteger('maintenance_request_id')->nullable();
            $table->text('resolution')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'severity']);
        });
        Schema::create('asset_incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('incident_number', 40)->unique();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->foreignId('facility_id')->nullable()->constrained('facilities')->nullOnDelete();
            $table->string('incident_type', 32);
            $table->string('status', 24)->default('REPORTED');
            $table->string('severity', 24)->default('MODERATE');
            $table->dateTime('occurred_at');
            $table->foreignId('reported_by')->constrained('users')->cascadeOnDelete();
            $table->text('description');
            $table->text('investigation_notes')->nullable();
            $table->string('responsible_party', 160)->nullable();
            $table->string('resolution_type', 32)->nullable();
            $table->string('resolution', 200)->nullable();
            $table->decimal('recovered_value', 16, 2)->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('document_reference', 200)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['incident_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_incidents');
        Schema::dropIfExists('damage_reports');
        Schema::dropIfExists('asset_inspection_results');
        Schema::dropIfExists('asset_inspections');
        Schema::dropIfExists('asset_inspection_templates');
    }
};
