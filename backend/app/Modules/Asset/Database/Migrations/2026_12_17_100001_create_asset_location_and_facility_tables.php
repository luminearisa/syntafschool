<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kampus → gedung → lantai → lokasi/ruang, lalu fasilitas yang menempatinya.
 *
 * Menyimpan hierarki di satu tabel `locations` (dengan `parent_location_id`)
 * lebih tahan terhadap kenyataan lapangan daripada memecah setiap tingkat ke
 * tabelnya sendiri: ruang dapat berpindah lantai, dan nama gedung berubah tanpa
 * memindahkan baris. `campuses`, `buildings`, dan `building_floors` tetap ada
 * karena ketiganya punya atribut operasional sendiri (alamat, tahun bangun,
 * luas), bukan sekadar nama.
 *
 * Kolom status disimpan sebagai string (bukan enum basis data) dengan alasan
 * yang sama seperti modul lain: menambah satu status baru tidak boleh menuntut
 * migrasi yang mengunci tabel besar, dan transisi status tetap ditegakkan
 * lapisan domain (StateMachine), bukan oleh tipe kolom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('code', 40)->unique();
            $table->string('name', 160);
            $table->text('address')->nullable();
            $table->string('city', 80)->nullable();
            $table->string('province', 80)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('head_name', 160)->nullable();
            $table->string('status', 24)->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('buildings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('campus_id')->constrained('campuses')->cascadeOnDelete();
            $table->string('code', 40)->unique();
            $table->string('name', 160);
            $table->string('building_type', 40)->nullable();
            $table->unsignedSmallInteger('year_built')->nullable();
            $table->unsignedSmallInteger('floors_count')->default(1);
            $table->decimal('area_m2', 12, 2)->nullable();
            $table->string('status', 24)->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('building_floors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('building_id')->constrained('buildings')->cascadeOnDelete();
            $table->integer('level');
            $table->string('name', 80);
            $table->decimal('area_m2', 12, 2)->nullable();
            $table->string('status', 24)->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained('campuses')->nullOnDelete();
            $table->foreignId('building_id')->nullable()->constrained('buildings')->nullOnDelete();
            $table->foreignId('building_floor_id')->nullable()->constrained('building_floors')->nullOnDelete();
            $table->foreignId('parent_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('code', 60)->unique();
            $table->string('name', 160);
            $table->string('location_type', 32);
            $table->string('path', 255)->nullable();
            $table->unsignedSmallInteger('level')->default(4);
            $table->unsignedInteger('capacity')->nullable();
            $table->decimal('area_m2', 12, 2)->nullable();
            $table->string('floor_label', 40)->nullable();
            $table->string('function', 80)->nullable();
            $table->string('status', 24)->default('ACTIVE');
            $table->foreignId('responsible_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('contact', 80)->nullable();
            $table->unsignedBigInteger('academic_room_id')->nullable()->comment('Penghubung ke ruang modul Akademik (bila dipetakan)');
            $table->boolean('is_bookable')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['campus_id', 'location_type']);
        });
        Schema::create('facility_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('category', 60)->nullable();
            $table->unsignedInteger('default_capacity')->nullable();
            $table->boolean('requires_approval')->default(true);
            $table->boolean('is_bookable')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('facilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained('campuses')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('facility_type_id')->nullable()->constrained('facility_types')->nullOnDelete();
            $table->string('facility_code', 60)->unique();
            $table->string('name', 160);
            $table->unsignedInteger('capacity_total')->nullable();
            $table->unsignedInteger('capacity_normal')->nullable();
            $table->unsignedInteger('capacity_exam')->nullable();
            $table->unsignedInteger('capacity_event')->nullable();
            $table->string('status', 24)->default('ACTIVE');
            $table->string('availability', 24)->default('AVAILABLE');
            $table->string('condition', 24)->default('GOOD');
            $table->string('safety_status', 24)->nullable();
            $table->string('maintenance_status', 24)->nullable();
            $table->foreignId('responsible_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('operational_hours')->nullable();
            $table->unsignedInteger('min_booking_minutes')->nullable();
            $table->unsignedInteger('max_booking_minutes')->nullable();
            $table->unsignedInteger('advance_booking_days')->nullable();
            $table->boolean('requires_approval')->nullable();
            $table->json('allowed_roles')->nullable();
            $table->decimal('utilization_rate', 6, 2)->default(0);
            $table->unsignedInteger('booking_count')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'availability']);
        });
        Schema::create('facility_attributes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('facility_type_id')->constrained('facility_types')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('label', 120);
            $table->string('data_type', 24)->default('string');
            $table->string('unit', 24)->nullable();
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['facility_type_id', 'code']);
        });
        Schema::create('facility_attribute_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('facility_attributes')->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['facility_id', 'attribute_id']);
        });
        Schema::create('facility_operating_hours', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->unsignedSmallInteger('day_of_week')->comment('0=Minggu … 6=Sabtu');
            $table->string('open_time', 5)->nullable();
            $table->string('close_time', 5)->nullable();
            $table->boolean('is_closed')->default(false);
            $table->string('notes', 160)->nullable();
            $table->timestamps();
            $table->unique(['facility_id', 'day_of_week']);
        });
        Schema::create('facility_blackout_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('reason', 200)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['facility_id', 'start_at', 'end_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_blackout_periods');
        Schema::dropIfExists('facility_operating_hours');
        Schema::dropIfExists('facility_attribute_values');
        Schema::dropIfExists('facility_attributes');
        Schema::dropIfExists('facilities');
        Schema::dropIfExists('facility_types');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('building_floors');
        Schema::dropIfExists('buildings');
        Schema::dropIfExists('campuses');
    }
};
