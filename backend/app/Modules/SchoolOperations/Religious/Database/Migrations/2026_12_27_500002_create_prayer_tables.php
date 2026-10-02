<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jenis shalat, sumber waktu, jadwal + versi + override, shalat berjamaah, dan kebijakan presensi shalat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prayer_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            $table->string('arabic_name', 80)->nullable();
            $table->string('category', 20)->default('OBLIGATORY');
            $table->unsignedInteger('default_rakaat')->nullable();
            $table->boolean('congregation_required')->default(false);
            $table->boolean('ramadan_only')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->json('config')->nullable();
            $table->timestamps();
        });
        Schema::create('prayer_time_sources', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 160);
            $table->string('provider', 40)->default('MANUAL');
            $table->json('config_data')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('prayer_schedules', function (Blueprint $table) {
            $table->id();
            $table->date('schedule_date');
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->unsignedBigInteger('mosque_id')->nullable()->index();
            $table->string('prayer_type', 30);
            $table->time('adhan_time')->nullable();
            $table->time('iqamah_time')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('status', 20)->default('SCHEDULED');
            $table->string('source', 20)->default('MANUAL');
            $table->unsignedBigInteger('source_id')->nullable()->index();
            $table->boolean('is_ramadan')->default(false);
            $table->unsignedInteger('version_number')->default(1);
            $table->string('timezone', 40)->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('prayer_schedule_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('schedule_id')->index();
            $table->unsignedInteger('version_number')->default(1);
            $table->json('snapshot')->nullable();
            $table->text('change_reason')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable()->index();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();
        });
        Schema::create('prayer_schedule_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('prayer_schedule_id')->nullable()->index();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->unsignedBigInteger('mosque_id')->nullable()->index();
            $table->date('override_date');
            $table->string('prayer_type', 30);
            $table->time('adhan_time')->nullable();
            $table->time('iqamah_time')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->string('status', 20)->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('congregational_prayers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('prayer_schedule_id')->nullable()->index();
            $table->unsignedBigInteger('mosque_id')->index();
            $table->string('prayer_type', 30);
            $table->date('congregation_date');
            $table->unsignedBigInteger('imam_id')->nullable()->index();
            $table->unsignedBigInteger('muadzin_id')->nullable()->index();
            $table->unsignedBigInteger('substitute_imam_id')->nullable()->index();
            $table->unsignedBigInteger('substitute_muadzin_id')->nullable()->index();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedInteger('participant_estimate')->nullable();
            $table->unsignedInteger('participant_actual')->nullable();
            $table->string('status', 20)->default('SCHEDULED');
            $table->unsignedBigInteger('roster_id')->nullable()->index();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('prayer_attendance_policies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 160);
            $table->string('scope_type', 20)->default('GLOBAL');
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->unsignedBigInteger('dormitory_id')->nullable()->index();
            $table->string('grade_level', 30)->nullable();
            $table->unsignedBigInteger('program_id')->nullable()->index();
            $table->json('prayer_types')->nullable();
            $table->unsignedInteger('grace_period_minutes')->default(0);
            $table->unsignedInteger('late_threshold_minutes')->default(0);
            $table->unsignedInteger('window_before_minutes')->default(0);
            $table->unsignedInteger('window_after_minutes')->default(0);
            $table->json('excused_rules')->nullable();
            $table->json('weekend_rules')->nullable();
            $table->json('holiday_rules')->nullable();
            $table->boolean('require_congregation')->default(false);
            $table->string('status', 20)->default('ACTIVE');
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prayer_attendance_policies');
        Schema::dropIfExists('congregational_prayers');
        Schema::dropIfExists('prayer_schedule_overrides');
        Schema::dropIfExists('prayer_schedule_versions');
        Schema::dropIfExists('prayer_schedules');
        Schema::dropIfExists('prayer_time_sources');
        Schema::dropIfExists('prayer_types');
    }
};
