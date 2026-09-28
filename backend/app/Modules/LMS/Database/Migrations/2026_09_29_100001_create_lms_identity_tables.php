<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identitas khusus Standalone Mode (§2, §42).
 *
 * Tabel ini SELALU dibuat, tetapi hanya dipakai ketika `LMS_STANDALONE=true`.
 * Pada Integrated Mode LMS memakai `users`, `students`, `employees` yang sudah
 * ada dan tabel-tabel di bawah dibiarkan kosong — modul tidak pernah membuat
 * duplikat User/Siswa di mode terintegrasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('email', 150)->unique();
            $table->string('username', 60)->nullable()->unique();
            $table->string('password');
            $table->string('phone', 25)->nullable();
            $table->string('avatar_path')->nullable();
            // ADMIN, TEACHER, STUDENT, PARENT, GUEST (ActorRole)
            $table->string('role', 20)->default('STUDENT');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['role', 'is_active']);
        });

        Schema::create('lms_learners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lms_user_id')->nullable()->constrained('lms_users')->nullOnDelete();
            $table->string('learner_type', 20)->default('STUDENT'); // STUDENT | TEACHER
            $table->string('code', 40)->nullable()->unique();
            $table->string('name', 150);
            $table->string('email', 150)->nullable();
            $table->string('class_label', 100)->nullable();
            $table->string('program_label', 100)->nullable();
            $table->string('photo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['learner_type', 'is_active']);
            $table->index('name');
        });

        Schema::create('lms_guardians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lms_user_id')->constrained('lms_users')->cascadeOnDelete();
            $table->foreignId('lms_learner_id')->constrained('lms_learners')->cascadeOnDelete();
            $table->string('relation', 60)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['lms_user_id', 'lms_learner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_guardians');
        Schema::dropIfExists('lms_learners');
        Schema::dropIfExists('lms_users');
    }
};
