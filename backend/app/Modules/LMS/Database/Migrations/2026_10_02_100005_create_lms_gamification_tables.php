<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — badge, XP, dan rentetan belajar (§26–§30).
 *
 * `dedupe_key` unik pada ledger poin mencegah XP ganda dari peristiwa yang
 * sama (anti-abuse §72), dan riwayat badge tidak diubah setelah diberikan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_badges', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('icon', 60)->nullable();
            $table->string('image_path')->nullable();
            $table->string('category', 60)->nullable();
            $table->string('criteria_label', 255)->nullable();
            $table->string('rule_type', 24);                             // BadgeRuleType
            $table->json('rule_config')->nullable();
            $table->unsignedInteger('points')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('issuer', 150)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('lms_badge_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('badge_id')->constrained('lms_badges')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('lms_courses')->cascadeOnDelete();
            $table->string('type', 24);                                   // BadgeRuleType
            $table->decimal('threshold', 8, 2)->nullable();
            $table->unsignedInteger('count')->nullable();
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['badge_id', 'is_active']);
        });

        Schema::create('lms_badge_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('badge_id')->constrained('lms_badges')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('lms_enrollments')->nullOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('student_id')->nullable();
            $table->string('source', 20)->default('AUTOMATIC');           // BadgeAwardSource
            $table->string('reason', 255)->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('awarded_at');
            $table->unsignedBigInteger('awarded_by')->nullable();
            $table->timestamps();

            // Anti duplikasi: satu badge per siswa per course.
            $table->unique(['badge_id', 'user_id', 'course_id'], 'lms_badge_award_unique');
            $table->index(['user_id', 'awarded_at']);
        });

        Schema::create('lms_gamification_points', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->foreignId('course_id')->nullable()->constrained('lms_courses')->cascadeOnDelete();
            $table->string('event_type', 40);                             // GamificationEventType
            $table->integer('points');
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('dedupe_key', 120)->unique();
            $table->string('note', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('earned_at');
            $table->timestamps();

            $table->index(['user_id', 'earned_at']);
            $table->index(['course_id', 'event_type']);
        });

        Schema::create('lms_learning_streaks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->foreignId('course_id')->nullable()->constrained('lms_courses')->cascadeOnDelete();
            $table->unsignedInteger('current_streak')->default(0);
            $table->unsignedInteger('longest_streak')->default(0);
            $table->date('last_activity_date')->nullable();
            $table->date('streak_started_on')->nullable();
            $table->unsignedInteger('total_active_days')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'course_id'], 'lms_streak_user_course_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_learning_streaks');
        Schema::dropIfExists('lms_gamification_points');
        Schema::dropIfExists('lms_badge_awards');
        Schema::dropIfExists('lms_badge_rules');
        Schema::dropIfExists('lms_badges');
    }
};
