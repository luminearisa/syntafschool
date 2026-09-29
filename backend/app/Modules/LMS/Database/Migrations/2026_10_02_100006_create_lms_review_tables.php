<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — ulasan & umpan balik course (§31–§32).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_course_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->foreignId('enrollment_id')->nullable()->constrained('lms_enrollments')->nullOnDelete();
            $table->unsignedTinyInteger('rating');                        // 1–5
            $table->text('review')->nullable();
            $table->string('status', 20)->default('PENDING');             // ReviewStatus
            $table->boolean('is_verified_completion')->default(false);
            $table->unsignedInteger('helpful_count')->default(0);
            $table->string('display_name', 150)->nullable();              // denormalisasi tampilan publik
            $table->unsignedBigInteger('moderated_by')->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->string('moderation_note', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['course_id', 'user_id']);
            $table->index(['course_id', 'status']);
        });

        Schema::create('lms_course_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreignId('enrollment_id')->nullable()->constrained('lms_enrollments')->nullOnDelete();
            $table->text('liked')->nullable();                            // "What did you like?"
            $table->text('improve')->nullable();                          // "What should improve?"
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('status', 20)->default('SUBMITTED');
            $table->json('meta')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['course_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_course_feedback');
        Schema::dropIfExists('lms_course_reviews');
    }
};
