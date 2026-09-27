<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Boarding School / Pesantren — struktur asrama & penempatan santri.
 *
 * Master orang (students, guardians, employees, users), kartu RFID
 * (`wallet_rfid_cards`), notifikasi (`notifications`), audit (`activity_log`),
 * penomoran (`document_sequences`) dan konfigurasi (`settings`) **dipakai
 * ulang** — tidak ada tabel master baru (§57, §73).
 *
 * Aturan bisnis (satu placement aktif per santri, satu santri per bed,
 * occupancy <= capacity, kecocokan gender) ditegakkan di service dengan
 * `DB::transaction` + `lockForUpdate`, bukan CHECK constraint, karena SQLite
 * menolak `ALTER TABLE ... ADD CONSTRAINT` dan skema dev/test harus sama
 * dengan produksi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boarding_dormitories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->enum('gender', ['male', 'female', 'mixed'])->default('mixed');
            $table->unsignedInteger('capacity')->default(0);
            $table->string('location', 150)->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive', 'maintenance'])->default('active');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('boarding_buildings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dormitory_id')->constrained('boarding_dormitories')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->unsignedTinyInteger('floor_count')->default(1);
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive', 'maintenance'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['dormitory_id', 'code']);
            $table->index('status');
        });

        Schema::create('boarding_floors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained('boarding_buildings')->cascadeOnDelete();
            $table->unsignedTinyInteger('floor_number');
            $table->string('name', 100)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['building_id', 'floor_number']);
        });

        Schema::create('boarding_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('floor_id')->constrained('boarding_floors')->cascadeOnDelete();
            // Denormalisasi agar filter per asrama/gender tidak perlu tiga join.
            $table->foreignId('dormitory_id')->constrained('boarding_dormitories')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->unsignedInteger('capacity')->default(1);
            $table->enum('gender', ['male', 'female', 'mixed'])->default('mixed');
            $table->enum('room_type', ['standard', 'premium', 'isolation', 'staff', 'temporary'])->default('standard');
            $table->enum('status', ['active', 'inactive', 'maintenance'])->default('active');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['dormitory_id', 'code']);
            $table->index('floor_id');
            $table->index(['status', 'gender']);
        });

        Schema::create('boarding_beds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('boarding_rooms')->cascadeOnDelete();
            $table->string('bed_number', 20);
            $table->string('label', 50)->nullable();
            $table->enum('status', ['available', 'occupied', 'maintenance', 'inactive'])->default('available');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['room_id', 'bed_number']);
            $table->index('status');
        });

        Schema::create('boarding_placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('dormitory_id')->constrained('boarding_dormitories')->restrictOnDelete();
            $table->foreignId('building_id')->nullable()->constrained('boarding_buildings')->nullOnDelete();
            $table->foreignId('floor_id')->nullable()->constrained('boarding_floors')->nullOnDelete();
            $table->foreignId('room_id')->constrained('boarding_rooms')->restrictOnDelete();
            $table->foreignId('bed_id')->nullable()->constrained('boarding_beds')->nullOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            // Placement lama tidak dihapus: ditutup (inactive) sebagai history (§9, §11).
            $table->enum('status', ['active', 'inactive'])->default('active');
            // Alasan penutupan: transfer_room, transfer_dorm, graduated, keluar, dsb.
            $table->string('end_reason', 30)->nullable();
            // Penempatan lanjutan hasil mutasi, supaya rantai history eksplisit.
            $table->foreignId('replaced_by_placement_id')->nullable()
                ->constrained('boarding_placements')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'status']);
            $table->index(['room_id', 'status']);
            $table->index(['bed_id', 'status']);
            $table->index(['dormitory_id', 'status']);
        });

        // Penugasan pengasuh: satu baris = satu tanggung jawab pada satu area.
        Schema::create('boarding_musyrifs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('dormitory_id')->nullable()->constrained('boarding_dormitories')->cascadeOnDelete();
            $table->foreignId('building_id')->nullable()->constrained('boarding_buildings')->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('boarding_rooms')->nullOnDelete();
            $table->enum('position', ['kepala_asrama', 'musyrif', 'musyrifah', 'wali_kamar', 'koordinator'])
                ->default('musyrif');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index('dormitory_id');
        });

        Schema::create('boarding_groups', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            // Pengasuh kelompok: karyawan, ditunjuk lewat penugasan musyrif juga.
            $table->foreignId('musyrif_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('dormitory_id')->nullable()->constrained('boarding_dormitories')->nullOnDelete();
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('boarding_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('boarding_groups')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->date('joined_date')->nullable();
            $table->date('left_date')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'student_id']);
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boarding_group_members');
        Schema::dropIfExists('boarding_groups');
        Schema::dropIfExists('boarding_musyrifs');
        Schema::dropIfExists('boarding_placements');
        Schema::dropIfExists('boarding_beds');
        Schema::dropIfExists('boarding_rooms');
        Schema::dropIfExists('boarding_floors');
        Schema::dropIfExists('boarding_buildings');
        Schema::dropIfExists('boarding_dormitories');
    }
};
