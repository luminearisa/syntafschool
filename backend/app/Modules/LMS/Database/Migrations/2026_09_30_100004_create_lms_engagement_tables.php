<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — forum, lesson, choice, dan survey (§32–§39, §51).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------ FORUM (§32–36) ------------------------------
        Schema::create('lms_forums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->unique()->constrained('lms_activities')->cascadeOnDelete();
            $table->string('forum_type', 30)->default('GENERAL');
            $table->boolean('subscription_enabled')->default(true);
            $table->boolean('moderation_enabled')->default(false);
            $table->boolean('grading_enabled')->default(false);
            $table->unsignedInteger('required_posts')->default(1);         // syarat penyelesaian (§40)
            $table->unsignedInteger('max_depth')->default(3);              // batas sarang balasan (§34)
            $table->unsignedInteger('grading_max_posts')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('lms_forum_discussions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forum_id')->constrained('lms_forums')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('student_id')->nullable();
            $table->string('title', 255);
            $table->longText('content');
            $table->string('status', 20)->default('VISIBLE');              // ForumPostStatus
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->unsignedInteger('posts_count')->default(0);
            $table->timestamp('last_post_at')->nullable();
            $table->unsignedBigInteger('moderated_by')->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['forum_id', 'status']);
            $table->index(['forum_id', 'is_pinned']);
        });

        Schema::create('lms_forum_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discussion_id')->constrained('lms_forum_discussions')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('student_id')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('lms_forum_posts')->cascadeOnDelete();
            $table->longText('content');
            $table->unsignedInteger('depth')->default(1);                   // 1 = balasan langsung
            $table->string('status', 20)->default('VISIBLE');              // ForumPostStatus
            $table->boolean('is_teacher_post')->default(false);
            $table->timestamp('edited_at')->nullable();
            $table->unsignedBigInteger('moderated_by')->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->text('moderation_note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['discussion_id', 'status']);
            $table->index('parent_id');
        });

        Schema::create('lms_forum_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forum_id')->constrained('lms_forums')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->boolean('notify_new_discussion')->default(true);
            $table->boolean('notify_new_post')->default(true);
            $table->timestamps();

            $table->unique(['forum_id', 'user_id']);
        });

        // ------------------------------ LESSON (§37) ------------------------------
        Schema::create('lms_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->unique()->constrained('lms_activities')->cascadeOnDelete();
            $table->boolean('require_all_pages')->default(true);
            $table->unsignedInteger('max_attempts')->default(0);            // 0 = tak terbatas
            $table->boolean('allow_review')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('lms_lesson_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lms_lessons')->cascadeOnDelete();
            $table->string('title', 200);
            $table->longText('content')->nullable();
            $table->string('page_type', 20)->default('CONTENT');           // LessonPageType
            $table->foreignId('question_id')->nullable()->constrained('lms_questions')->nullOnDelete();
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['lesson_id', 'sort_order']);
        });

        Schema::create('lms_lesson_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('lms_lesson_pages')->cascadeOnDelete();
            $table->foreignId('next_page_id')->nullable()->constrained('lms_lesson_pages')->nullOnDelete();
            $table->string('label', 200)->nullable();                      // "Jawaban benar", "Salah", "Pilihan A"
            $table->boolean('is_correct')->default(true);
            $table->string('answer_key', 100)->nullable();                 // pencocokan jawaban
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('page_id');
        });

        Schema::create('lms_lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lms_lessons')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('lms_enrollments')->nullOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->foreignId('last_page_id')->nullable()->constrained('lms_lesson_pages')->nullOnDelete();
            $table->json('visited_pages')->nullable();
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['lesson_id', 'user_id']);
        });

        // ------------------------------ CHOICE (§38) ------------------------------
        Schema::create('lms_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->unique()->constrained('lms_activities')->cascadeOnDelete();
            $table->boolean('allow_multiple')->default(false);
            $table->unsignedInteger('limit_responses')->default(1);        // 0 = tak terbatas
            $table->string('results_visibility', 30)->default('AFTER_RESPONSE');
            $table->boolean('anonymous')->default(false);
            $table->boolean('show_available_choices')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('lms_choice_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('choice_id')->constrained('lms_choices')->cascadeOnDelete();
            $table->string('text', 500);
            $table->unsignedInteger('limit')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['choice_id', 'sort_order']);
        });

        Schema::create('lms_choice_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('choice_id')->constrained('lms_choices')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('lms_choice_options')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('student_id')->nullable();
            $table->timestamps();

            $table->index(['choice_id', 'option_id']);
            $table->index('user_id');
        });

        // ------------------------------ SURVEY (§39) ------------------------------
        Schema::create('lms_surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->unique()->constrained('lms_activities')->cascadeOnDelete();
            $table->boolean('anonymous')->default(false);
            $table->boolean('allow_multiple_submissions')->default(false);
            $table->boolean('show_results_to_students')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('lms_survey_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('lms_surveys')->cascadeOnDelete();
            $table->string('question_type', 30)->default('RATING');        // SurveyQuestionType
            $table->text('text');
            $table->json('options')->nullable();
            $table->unsignedInteger('rating_min')->default(1);
            $table->unsignedInteger('rating_max')->default(5);
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['survey_id', 'sort_order']);
        });

        Schema::create('lms_survey_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('lms_surveys')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('lms_survey_questions')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();             // null bila survei anonim
            $table->unsignedBigInteger('student_id')->nullable();
            $table->string('submission_key', 40)->nullable();              // pengelompokan satu pengisian
            $table->text('response_text')->nullable();
            $table->decimal('response_value', 10, 2)->nullable();
            $table->json('response_data')->nullable();
            $table->timestamps();

            $table->index(['survey_id', 'question_id']);
            $table->index('submission_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_survey_responses');
        Schema::dropIfExists('lms_survey_questions');
        Schema::dropIfExists('lms_surveys');
        Schema::dropIfExists('lms_choice_responses');
        Schema::dropIfExists('lms_choice_options');
        Schema::dropIfExists('lms_choices');
        Schema::dropIfExists('lms_lesson_progress');
        Schema::dropIfExists('lms_lesson_branches');
        Schema::dropIfExists('lms_lesson_pages');
        Schema::dropIfExists('lms_lessons');
        Schema::dropIfExists('lms_forum_subscriptions');
        Schema::dropIfExists('lms_forum_posts');
        Schema::dropIfExists('lms_forum_discussions');
        Schema::dropIfExists('lms_forums');
    }
};
