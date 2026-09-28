<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori, course, pengajar, section, learning item, resource, buku (§42).
 *
 * Catatan desain: kolom yang menunjuk entity akademik ERP (`academic_course_id`,
 * `academic_year_id`, `class_id`, `program_id`, `semester_id`, `teacher_id`)
 * sengaja berupa integer ber-index tanpa foreign key. LMS harus tetap dapat
 * dijalankan sebagai modul mandiri (`LMS_STANDALONE=true`) di mana tabel SIAKAD
 * boleh saja tidak ada; integritasnya dijaga `LmsIntegrationService`, bukan
 * skema database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('lms_categories')->nullOnDelete();
            $table->string('name', 150);
            $table->string('slug', 180)->unique();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('ACTIVE');
            $table->string('visibility', 20)->default('PUBLIC');
            $table->unsignedInteger('depth')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['parent_id', 'sort_order']);
            $table->index(['status', 'visibility']);
        });

        Schema::create('lms_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('lms_categories')->nullOnDelete();

            // --- Tautan akademik (opsional; hanya diisi pada Integrated Mode) ---
            $table->unsignedBigInteger('academic_course_id')->nullable();  // subjects.id
            $table->unsignedBigInteger('academic_year_id')->nullable();    // academic_years.id
            $table->unsignedBigInteger('semester_id')->nullable();         // semesters.id
            $table->unsignedBigInteger('program_id')->nullable();          // programs.id
            $table->unsignedBigInteger('class_id')->nullable();            // school_classes.id
            $table->unsignedBigInteger('teacher_id')->nullable();          // employees.id (guru utama)

            $table->string('code', 60)->unique();
            $table->string('name', 180);
            $table->string('slug', 200)->unique();
            $table->string('short_description', 500)->nullable();
            $table->longText('description')->nullable();
            $table->text('learning_objectives')->nullable();
            $table->string('thumbnail')->nullable();
            $table->string('cover_image')->nullable();
            $table->string('course_type', 20)->default('ACADEMIC');
            $table->string('level', 20)->default('BEGINNER');
            $table->string('language', 10)->default('id');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->string('visibility', 30)->default('ENROLLED_ONLY');
            $table->string('enrollment_method', 20)->default('MANUAL');
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('max_students')->nullable();
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'visibility']);
            $table->index(['category_id', 'status']);
            $table->index(['academic_course_id', 'class_id']);
            $table->index('name');
        });

        Schema::create('lms_course_teachers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            // Identitas pelaku: users.id (integrated) atau lms_users.id
            // (standalone) — tanpa FK, lihat catatan pada migrasi learning.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable(); // employees.id (integrated)
            $table->string('name', 150)->nullable();               // denormalisasi tampilan
            $table->string('role', 20)->default('TEACHER');        // EnrollmentRole
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['course_id', 'user_id']);
            $table->index(['user_id', 'role']);
        });

        Schema::create('lms_course_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('summary', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('visibility', 20)->default('VISIBLE');
            // Aturan ketersediaan (§21): start/end + daftar aturan lanjutan.
            $table->timestamp('available_from')->nullable();
            $table->timestamp('available_until')->nullable();
            $table->json('availability')->nullable();
            $table->string('completion_rule', 30)->default('ALL_ITEMS');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['course_id', 'sort_order']);
        });

        Schema::create('lms_learning_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('lms_course_sections')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('lms_learning_items')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('item_type', 20)->default('RESOURCE');    // ItemType
            $table->string('resource_type', 20)->nullable();         // ResourceType
            $table->string('activity_type', 20)->nullable();         // ActivityType
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('visibility', 20)->default('VISIBLE');
            $table->boolean('is_required')->default(true);
            $table->string('completion_type', 20)->default('MANUAL');
            $table->decimal('completion_min_grade', 5, 2)->nullable();
            $table->boolean('is_preview')->default(false);           // dapat dilihat guest
            $table->json('availability')->nullable();
            $table->unsignedInteger('estimated_minutes')->nullable();
            // Tautan lunak ke aktivitas ERP (assignments.id) untuk phase lanjutan.
            $table->unsignedBigInteger('external_activity_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['section_id', 'sort_order']);
            $table->index(['course_id', 'item_type']);
            $table->index(['course_id', 'activity_type']);
        });

        Schema::create('lms_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_item_id')->unique()->constrained('lms_learning_items')->cascadeOnDelete();
            $table->longText('content')->nullable();          // PAGE / TEXT (HTML tersanitasi)
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_mime', 150)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('external_url', 1000)->nullable();
            $table->string('open_in', 20)->default('NEW_TAB');
            $table->string('media_source', 20)->nullable();   // UPLOAD | EXTERNAL | EMBED
            $table->string('video_provider', 40)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('lms_book_chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_item_id')->constrained('lms_learning_items')->cascadeOnDelete();
            $table->string('title', 200);
            $table->longText('content')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['learning_item_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_book_chapters');
        Schema::dropIfExists('lms_resources');
        Schema::dropIfExists('lms_learning_items');
        Schema::dropIfExists('lms_course_sections');
        Schema::dropIfExists('lms_course_teachers');
        Schema::dropIfExists('lms_courses');
        Schema::dropIfExists('lms_categories');
    }
};
