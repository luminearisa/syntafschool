<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — aktivitas pembelajaran, tugas (assignment), submission, dan
 * catatan nilai aktivitas (§2, §5, §6, §46–47, §51).
 *
 * Konvensi identitas tetap seperti Phase 1: `user_id` menunjuk `users`
 * (Integrated) atau `lms_users` (Standalone) sehingga sengaja tanpa foreign
 * key; `student_id`/`teacher_id` adalah tautan lunak ke entity ERP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_item_id')->unique()->constrained('lms_learning_items')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('lms_course_sections')->nullOnDelete();
            $table->string('activity_type', 20);                       // ActivityType
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->longText('instructions')->nullable();              // HTML tersanitasi
            $table->string('status', 20)->default('DRAFT');            // ActivityStatus
            $table->timestamp('start_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('close_at')->nullable();                 // cutoff
            $table->string('visibility', 20)->default('VISIBLE');
            $table->string('completion_rule', 20)->default('SUBMIT');  // CompletionType
            $table->decimal('completion_min_grade', 5, 2)->nullable();
            $table->json('availability')->nullable();                  // §41 (prasyarat, grup, nilai)
            $table->json('settings')->nullable();
            $table->boolean('is_graded')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['course_id', 'activity_type']);
            $table->index(['course_id', 'status']);
            $table->index(['course_id', 'due_at']);
        });

        Schema::create('lms_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->unique()->constrained('lms_activities')->cascadeOnDelete();
            $table->string('submission_type', 30)->default('ONLINE_TEXT_AND_FILE');
            $table->string('grading_type', 20)->default('POINTS');
            $table->decimal('maximum_grade', 6, 2)->default(100);
            $table->decimal('passing_grade', 6, 2)->nullable();
            $table->unsignedInteger('maximum_attempts')->default(1);
            $table->boolean('allow_late_submission')->default(true);
            $table->decimal('late_penalty_percent', 5, 2)->default(0);   // §9
            $table->unsignedInteger('late_penalty_days_step')->nullable(); // penalti bertingkat per N hari
            $table->decimal('late_penalty_max_percent', 5, 2)->nullable();
            $table->boolean('group_submission')->default(false);
            $table->boolean('require_file')->default(false);
            $table->boolean('require_text')->default(false);
            $table->json('allowed_file_types')->nullable();              // ['PDF','DOCX', ...]
            $table->unsignedBigInteger('max_file_size_kb')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('lms_assignment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('lms_assignments')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('student_id')->nullable();
            $table->unsignedInteger('attempt')->default(1);
            $table->string('status', 20)->default('DRAFT');               // AssignmentSubmissionStatus
            $table->longText('text_content')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->unsignedBigInteger('grader_id')->nullable();
            $table->decimal('grade', 6, 2)->nullable();                   // nilai final (setelah penalti)
            $table->decimal('original_grade', 6, 2)->nullable();          // nilai sebelum penalti (§9)
            $table->decimal('late_penalty_percent', 5, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->boolean('late')->default(false);
            $table->boolean('passed')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['assignment_id', 'user_id', 'attempt']);
            $table->index(['assignment_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('lms_assignment_submission_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('lms_assignment_submissions')->cascadeOnDelete();
            $table->string('purpose', 20)->default('SUBMISSION');         // SUBMISSION | FEEDBACK
            $table->string('file_path', 1000);
            $table->string('file_name', 255);
            $table->string('file_mime', 150)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('file_type', 20)->nullable();                   // PDF, DOCX, IMAGE, ...
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index(['submission_id', 'purpose']);
        });

        Schema::create('lms_activity_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('activity_id')->constrained('lms_activities')->cascadeOnDelete();
            $table->foreignId('learning_item_id')->nullable()->constrained('lms_learning_items')->nullOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('student_id')->nullable();
            $table->foreignId('enrollment_id')->nullable()->constrained('lms_enrollments')->nullOnDelete();
            $table->string('source', 20);                                 // ActivityGradeSource
            $table->decimal('grade', 6, 2)->nullable();                   // nilai final
            $table->decimal('raw_grade', 6, 2)->nullable();               // skor mentah (mis. 7/10)
            $table->decimal('maximum_grade', 6, 2)->default(100);
            $table->decimal('original_grade', 6, 2)->nullable();          // sebelum penalti
            $table->boolean('passed')->nullable();
            $table->text('feedback')->nullable();
            $table->unsignedBigInteger('graded_by')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['activity_id', 'user_id']);
            $table->index(['course_id', 'user_id']);
            $table->index(['user_id', 'graded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_activity_grades');
        Schema::dropIfExists('lms_assignment_submission_files');
        Schema::dropIfExists('lms_assignment_submissions');
        Schema::dropIfExists('lms_assignments');
        Schema::dropIfExists('lms_activities');
    }
};
