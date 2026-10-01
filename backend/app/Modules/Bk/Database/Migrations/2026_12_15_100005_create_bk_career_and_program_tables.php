<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bimbingan karier & program kelompok BK.
 *
 * Hasil asesmen karier adalah salah satu masukan, bukan penetapan pilihan.
 * Program kelompok menautkan kehadirannya ke Modul Attendance lewat penunjuk.
 *
 * Catatan desain: kolom status/kategori memakai `string` dengan daftar nilai yang
 * dijaga PHP Enum, bukan `enum()` basis data. Alasannya: modul BK sudah menambah
 * nilai beberapa kali, dan CHECK constraint bawaan `enum()` membuat penambahan
 * nilai baru selalu berarti migrasi pemecahan tabel — di MySQL maupun SQLite.
 * Validasi tetap ada, hanya dipindahkan ke tempat yang benar (enum + Form Request).
 *
 * `school_id` nullable adalah penopang isolasi unit sekolah. Instalasi satu
 * sekolah membiarkannya kosong dan perilaku modelnya sama seperti sebelumnya;
 * instalasi yayasan dengan beberapa unit mengisinya, dan global scope
 * `BelongsToSchool` menutup data antar-unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bk_career_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->text('aspiration')->nullable();
            $table->json('interests')->nullable();
            $table->json('strengths')->nullable();
            $table->json('skills')->nullable();
            $table->json('preferred_subjects')->nullable();
            /*
             | Hasil asesmen/inventori disimpan sebagai SALAH SATU masukan
             | (`assessment_inputs`), bukan kesimpulan. Kolom-kolom di bawah adalah
             | hal yang dipegang siswa sendiri, sehingga siswa tidak terkunci pada
             | satu pilihan karena satu skor.
             */
            $table->json('assessment_inputs')->nullable();
            $table->text('university_plan')->nullable();
            $table->text('education_plan')->nullable();
            $table->text('action_plan')->nullable();
            $table->string('target_institution', 160)->nullable();
            $table->string('target_major', 160)->nullable();
            $table->json('alternative_options')->nullable();
            $table->string('exploration_status', 24)->default('NOT_STARTED');  // NOT_STARTED|EXPLORING|NARROWED|DECIDED|REVIEWING
            $table->string('guidance_status', 24)->default('NOT_STARTED');      // NOT_STARTED|ONGOING|PAUSED|COMPLETED
            $table->text('notes')->nullable();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['school_id', 'student_id'], 'bk_career_profiles_student');
            $table->index(['exploration_status', 'guidance_status'], 'bk_career_profiles_status');
        });

        Schema::create('bk_career_guidance_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('session_number', 32)->unique();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('bk_cases')->nullOnDelete();
            $table->foreignId('counselor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('focus', 32);                             // ASPIRATION|INTEREST|SKILL|EXPLORATION|UNIVERSITY|EDUCATION_PLAN|ACTION_PLAN|PROGRESS
            $table->timestamp('session_at')->nullable();
            $table->text('summary')->nullable();
            $table->json('options_explored')->nullable();
            $table->json('action_items')->nullable();
            $table->text('next_steps')->nullable();
            $table->date('next_session_date')->nullable();
            $table->string('confidentiality_level', 24)->default('STAFF_ONLY');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['student_id', 'session_at'], 'bk_career_sessions_student');
            $table->index(['focus', 'session_at'], 'bk_career_sessions_focus');
        });

        Schema::create('bk_group_programs', function (Blueprint $table): void {
            $table->id();
            $table->string('program_number', 32)->unique();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('code', 48)->nullable();
            $table->string('title', 180);
            $table->string('program_type', 32);                      // STUDY_SKILLS|LEADERSHIP|CAREER_PREPARATION|BOARDING_TRANSITION|EXAM_PREPARATION|SOCIAL_SKILLS|CHARACTER_DEVELOPMENT|OTHER
            $table->text('description')->nullable();
            $table->text('objective')->nullable();
            $table->foreignId('facilitator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('DRAFT');          // DRAFT|PLANNED|ACTIVE|ON_HOLD|COMPLETED|CANCELLED
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedInteger('target_participants')->nullable();
            $table->string('location', 160)->nullable();
            $table->boolean('requires_consent')->default(true);
            $table->string('confidentiality_level', 24)->default('STAFF_ONLY');
            $table->json('evaluation')->nullable();
            $table->text('evaluation_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['program_type', 'status'], 'bk_group_programs_type');
            $table->index(['school_id', 'status'], 'bk_group_programs_school');
        });

        Schema::create('bk_group_program_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('program_id')->constrained('bk_group_programs')->cascadeOnDelete();
            $table->unsignedInteger('session_number')->default(1);
            $table->string('title', 180);
            $table->timestamp('scheduled_start')->nullable();
            $table->timestamp('scheduled_end')->nullable();
            $table->string('location', 160)->nullable();
            $table->foreignId('facilitator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('materials')->nullable();
            $table->foreignId('material_document_id')->nullable()->constrained('bk_case_documents')->nullOnDelete();
            $table->string('status', 20)->default('PLANNED');        // PLANNED|COMPLETED|CANCELLED|RESCHEDULED
            $table->text('summary')->nullable();
            $table->text('evaluation_note')->nullable();
            /*
             | Kehadiran program ditautkan ke Modul Attendance lewat penunjuk
             | (`attendance_reference`), bukan digandakan: BK mencatat kehadiran
             | sesi BK, sedangkan absensi pelajaran tetap milik modulnya sendiri.
             */
            $table->string('attendance_reference', 64)->nullable();
            $table->timestamp('attendance_recorded_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['program_id', 'session_number'], 'bk_group_sessions_number');
            $table->index(['scheduled_start', 'status'], 'bk_group_sessions_date');
        });

        Schema::create('bk_group_program_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('program_id')->constrained('bk_group_programs')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('consent_id')->nullable()->constrained('bk_consents')->nullOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('bk_cases')->nullOnDelete();
            $table->string('status', 20)->default('INVITED');        // INVITED|ACTIVE|COMPLETED|WITHDRAWN|REMOVED
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('evaluation')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['program_id', 'student_id'], 'bk_group_members_unique');
            $table->index(['student_id', 'status'], 'bk_group_members_student');
        });

        Schema::create('bk_group_program_attendance', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('program_id')->constrained('bk_group_programs')->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('bk_group_program_sessions')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('bk_group_program_members')->cascadeOnDelete();
            $table->string('attendance_status', 16);                 // PRESENT|ABSENT|LATE|EXCUSED|SICK
            $table->text('note')->nullable();
            // Sumber kebenaran baris: ditandai pengguna atau ditarik dari Modul
            // Attendance; bila ditarik, `external_reference` menunjuk barisnya.
            $table->string('source', 20)->default('MANUAL');         // MANUAL|ATTENDANCE_MODULE|IMPORT
            $table->string('external_reference', 64)->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('marked_at')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'member_id'], 'bk_group_attendance_unique');
            $table->index(['program_id', 'attendance_status'], 'bk_group_attendance_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bk_group_program_attendance');
        Schema::dropIfExists('bk_group_program_members');
        Schema::dropIfExists('bk_group_program_sessions');
        Schema::dropIfExists('bk_group_programs');
        Schema::dropIfExists('bk_career_guidance_sessions');
        Schema::dropIfExists('bk_career_profiles');
    }
};
