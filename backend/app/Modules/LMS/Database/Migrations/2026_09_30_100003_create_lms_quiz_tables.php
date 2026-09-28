<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — kuis, percobaan, dan jawaban (§13, §23–§30, §51).
 *
 * Setiap attempt memilih sendiri daftar soalnya (`lms_quiz_attempt_questions`).
 * Inilah yang membuat pemilihan soal acak per siswa dan versioning soal (§22)
 * bekerja tanpa mengubah hasil percobaan yang sudah terjadi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_quizzes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->unique()->constrained('lms_activities')->cascadeOnDelete();
            $table->unsignedInteger('time_limit_minutes')->nullable();     // null = tanpa batas
            $table->unsignedInteger('attempts_allowed')->default(1);       // 0 = tak terbatas
            $table->string('grade_strategy', 20)->default('HIGHEST');      // QuizGradeStrategy
            $table->boolean('shuffle_questions')->default(false);
            $table->boolean('shuffle_answers')->default(false);
            $table->boolean('show_one_question_at_time')->default(false);
            $table->string('navigation_method', 20)->default('FREE');      // QuizNavigation
            $table->decimal('maximum_grade', 6, 2)->default(100);
            $table->decimal('passing_grade', 6, 2)->nullable();
            $table->unsignedInteger('questions_per_page')->nullable();
            $table->boolean('require_password')->default(false);
            $table->string('quiz_password', 100)->nullable();
            $table->timestamp('open_at')->nullable();
            $table->timestamp('close_at')->nullable();
            $table->json('review_options')->nullable();                    // §29
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('lms_quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained('lms_quizzes')->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->constrained('lms_questions')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('lms_question_categories')->nullOnDelete();
            $table->unsignedInteger('question_count')->nullable();         // untuk slot acak (§23)
            $table->decimal('marks', 6, 2)->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['quiz_id', 'sort_order']);
            $table->index('category_id');
        });

        Schema::create('lms_quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained('lms_quizzes')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('lms_enrollments')->nullOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('student_id')->nullable();
            $table->unsignedInteger('attempt_number')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();                   // server-side timer (§27)
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->unsignedInteger('time_limit_minutes')->nullable();     // snapshot konfigurasi
            $table->string('status', 20)->default('IN_PROGRESS');          // QuizAttemptStatus
            $table->decimal('raw_score', 8, 2)->default(0);                // total mark diperoleh
            $table->decimal('maximum_score', 8, 2)->default(0);            // total mark soal
            $table->decimal('final_grade', 6, 2)->nullable();              // dinormalisasi ke maximum_grade
            $table->boolean('passed')->nullable();
            $table->boolean('needs_manual_grading')->default(false);
            $table->unsignedBigInteger('graded_by')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['quiz_id', 'user_id', 'attempt_number']);
            $table->index(['quiz_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('lms_quiz_attempt_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('lms_quiz_attempts')->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->constrained('lms_questions')->nullOnDelete();
            $table->foreignId('question_version_id')->nullable()->constrained('lms_question_versions')->nullOnDelete();
            $table->decimal('marks', 6, 2)->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('snapshot')->nullable();                          // soal + opsi yang dilihat siswa
            $table->timestamps();

            $table->unique(['attempt_id', 'question_id']);
            $table->index(['attempt_id', 'sort_order']);
        });

        Schema::create('lms_quiz_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('lms_quiz_attempts')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('lms_questions')->cascadeOnDelete();
            $table->foreignId('question_version_id')->nullable()->constrained('lms_question_versions')->nullOnDelete();
            $table->longText('answer')->nullable();                        // jawaban teks
            $table->json('answer_data')->nullable();                       // pilihan terstruktur
            $table->decimal('marks', 6, 2)->nullable();                    // mark diperoleh
            $table->boolean('is_correct')->nullable();
            $table->text('feedback')->nullable();
            $table->unsignedBigInteger('graded_by')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();

            $table->unique(['attempt_id', 'question_id']);
            $table->index('attempt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_quiz_answers');
        Schema::dropIfExists('lms_quiz_attempt_questions');
        Schema::dropIfExists('lms_quiz_attempts');
        Schema::dropIfExists('lms_quiz_questions');
        Schema::dropIfExists('lms_quizzes');
    }
};
