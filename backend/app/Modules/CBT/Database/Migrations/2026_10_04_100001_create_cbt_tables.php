<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul CBT — bank soal berversi, kisi, sesi ujian, percobaan, jawaban,
 * insiden integritas, dan statistik butir.
 *
 * Tabel memakai awalan `cbt_` agar tidak menabrak fondasi A8 (`questions`,
 * `exam_schedules`) yang masih berupa skema tanpa mesin ujian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbt_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 40)->nullable()->unique();
            $table->string('question_type', 30);
            $table->string('difficulty', 20)->default('medium');
            $table->string('topic', 120)->nullable();
            $table->string('bloom_level', 30)->nullable();
            $table->text('stem');
            $table->text('explanation')->nullable();
            $table->decimal('points', 6, 2)->default(1);
            $table->json('payload')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['subject_id', 'topic']);
            $table->index(['question_type', 'difficulty']);
        });

        Schema::create('cbt_question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('cbt_questions')->cascadeOnDelete();
            $table->string('option_key', 8);
            $table->text('text');
            $table->boolean('is_correct')->default(false);
            $table->string('match_key', 8)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['question_id', 'option_key']);
        });

        Schema::create('cbt_question_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('cbt_questions')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['question_id', 'version']);
        });

        Schema::create('cbt_blueprints', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('code', 40)->nullable()->unique();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->text('instructions')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->unsignedSmallInteger('question_count')->default(0);
            $table->decimal('max_score', 7, 2)->default(100);
            $table->decimal('passing_score', 5, 2)->default(70);
            $table->boolean('shuffle_questions')->default(true);
            $table->boolean('shuffle_options')->default(true);
            $table->boolean('negative_marking')->default(false);
            $table->decimal('negative_fraction', 4, 2)->default(0);
            $table->unsignedTinyInteger('attempts_allowed')->default(1);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('cbt_blueprint_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blueprint_id')->constrained('cbt_blueprints')->cascadeOnDelete();
            $table->string('topic', 120)->nullable();
            $table->string('difficulty', 20)->nullable();
            $table->string('question_type', 30)->nullable();
            $table->unsignedSmallInteger('count')->default(1);
            $table->decimal('points', 6, 2)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('cbt_blueprint_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blueprint_id')->constrained('cbt_blueprints')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('cbt_questions')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->decimal('points', 6, 2)->nullable();
            $table->boolean('required')->default(true);
            $table->timestamps();

            $table->unique(['blueprint_id', 'question_id']);
        });

        Schema::create('cbt_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('title', 180);
            $table->string('code', 40)->unique();
            $table->foreignId('blueprint_id')->constrained('cbt_blueprints')->restrictOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('school_class_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('semester_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('proctor_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('results_released_at')->nullable();
            $table->string('access_token', 12)->nullable();
            $table->json('settings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'starts_at']);
            $table->index(['school_class_id', 'starts_at']);
        });

        Schema::create('cbt_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('cbt_sessions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('seat', 20)->nullable();
            $table->boolean('is_eligible')->default(true);
            $table->unsignedSmallInteger('extra_minutes')->default(0);
            $table->string('attendance', 20)->default('expected');
            $table->timestamp('checked_in_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'student_id']);
        });

        Schema::create('cbt_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('cbt_sessions')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('cbt_participants')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt_number')->default(1);
            $table->string('status', 20)->default('in_progress');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('question_order')->nullable();
            $table->json('option_orders')->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->decimal('max_score', 8, 2)->nullable();
            $table->decimal('percentage', 6, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->boolean('needs_manual_grade')->default(false);
            $table->unsignedSmallInteger('integrity_score')->default(100);
            $table->string('client_ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'student_id', 'attempt_number']);
            $table->index(['session_id', 'status']);
        });

        Schema::create('cbt_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('cbt_attempts')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('cbt_questions')->cascadeOnDelete();
            $table->unsignedInteger('question_version')->default(1);
            $table->json('response')->nullable();
            $table->boolean('flagged')->default(false);
            $table->boolean('auto_graded')->default(false);
            $table->boolean('is_correct')->nullable();
            $table->decimal('awarded', 8, 2)->nullable();
            $table->decimal('max_points', 8, 2)->default(0);
            $table->text('grader_note')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('graded_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['attempt_id', 'question_id']);
        });

        Schema::create('cbt_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('cbt_sessions')->cascadeOnDelete();
            $table->foreignId('attempt_id')->nullable()->constrained('cbt_attempts')->cascadeOnDelete();
            $table->string('type', 40);
            $table->unsignedTinyInteger('severity')->default(1);
            $table->text('note')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['session_id', 'type']);
        });

        Schema::create('cbt_item_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('cbt_sessions')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('cbt_questions')->cascadeOnDelete();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('correct')->default(0);
            $table->decimal('p_value', 6, 4)->nullable();
            $table->decimal('discrimination', 6, 4)->nullable();
            $table->decimal('avg_points', 8, 2)->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbt_item_stats');
        Schema::dropIfExists('cbt_incidents');
        Schema::dropIfExists('cbt_answers');
        Schema::dropIfExists('cbt_attempts');
        Schema::dropIfExists('cbt_participants');
        Schema::dropIfExists('cbt_sessions');
        Schema::dropIfExists('cbt_blueprint_items');
        Schema::dropIfExists('cbt_blueprint_rules');
        Schema::dropIfExists('cbt_blueprints');
        Schema::dropIfExists('cbt_question_revisions');
        Schema::dropIfExists('cbt_question_options');
        Schema::dropIfExists('cbt_questions');
    }
};
