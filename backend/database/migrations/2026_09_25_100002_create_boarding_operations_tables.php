<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Boarding School / Pesantren — kegiatan, absensi, perizinan, kedisiplinan,
 * prestasi, kesehatan, dan kunjungan.
 *
 * Absensi memakai tipe absensi yang configurable (master
 * `boarding_attendance_types`, bukan hard-code hadir/tidak hadir — §17).
 * Identitas santri untuk scan RFID memakai `wallet_rfid_cards` milik modul
 * Wallet; boarding hanya konsumen (§23, §61). Kepulangan (§30) dipisah dari
 * izin keluar biasa (§28). Notifikasi memakai tabel `notifications` dan
 * audit memakai `activity_log` yang sudah ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boarding_attendance_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            // Kategori perilaku status, bukan kategori kegiatan.
            $table->enum('category', ['present', 'excused', 'unexcused', 'late', 'neutral'])->default('present');
            $table->boolean('is_present')->default(true);
            $table->boolean('is_excused')->default(false);
            $table->boolean('requires_note')->default(false);
            $table->boolean('requires_approval')->default(false);
            $table->string('color', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('boarding_activities', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->enum('category', [
                'ibadah', 'academic', 'tahfidz', 'meal', 'cleanliness',
                'discipline', 'sports', 'health', 'dormitory', 'other',
            ])->default('other');
            // Tipe absensi default kegiatan; himpunan lengkap lewat pivot di bawah (§18).
            $table->foreignId('default_attendance_type_id')->nullable()
                ->constrained('boarding_attendance_types')->nullOnDelete();
            // Ketelambesan dihitung terhadap menit setelah jam mulai jadwal (§25).
            $table->unsignedSmallInteger('late_after_minutes')->nullable();
            $table->boolean('is_required')->default(true);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['category', 'is_active']);
        });

        // Konfigurasi activity -> attendance type (§18): Shalat ≠ Halaqah ≠ Makan.
        Schema::create('boarding_activity_attendance_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('boarding_activities')->cascadeOnDelete();
            $table->foreignId('attendance_type_id')->constrained('boarding_attendance_types')->cascadeOnDelete();
            $table->unsignedTinyInteger('sort_order')->default(0);

            $table->unique(['activity_id', 'attendance_type_id']);
        });

        Schema::create('boarding_activity_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('boarding_activities')->cascadeOnDelete();
            // Jadwal dapat berlaku per asrama, per kelompok, atau global.
            $table->foreignId('dormitory_id')->nullable()->constrained('boarding_dormitories')->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('boarding_groups')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 1 = Senin .. 7 = Minggu
            $table->time('start_time');
            $table->time('end_time');
            $table->date('effective_start')->nullable();
            $table->date('effective_end')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['activity_id', 'day_of_week']);
            $table->index(['dormitory_id', 'day_of_week']);
        });

        Schema::create('boarding_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('activity_id')->constrained('boarding_activities')->cascadeOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('boarding_activity_schedules')->nullOnDelete();
            $table->foreignId('attendance_type_id')->constrained('boarding_attendance_types')->restrictOnDelete();
            $table->date('date');
            // Sesi membedakan kegiatan berulang sehari (mis. sesi 1/2); default '1'.
            $table->string('session', 20)->default('1');
            $table->timestamp('recorded_at')->nullable();
            // Terlambat dihitung otomatis dari jadwal + late_after_minutes (§25).
            $table->boolean('is_late')->default(false);
            $table->unsignedSmallInteger('late_minutes')->nullable();
            $table->enum('source', ['manual', 'bulk', 'rfid'])->default('manual');
            $table->string('device', 50)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('revision')->default(1);
            $table->foreignId('revised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revised_at')->nullable();
            $table->timestamps();

            // Absensi ganda ditolak di level database (§24), selain dicegah di service.
            $table->unique(['student_id', 'activity_id', 'date', 'session']);
            $table->index(['date', 'activity_id']);
            $table->index(['student_id', 'date']);
        });

        Schema::create('boarding_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->enum('permission_type', ['sick', 'family', 'school_activity', 'out', 'special'])
                ->default('out');
            $table->string('destination', 150)->nullable();
            $table->string('reason', 500);
            $table->dateTime('start_at');
            $table->dateTime('expected_return_at');
            $table->dateTime('actual_return_at')->nullable();
            $table->boolean('late_return')->default(false);
            $table->enum('status', [
                'draft', 'submitted', 'approved', 'rejected',
                'out', 'returned', 'expired', 'cancelled',
            ])->default('draft');
            // Workflow approval configurable (§27): jumlah langkah ditentukan settings.
            $table->unsignedTinyInteger('current_step')->default(0);
            $table->unsignedTinyInteger('required_steps')->default(1);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('checked_out_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('attachment_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'status']);
            $table->index('status');
        });

        Schema::create('boarding_permission_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permission_id')->constrained('boarding_permissions')->cascadeOnDelete();
            $table->unsignedTinyInteger('step')->default(1);
            $table->enum('action', ['approved', 'rejected']);
            $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('acted_role', 100)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();

            $table->unique(['permission_id', 'step']);
        });

        // Kepulangan dibedakan dari izin keluar biasa (§30).
        Schema::create('boarding_homecomings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->enum('type', ['weekend', 'semester_break', 'lebaran', 'special'])->default('weekend');
            $table->date('home_date');
            $table->date('return_date');
            $table->date('actual_return_date')->nullable();
            $table->boolean('late_return')->default(false);
            $table->string('transport', 100)->nullable();
            $table->string('picked_up_by', 100)->nullable();
            $table->string('returned_with', 100)->nullable();
            $table->enum('status', [
                'draft', 'submitted', 'approved', 'rejected',
                'out', 'returned', 'expired', 'cancelled',
            ])->default('draft');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('checked_out_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'status']);
            $table->index('status');
        });

        Schema::create('boarding_violation_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->enum('category', [
                'ibadah', 'academic', 'tahfidz', 'cleanliness',
                'discipline', 'social', 'health', 'other',
            ])->default('discipline');
            // Poin dikonfigurasi per jenis, tidak hard-code di pencatatan (§36).
            $table->unsignedInteger('point')->default(0);
            $table->enum('severity', ['minor', 'moderate', 'severe'])->default('minor');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('boarding_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('violation_type_id')->constrained('boarding_violation_types')->restrictOnDelete();
            $table->date('date');
            $table->text('narrative')->nullable();
            // Snapshot poin saat kejadian agar perubahan master tidak mengubah histori.
            $table->unsignedInteger('points')->default(0);
            $table->string('evidence_path')->nullable();
            $table->enum('status', ['open', 'actioned', 'closed'])->default('open');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'date']);
            $table->index('status');
        });

        Schema::create('boarding_disciplinary_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('violation_id')->nullable()->constrained('boarding_violations')->nullOnDelete();
            $table->enum('action', [
                'warning', 'coaching', 'parent_notification',
                'written_warning', 'community_service', 'other',
            ])->default('warning');
            $table->date('date');
            $table->string('officer', 100)->nullable();
            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('pending');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'status']);
        });

        Schema::create('boarding_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->enum('category', [
                'tahfidz', 'academic', 'sports', 'language',
                'leadership', 'cleanliness', 'other',
            ])->default('other');
            $table->string('title', 150);
            $table->date('date');
            $table->string('level', 50)->nullable(); // sekolah/kota/provinsi/nasional
            $table->text('description')->nullable();
            $table->string('evidence_path')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'date']);
        });

        // Catatan kesehatan sederhana — bukan rekam medis (§39).
        Schema::create('boarding_health_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('condition', 100);
            $table->date('date');
            $table->text('complaint')->nullable();
            $table->text('action')->nullable();
            $table->enum('status', ['reported', 'being_treated', 'recovered', 'referred'])->default('reported');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'status']);
            $table->index('date');
        });

        Schema::create('boarding_visitors', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_name', 100);
            $table->string('identity_number', 50)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('relationship', 50)->nullable();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->date('visit_date');
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->string('purpose', 255)->nullable();
            $table->enum('status', ['waiting', 'visiting', 'finished', 'cancelled'])->default('waiting');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'visit_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boarding_visitors');
        Schema::dropIfExists('boarding_health_records');
        Schema::dropIfExists('boarding_achievements');
        Schema::dropIfExists('boarding_disciplinary_actions');
        Schema::dropIfExists('boarding_violations');
        Schema::dropIfExists('boarding_violation_types');
        Schema::dropIfExists('boarding_homecomings');
        Schema::dropIfExists('boarding_permission_approvals');
        Schema::dropIfExists('boarding_permissions');
        Schema::dropIfExists('boarding_attendances');
        Schema::dropIfExists('boarding_activity_schedules');
        Schema::dropIfExists('boarding_activity_attendance_types');
        Schema::dropIfExists('boarding_activities');
        Schema::dropIfExists('boarding_attendance_types');
    }
};
