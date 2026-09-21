<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ================= KEPEGAWAIAN =================
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nip', 30)->nullable()->unique();
            $table->string('full_name', 150);
            $table->foreignId('gender_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('religion_id')->nullable()->constrained()->nullOnDelete();
            $table->string('birth_place', 100)->nullable();
            $table->date('birth_date')->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('position', 100)->nullable();
            $table->enum('employee_type', ['guru', 'staf'])->default('guru');
            $table->enum('employment_status', ['PNS', 'PPPK', 'Honorer', 'GTY'])->default('Honorer');
            $table->date('join_date')->nullable();
            $table->boolean('is_teacher')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // ================= KESISWAAN =================
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nis', 30)->unique();
            $table->string('nisn', 30)->nullable()->unique();
            $table->string('full_name', 150);
            $table->string('nick_name', 50)->nullable();
            $table->foreignId('gender_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('religion_id')->nullable()->constrained()->nullOnDelete();
            $table->string('birth_place', 100)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('blood_type', 3)->nullable();
            $table->text('address')->nullable();
            $table->string('province_id', 2)->nullable();
            $table->string('city_id', 4)->nullable();
            $table->string('district_id', 7)->nullable();
            $table->string('village_id', 10)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('photo_path')->nullable();
            $table->foreignId('residence_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transportation_id')->nullable()->constrained()->nullOnDelete();
            $table->tinyInteger('child_order')->nullable();
            $table->tinyInteger('sibling_count')->nullable();
            $table->string('previous_school', 150)->nullable();
            $table->date('admission_date')->nullable();
            $table->foreignId('status_id')->nullable()->constrained('student_statuses')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index('full_name');
        });

        Schema::create('guardians', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 150);
            $table->foreignId('gender_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('religion_id')->nullable()->constrained()->nullOnDelete();
            $table->text('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 100)->nullable();
            $table->foreignId('occupation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('income_range_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('education_background_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('full_name');
        });

        Schema::create('student_guardians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guardian_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guardian_relation_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unique(['student_id', 'guardian_id']);
        });

        // ================= AKADEMIK =================
        Schema::create('school_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('education_level_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->string('code', 30)->unique();
            $table->foreignId('homeroom_teacher_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->integer('capacity')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('class_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['aktif', 'pindah', 'keluar'])->default('aktif');
            $table->timestamps();
            $table->unique(['student_id', 'academic_year_id']);
        });

        Schema::create('class_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['school_class_id', 'subject_id', 'academic_year_id']);
        });

        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('day_id')->constrained('days')->restrictOnDelete();
            $table->time('start_time');
            $table->time('end_time');
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
        Schema::dropIfExists('class_subjects');
        Schema::dropIfExists('class_students');
        Schema::dropIfExists('school_classes');
        Schema::dropIfExists('student_guardians');
        Schema::dropIfExists('guardians');
        Schema::dropIfExists('students');
        Schema::dropIfExists('employees');
    }
};
