<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — mesin konten interaktif (§7–§12).
 *
 * Satu tabel generik + versi. Bentuk isi tiap tipe hidup di `content_data`
 * sehingga menambah tipe baru cukup menambah driver, bukan kolom baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_interactive_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->nullable()->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('lms_learning_items')->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('type', 24);                                  // InteractiveContentType
            $table->json('content_data')->nullable();
            $table->json('configuration')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('DRAFT');              // InteractiveContentStatus
            $table->boolean('is_reusable')->default(false);
            // Aturan penyelesaian bila dikonfigurasi (§8, §9).
            $table->string('completion_rule', 24)->default('NONE');
            $table->unsignedInteger('required_interactions')->nullable();
            $table->unsignedInteger('completion_min_slides')->nullable();
            $table->decimal('completion_min_score', 6, 2)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['course_id', 'type']);
            $table->index(['type', 'status']);
        });

        Schema::create('lms_interactive_content_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interactive_content_id')->constrained('lms_interactive_contents')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('content_data')->nullable();
            $table->json('configuration')->nullable();
            $table->string('change_note', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['interactive_content_id', 'version']);
        });

        Schema::create('lms_interactive_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interactive_content_id')->constrained('lms_interactive_contents')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('lms_learning_items')->nullOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('lms_enrollments')->cascadeOnDelete();
            // users.id (integrated) / lms_users.id (standalone) — tanpa FK.
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('attempt_number')->default(1);
            $table->string('status', 20)->default('IN_PROGRESS');         // IN_PROGRESS / COMPLETED / ABANDONED
            $table->unsignedInteger('interactions_seen')->default(0);
            $table->unsignedInteger('interactions_required')->nullable();
            $table->decimal('score', 6, 2)->nullable();
            $table->decimal('max_score', 6, 2)->nullable();
            $table->decimal('percentage', 6, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->unsignedInteger('slides_seen')->default(0);
            $table->unsignedInteger('cards_known')->default(0);
            $table->json('state')->nullable();                            // posisi terakhir, kartu sulit, dll.
            $table->json('responses')->nullable();                        // jawaban per interaksi
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['interactive_content_id', 'user_id'], 'lms_interactive_response_user_unique');
            $table->index(['course_id', 'user_id']);
            $table->index(['item_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_interactive_responses');
        Schema::dropIfExists('lms_interactive_content_versions');
        Schema::dropIfExists('lms_interactive_contents');
    }
};
