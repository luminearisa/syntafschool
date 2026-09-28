<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — bank soal yang reusable (§14–§22, §51).
 *
 * Question bank tidak terikat pada satu course: guru dapat memakai ulang soal
 * lintas quiz. `lms_question_versions` menyimpan snapshot soal setiap kali
 * diubah sehingga attempt lama tetap sah (§22).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_question_banks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->nullable()->constrained('lms_courses')->cascadeOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->string('visibility', 20)->default('COURSE');          // COURSE | INSTITUTION
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['course_id', 'status']);
        });

        Schema::create('lms_question_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_id')->constrained('lms_question_banks')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('lms_question_categories')->cascadeOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['bank_id', 'parent_id']);
        });

        Schema::create('lms_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_id')->nullable()->constrained('lms_question_banks')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('lms_question_categories')->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('lms_courses')->cascadeOnDelete();
            $table->string('name', 255);                                   // judul singkat untuk guru
            $table->string('question_type', 40);                           // QuestionType
            $table->longText('question_text');
            $table->longText('general_feedback')->nullable();
            $table->decimal('default_mark', 6, 2)->default(1);
            $table->string('difficulty', 20)->default('MEDIUM');           // DifficultyLevel
            $table->string('status', 20)->default('ACTIVE');               // ACTIVE | DRAFT | ARCHIVED
            $table->unsignedInteger('version')->default(1);
            $table->json('settings')->nullable();                          // konfigurasi per tipe
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['bank_id', 'category_id']);
            $table->index(['question_type', 'difficulty']);
            $table->index('course_id');
        });

        Schema::create('lms_question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('lms_questions')->cascadeOnDelete();
            $table->string('option_key', 10)->nullable();                   // A, B, C, D (atau pasangan kiri)
            $table->longText('text');
            $table->boolean('is_correct')->default(false);
            $table->text('feedback')->nullable();
            $table->string('match_target', 255)->nullable();                // sisi kanan untuk MATCHING
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['question_id', 'sort_order']);
        });

        Schema::create('lms_question_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('lms_questions')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('question_type', 40);
            $table->longText('question_text');
            $table->longText('general_feedback')->nullable();
            $table->decimal('default_mark', 6, 2)->default(1);
            $table->json('settings')->nullable();
            $table->json('options')->nullable();                            // snapshot opsi & kunci jawaban
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['question_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_question_versions');
        Schema::dropIfExists('lms_question_options');
        Schema::dropIfExists('lms_questions');
        Schema::dropIfExists('lms_question_categories');
        Schema::dropIfExists('lms_question_banks');
    }
};
