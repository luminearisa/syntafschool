<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Learning Path, prerequisite, dan drip content (§1–§4).
 *
 * Learning path TIDAK menyimpan progress sendiri: `lms_learning_path_items`
 * hanya mengurutkan & memberi aturan buka pada `lms_course_sections` yang sudah
 * ada, sehingga mesin completion/progress/gradebook Phase 1–3 tetap menjadi
 * satu-satunya sumber kebenaran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_learning_paths', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->string('title', 180);
            $table->string('slug', 200)->nullable();
            $table->text('description')->nullable();
            $table->string('mode', 20)->default('FLEXIBLE');           // LearningPathMode
            $table->string('status', 20)->default('DRAFT');             // LearningPathStatus
            $table->boolean('is_default')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('drip_enabled')->default(false);
            $table->json('settings')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['course_id', 'status']);
        });

        Schema::create('lms_learning_path_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_path_id')->constrained('lms_learning_paths')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('lms_course_sections')->nullOnDelete();
            $table->string('title', 180)->nullable();                   // judul modul (fallback ke section)
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(1);            // Module 1, 2, 3
            $table->unsignedInteger('sort_order')->default(0);
            // Aturan buka modul; tetap dievaluasi oleh mesin availability.
            $table->string('unlock_rule', 30)->default('IMMEDIATE');    // DripReleaseType
            $table->timestamp('unlock_at')->nullable();
            $table->unsignedInteger('unlock_after_days')->nullable();
            $table->boolean('is_required')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['learning_path_id', 'section_id']);
            $table->index(['learning_path_id', 'sort_order']);
        });

        Schema::create('lms_item_prerequisites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            // Materi yang dikunci (item) dan/atau aktivitas yang dikunci.
            $table->foreignId('item_id')->nullable()->constrained('lms_learning_items')->cascadeOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained('lms_activities')->cascadeOnDelete();
            // Rujukan prasyarat.
            $table->foreignId('prerequisite_item_id')->nullable()->constrained('lms_learning_items')->cascadeOnDelete();
            $table->foreignId('prerequisite_activity_id')->nullable()->constrained('lms_activities')->cascadeOnDelete();
            $table->string('type', 24);                                  // PrerequisiteType
            $table->decimal('threshold', 6, 2)->nullable();              // nilai minimum / persentase
            $table->string('result_value', 40)->nullable();              // PASS / SUBMITTED / COMPLETE
            $table->timestamp('release_at')->nullable();                 // type DATE
            $table->boolean('is_enforced')->default(true);
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['course_id', 'item_id']);
            $table->index(['course_id', 'activity_id']);
        });

        Schema::create('lms_drip_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('section_id')->nullable()->constrained('lms_course_sections')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('lms_learning_items')->cascadeOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained('lms_activities')->cascadeOnDelete();
            $table->foreignId('after_item_id')->nullable()->constrained('lms_learning_items')->nullOnDelete();
            $table->string('release_type', 32)->default('IMMEDIATE');    // DripReleaseType
            $table->timestamp('release_at')->nullable();
            $table->unsignedInteger('days_after_enrollment')->nullable();
            $table->unsignedInteger('days_after_previous_completion')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['course_id', 'is_active']);
            $table->index(['section_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_drip_rules');
        Schema::dropIfExists('lms_item_prerequisites');
        Schema::dropIfExists('lms_learning_path_items');
        Schema::dropIfExists('lms_learning_paths');
    }
};
