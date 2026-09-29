<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — kelas langsung, kehadiran, dan rekaman (§13–§17).
 *
 * Provider disimpan sebagai string dan diselesaikan lewat
 * `LiveMeetingProviderInterface`; tidak ada SDK provider yang di-hardcode.
 * Kehadiran hanya otomatis bila provider mengirim data — selain itu guru
 * mencatat manual (`source = MANUAL`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_live_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('lms_course_sections')->nullOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained('lms_activities')->nullOnDelete();
            $table->string('type', 20)->default('LIVE_CLASS');           // LiveSessionType
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('provider', 20)->default('EXTERNAL');         // LiveSessionProvider
            $table->timestamp('start_at');
            $table->timestamp('end_at')->nullable();
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->string('meeting_url', 500)->nullable();
            $table->string('meeting_id', 120)->nullable();
            $table->string('passcode', 60)->nullable();
            $table->string('host_url', 500)->nullable();                 // tautan khusus pengajar
            $table->string('status', 20)->default('SCHEDULED');          // LiveSessionStatus
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->boolean('recurring')->default(false);
            $table->string('recurrence_rule', 120)->nullable();           // mis. FREQ=WEEKLY;BYDAY=TU
            $table->boolean('attendance_enabled')->default(true);
            $table->boolean('is_recorded')->default(false);
            $table->json('settings')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['course_id', 'start_at']);
            $table->index(['status', 'start_at']);
            $table->index(['type', 'start_at']);
        });

        Schema::create('lms_live_session_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_session_id')->constrained('lms_live_sessions')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('lms_enrollments')->nullOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('status', 20)->default('ABSENT');             // LiveAttendanceStatus
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->unsignedInteger('minutes_attended')->nullable();
            $table->string('source', 20)->default('MANUAL');             // MANUAL / PROVIDER / SELF
            $table->unsignedBigInteger('marked_by')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['live_session_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('lms_live_session_recordings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_session_id')->nullable()->constrained('lms_live_sessions')->nullOnDelete();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('lms_learning_items')->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('url', 500);
            $table->string('provider', 20)->default('EXTERNAL');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('completion_threshold')->nullable();  // mis. 80 (%)
            $table->boolean('is_published')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['course_id', 'is_published']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_live_session_recordings');
        Schema::dropIfExists('lms_live_session_attendance');
        Schema::dropIfExists('lms_live_sessions');
    }
};
