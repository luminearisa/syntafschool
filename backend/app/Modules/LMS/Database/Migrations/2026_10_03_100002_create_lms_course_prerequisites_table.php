<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prasyarat antar-course (§18).
 *
 * Dipakai untuk rantai "Course A → Course B → Course C" pada kurikulum.
 * Pemeriksaannya **tidak** membuat mesin penyelesaian baru: gerbang ini hanya
 * membaca status ketuntasan yang sudah dihitung `CourseProgressService`, dan
 * siklus ditolak `PrerequisiteGraphService` sebelum aturan disimpan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_course_prerequisites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->foreignId('prerequisite_course_id')->constrained('lms_courses')->cascadeOnDelete();
            $table->boolean('is_enforced')->default(true);
            $table->decimal('minimum_progress', 5, 2)->default(100);
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['course_id', 'prerequisite_course_id'], 'lms_course_prereq_unique');
            $table->index('prerequisite_course_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_course_prerequisites');
    }
};
