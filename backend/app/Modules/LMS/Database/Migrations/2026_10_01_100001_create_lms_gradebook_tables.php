<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — Gradebook, Grade Item, Grade Scale, Assessment Result, History,
 * Release, dan jejak sinkronisasi akademik (§2–§13, §16–§19, §41–§44, §50).
 *
 * Konvensi identitas tetap seperti Phase 1–2: `user_id`/`student_id` adalah
 * tautan lunak (tanpa foreign key) supaya Integrated dan Standalone mode
 * memakai skema yang sama persis.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ================= SKALA NILAI (§6) =================
        Schema::create('lms_grade_scales', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20)->default('SCHOOL');      // SCHOOL | COURSE | ACADEMIC_YEAR
            $table->string('code', 40)->nullable();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->foreignId('course_id')->nullable()->constrained('lms_courses')->cascadeOnDelete();
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->unsignedBigInteger('semester_id')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['scope', 'course_id']);
            $table->index(['academic_year_id', 'semester_id']);
        });

        Schema::create('lms_grade_scale_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_scale_id')->constrained('lms_grade_scales')->cascadeOnDelete();
            $table->string('grade', 10);                         // A, B+, 4, dst.
            $table->decimal('min_score', 6, 2);
            $table->decimal('max_score', 6, 2);
            $table->string('predicate', 60)->nullable();         // "Sangat Baik"
            $table->decimal('gpa', 4, 2)->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['grade_scale_id', 'grade']);
        });

        // ================= GRADEBOOK (§2) =================
        Schema::create('lms_gradebooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->unique()->constrained('lms_courses')->cascadeOnDelete();
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('DRAFT');        // GradebookStatus
            $table->string('grading_method', 20)->default('PERCENTAGE'); // GradingMethod
            $table->string('aggregation_method', 20)->default('WEIGHTED_MEAN'); // AggregationMethod
            $table->string('missing_grade_policy', 20)->default('PENDING');     // MissingGradePolicy
            $table->string('weight_policy', 20)->default('NORMALIZE');          // WeightPolicy
            $table->string('extra_credit_policy', 20)->default('CAP');          // ExtraCreditPolicy
            $table->decimal('maximum_grade', 6, 2)->default(100);
            $table->decimal('passing_grade', 6, 2)->nullable();
            $table->foreignId('grade_scale_id')->nullable()->constrained('lms_grade_scales')->nullOnDelete();
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->boolean('allow_extra_credit')->default(true);
            $table->boolean('is_parent_visible')->default(true);
            $table->boolean('is_ranking_visible')->default(false);   // §36 default: tanpa ranking
            $table->boolean('is_letter_visible')->default(true);
            $table->timestamp('frozen_at')->nullable();              // §44
            $table->unsignedBigInteger('frozen_by')->nullable();
            $table->timestamp('last_calculated_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        // ================= GRADE CATEGORY (§4) =================
        Schema::create('lms_grade_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gradebook_id')->constrained('lms_gradebooks')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('lms_grade_categories')->cascadeOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->decimal('weight', 6, 2)->default(0);
            $table->string('aggregation_method', 20)->nullable();   // null → ikut gradebook
            $table->string('missing_grade_policy', 20)->nullable();
            $table->unsignedTinyInteger('drop_lowest')->default(0);
            $table->boolean('is_extra_credit')->default(false);
            $table->boolean('include_in_final')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['gradebook_id', 'parent_id']);
        });

        // ================= GRADE ITEM (§3) =================
        Schema::create('lms_grade_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gradebook_id')->constrained('lms_gradebooks')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('lms_grade_categories')->nullOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained('lms_activities')->cascadeOnDelete();
            $table->string('source', 20)->default('MANUAL');         // GradeItemSource
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->decimal('maximum_grade', 6, 2)->default(100);
            $table->decimal('passing_grade', 6, 2)->nullable();
            $table->decimal('weight', 6, 2)->default(0);
            $table->string('aggregation_method', 20)->nullable();
            $table->string('grade_type', 20)->default('POINTS');     // GradingMethod
            $table->string('visibility', 20)->default('VISIBLE');    // GradeItemVisibility
            $table->boolean('is_extra_credit')->default(false);
            $table->boolean('include_in_final')->default(true);
            $table->boolean('is_bonus')->default(false);
            $table->foreignId('rubric_id')->nullable()->constrained('lms_rubrics')->nullOnDelete();
            $table->foreignId('grade_scale_id')->nullable()->constrained('lms_grade_scales')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('released_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique('activity_id');
            $table->index(['gradebook_id', 'category_id']);
            $table->index(['gradebook_id', 'sort_order']);
        });

        // ================= ASSESSMENT RESULT (§12–§13) =================
        Schema::create('lms_assessment_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gradebook_id')->constrained('lms_gradebooks')->cascadeOnDelete();
            $table->foreignId('grade_item_id')->constrained('lms_grade_items')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('student_id')->nullable();
            // Jejak ke evidence layer Phase 2 (nilai aktivitas). Null untuk item MANUAL/EXTERNAL.
            $table->unsignedBigInteger('activity_grade_id')->nullable();
            $table->string('source', 20)->default('MANUAL');
            $table->decimal('raw_score', 8, 2)->nullable();        // apa adanya dari evidence
            $table->decimal('maximum_score', 6, 2)->default(100);
            $table->decimal('normalized_score', 8, 4)->nullable(); // 0–100
            $table->decimal('final_score', 8, 4)->nullable();      // setelah penalti/extra credit
            $table->decimal('percent', 6, 2)->nullable();
            $table->string('status', 20)->default('PENDING');      // AssessmentResultStatus
            $table->boolean('is_exempt')->default(false);           // §10
            $table->string('exemption_reason', 255)->nullable();
            $table->unsignedBigInteger('exempted_by')->nullable();
            $table->timestamp('exempted_at')->nullable();
            $table->boolean('is_extra_credit')->default(false);     // §11
            $table->text('feedback')->nullable();                   // §21 general feedback
            $table->text('feedback_private')->nullable();            // catatan guru (tidak ke siswa)
            $table->json('feedback_meta')->nullable();               // what went well / to improve
            $table->string('feedback_attachment')->nullable();
            $table->unsignedBigInteger('graded_by')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->unsignedInteger('attempt')->default(1);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['grade_item_id', 'user_id']);
            $table->index(['course_id', 'user_id']);
            $table->index(['gradebook_id', 'status']);
        });

        // ================= GRADE HISTORY (§19, §53) =================
        Schema::create('lms_grade_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gradebook_id')->nullable()->constrained('lms_gradebooks')->cascadeOnDelete();
            $table->foreignId('grade_item_id')->nullable()->constrained('lms_grade_items')->cascadeOnDelete();
            $table->foreignId('assessment_result_id')->nullable()->constrained('lms_assessment_results')->cascadeOnDelete();
            $table->unsignedBigInteger('student_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 30);                            // GradeHistoryAction
            $table->string('source', 30)->nullable();                // TEACHER | SYSTEM | REVIEW | SYNC
            $table->json('previous_value')->nullable();
            $table->json('new_value')->nullable();
            $table->string('reason', 255)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['gradebook_id', 'user_id']);
            $table->index(['assessment_result_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        // ================= GRADE RELEASE (§16–§18) =================
        Schema::create('lms_grade_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gradebook_id')->constrained('lms_gradebooks')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('student_id')->nullable();
            $table->string('status', 20)->default('RELEASED');       // GradeReleaseStatus
            $table->decimal('final_score', 8, 2)->nullable();
            $table->decimal('normalized_score', 8, 2)->nullable();
            $table->string('letter', 10)->nullable();
            $table->string('predicate', 60)->nullable();
            $table->boolean('passed')->nullable();
            $table->json('component_snapshot')->nullable();          // rincian per komponen (§15)
            $table->json('calculation_snapshot')->nullable();         // snapshot perhitungan penuh (§16)
            $table->string('config_hash', 64)->nullable();            // deteksi perubahan konfigurasi
            $table->unsignedBigInteger('released_by')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['gradebook_id', 'user_id']);
        });

        // ================= GRADE SYNC (§41–§42) =================
        Schema::create('lms_grade_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gradebook_id')->constrained('lms_gradebooks')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('student_id')->nullable();
            $table->string('status', 20)->default('NOT_SYNCED');      // GradeSyncStatus
            $table->json('payload')->nullable();
            $table->string('payload_hash', 64)->nullable();            // idempotensi
            $table->string('provider', 60)->nullable();
            $table->string('external_type', 60)->nullable();
            $table->string('external_id', 120)->nullable();
            $table->string('external_ref', 180)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->unsignedBigInteger('locked_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['gradebook_id', 'user_id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_grade_syncs');
        Schema::dropIfExists('lms_grade_releases');
        Schema::dropIfExists('lms_grade_histories');
        Schema::dropIfExists('lms_assessment_results');
        Schema::dropIfExists('lms_grade_items');
        Schema::dropIfExists('lms_grade_categories');
        Schema::dropIfExists('lms_gradebooks');
        Schema::dropIfExists('lms_grade_scale_entries');
        Schema::dropIfExists('lms_grade_scales');
    }
};
