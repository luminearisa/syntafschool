<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — sertifikat (§21–§25).
 *
 * Nomor sertifikat unik & immutable; keberadaan `enrollment_id` yang unik
 * membuat penerbitan bersifat idempoten (job dobel tidak menghasilkan
 * sertifikat ganda).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_certificate_templates', function (Blueprint $table) {
            $table->id();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('background_path')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('signature_path')->nullable();
            $table->string('signer_name', 150)->nullable();
            $table->string('signer_title', 150)->nullable();
            $table->string('issuer_name', 180)->nullable();
            $table->longText('body_html')->nullable();                   // placeholder {{student_name}} dst.
            $table->json('template_data')->nullable();
            $table->string('orientation', 20)->default('landscape');
            $table->string('paper_size', 10)->default('a4');
            $table->boolean('is_default')->default(false);
            $table->string('status', 20)->default('ACTIVE');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('lms_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->nullable()->constrained('lms_certificate_templates')->nullOnDelete();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('lms_enrollments')->nullOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('student_id')->nullable();
            $table->string('certificate_number', 60)->unique();          // CERT-2026-000001
            $table->string('verification_token', 80)->unique();
            $table->string('status', 20)->default('ISSUED');             // CertificateStatus
            $table->decimal('grade_value', 6, 2)->nullable();
            $table->decimal('completion_percent', 6, 2)->nullable();
            $table->json('snapshot')->nullable();                        // nama/course saat terbit (immutable)
            $table->string('file_path')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 255)->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();
            $table->timestamps();

            // Satu sertifikat per enrollment (idempotensi penerbitan).
            $table->unique('enrollment_id');
            $table->index(['user_id', 'status']);
            $table->index(['course_id', 'status']);
        });

        Schema::create('lms_certificate_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certificate_id')->nullable()->constrained('lms_certificates')->nullOnDelete();
            $table->string('certificate_number', 60);
            $table->string('result', 30);                                // VALID / NOT_FOUND / REVOKED
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->index('certificate_number');
            $table->index('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_certificate_verifications');
        Schema::dropIfExists('lms_certificates');
        Schema::dropIfExists('lms_certificate_templates');
    }
};
