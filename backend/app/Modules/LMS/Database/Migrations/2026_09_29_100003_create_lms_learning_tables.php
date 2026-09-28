<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enrollment, kelompok, pengumuman, completion, bookmark, progress (§42).
 *
 * Kolom identitas (`user_id`, `enrolled_by`, `completed_by`) menunjuk `users`
 * pada Integrated Mode dan `lms_users` pada Standalone Mode. Karena tabel yang
 * ditunjuk bergantung pada mode — dan mode dapat berganti tanpa membongkar
 * database — kolomnya sengaja berupa integer ber-index TANPA foreign key.
 * Integritas dijaga kontrak domain (`UserProviderInterface`), bukan skema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('student_id')->nullable();  // students.id (integrated)
            $table->unsignedBigInteger('teacher_id')->nullable();  // employees.id (integrated)
            $table->string('role', 20)->default('STUDENT');        // EnrollmentRole
            $table->string('enrollment_method', 20)->default('MANUAL');
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->decimal('progress_percent', 5, 2)->default(0);
            $table->unsignedBigInteger('enrolled_by')->nullable();
            $table->json('metadata')->nullable();                  // asal sinkronisasi (kelas/tahun ajaran)
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['course_id', 'user_id']);
            $table->index(['course_id', 'role', 'status']);
            $table->index(['user_id', 'status']);
            $table->index('student_id');
            $table->index('teacher_id');
        });

        Schema::create('lms_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['course_id', 'name']);
        });

        Schema::create('lms_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('lms_groups')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained('lms_enrollments')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->unique(['group_id', 'enrollment_id']);
            $table->index('user_id');
        });

        Schema::create('lms_course_announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->string('title', 200);
            $table->longText('content');
            $table->boolean('pinned')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['course_id', 'published_at']);
        });

        Schema::create('lms_activity_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('lms_enrollments')->cascadeOnDelete();
            $table->foreignId('learning_item_id')->constrained('lms_learning_items')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('completion_type', 20)->default('MANUAL');
            $table->string('status', 20)->default('COMPLETE');
            $table->decimal('grade_value', 5, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['enrollment_id', 'learning_item_id']);
            $table->index(['course_id', 'completed_at']);
            $table->index(['user_id', 'completed_at']);
        });

        Schema::create('lms_course_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->unique()->constrained('lms_enrollments')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('required_items')->default(0);
            $table->unsignedInteger('completed_items')->default(0);
            $table->decimal('progress_percent', 5, 2)->default(0);
            $table->foreignId('last_item_id')->nullable()->constrained('lms_learning_items')->nullOnDelete();
            $table->foreignId('next_item_id')->nullable()->constrained('lms_learning_items')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->index(['course_id', 'progress_percent']);
        });

        Schema::create('lms_bookmarks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('learning_item_id')->nullable()->constrained('lms_learning_items')->cascadeOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'course_id', 'learning_item_id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('lms_learning_views', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('learning_item_id')->nullable()->constrained('lms_learning_items')->cascadeOnDelete();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'viewed_at']);
            $table->index(['course_id', 'viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_learning_views');
        Schema::dropIfExists('lms_bookmarks');
        Schema::dropIfExists('lms_course_progress');
        Schema::dropIfExists('lms_activity_completions');
        Schema::dropIfExists('lms_course_announcements');
        Schema::dropIfExists('lms_group_members');
        Schema::dropIfExists('lms_groups');
        Schema::dropIfExists('lms_enrollments');
    }
};
