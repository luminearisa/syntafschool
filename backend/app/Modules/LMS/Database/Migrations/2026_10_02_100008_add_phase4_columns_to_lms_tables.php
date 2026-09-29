<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — kolom tambahan pada tabel Phase 1–3.
 *
 * Semua kolom bersifat opsional dengan nilai bawaan yang aman, sehingga course
 * lama tetap berjalan tanpa konfigurasi baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lms_courses', function (Blueprint $table) {
            // Aturan kelulusan course (§40).
            $table->string('completion_rule', 32)->default('ALL_REQUIRED_ACTIVITIES');
            $table->decimal('completion_min_progress', 6, 2)->nullable();
            $table->decimal('completion_min_grade', 6, 2)->nullable();
            $table->unsignedBigInteger('required_final_activity_id')->nullable();

            // Sertifikat (§21–§25).
            $table->boolean('certificate_enabled')->default(false);
            $table->unsignedBigInteger('certificate_template_id')->nullable();
            $table->decimal('certificate_min_progress', 6, 2)->nullable();
            $table->decimal('certificate_min_grade', 6, 2)->nullable();
            $table->unsignedBigInteger('certificate_required_competency_id')->nullable();

            // Gamifikasi, ulasan, dan retake (§28, §31, §42).
            $table->boolean('gamification_enabled')->default(false);
            $table->boolean('leaderboard_enabled')->default(false);
            $table->boolean('review_enabled')->default(true);
            $table->boolean('allow_retake')->default(false);
            $table->unsignedInteger('max_attempts')->nullable();

            // Cache agregat rating untuk katalog publik.
            $table->decimal('rating_average', 3, 2)->nullable();
            $table->unsignedInteger('rating_count')->default(0);

            // Learning path & drip (§1–§4).
            $table->boolean('learning_path_enabled')->default(false);
            $table->boolean('drip_enabled')->default(false);
        });

        Schema::table('lms_enrollments', function (Blueprint $table) {
            // Riwayat percobaan ulang (§42) — satu baris tetap satu attempt.
            $table->unsignedInteger('attempt_number')->default(1);
            $table->unsignedInteger('xp_points')->default(0);
            $table->timestamp('last_event_at')->nullable();
            $table->string('health_status', 20)->nullable();
            $table->timestamp('health_computed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lms_enrollments', function (Blueprint $table) {
            $table->dropColumn([
                'attempt_number', 'xp_points', 'last_event_at', 'health_status', 'health_computed_at',
            ]);
        });

        Schema::table('lms_courses', function (Blueprint $table) {
            $table->dropColumn([
                'completion_rule', 'completion_min_progress', 'completion_min_grade', 'required_final_activity_id',
                'certificate_enabled', 'certificate_template_id', 'certificate_min_progress', 'certificate_min_grade',
                'certificate_required_competency_id', 'gamification_enabled', 'leaderboard_enabled', 'review_enabled',
                'allow_retake', 'max_attempts', 'rating_average', 'rating_count', 'learning_path_enabled', 'drip_enabled',
            ]);
        });
    }
};
