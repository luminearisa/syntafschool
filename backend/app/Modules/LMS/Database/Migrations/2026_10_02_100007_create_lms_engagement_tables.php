<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — peristiwa belajar ringan, template course, jejak salin, dan arsip
 * (§38, §46–§51).
 *
 * `lms_learning_events` adalah satu tabel peristiwa dengan agregasi sederhana —
 * bukan platform analitik. Karena pemanggil hanya lewat `LearningEventService`,
 * penyimpanan dapat diganti tanpa mengubah domain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_learning_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreignId('course_id')->nullable()->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('lms_course_sections')->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('lms_learning_items')->nullOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained('lms_activities')->nullOnDelete();
            $table->foreignId('interactive_content_id')->nullable()->constrained('lms_interactive_contents')->nullOnDelete();
            $table->foreignId('live_session_id')->nullable()->constrained('lms_live_sessions')->nullOnDelete();
            $table->string('event_type', 40);                             // LearningEventType
            $table->string('actor_role', 20)->nullable();                 // ActorRole
            $table->json('context')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['course_id', 'event_type', 'occurred_at'], 'lms_event_course_type_idx');
            $table->index(['user_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
        });

        Schema::create('lms_course_templates', function (Blueprint $table) {
            $table->id();
            $table->string('title', 180);
            $table->string('code', 60)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('lms_categories')->nullOnDelete();
            $table->unsignedBigInteger('source_course_id')->nullable();
            $table->json('payload')->nullable();                          // struktur section + item
            $table->unsignedInteger('sections_count')->default(0);
            $table->unsignedInteger('items_count')->default(0);
            $table->string('status', 20)->default('ACTIVE');
            $table->boolean('is_system')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'category_id']);
        });

        Schema::create('lms_course_copies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_course_id')->nullable()->constrained('lms_courses')->nullOnDelete();
            $table->foreignId('target_course_id')->nullable()->constrained('lms_courses')->nullOnDelete();
            $table->string('mode', 20)->default('CONTENT');               // CONTENT / TEMPLATE / IMPORT
            $table->json('options')->nullable();
            $table->json('stats')->nullable();
            $table->string('status', 20)->default('COMPLETED');
            $table->string('error', 500)->nullable();
            $table->unsignedBigInteger('copied_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['source_course_id', 'status']);
        });

        Schema::create('lms_course_archives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->string('reason', 255)->nullable();
            $table->json('snapshot')->nullable();                         // ringkasan saat diarsipkan
            $table->unsignedInteger('students_count')->default(0);
            $table->unsignedInteger('items_count')->default(0);
            $table->unsignedInteger('completions_count')->default(0);
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->timestamp('archived_at');
            $table->unsignedBigInteger('restored_by')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();

            $table->index(['course_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_course_archives');
        Schema::dropIfExists('lms_course_copies');
        Schema::dropIfExists('lms_course_templates');
        Schema::dropIfExists('lms_learning_events');
    }
};
