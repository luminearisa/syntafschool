<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mosque master, area, akses, perlengkapan, aset, pemesanan, kebersihan, pemeliharaan, utilisasi, dan kotak donasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mosques', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->string('code', 40)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('location', 255)->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->string('gender_access', 20)->default('MIXED');
            $table->string('operational_status', 20)->default('ACTIVE');
            $table->string('latitude', 20)->nullable();
            $table->string('longitude', 20)->nullable();
            $table->string('timezone', 40)->default('Asia/Jakarta');
            $table->string('qibla_direction', 20)->nullable();
            $table->string('contact_person', 120)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->boolean('open_24h')->default(false);
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('mosque_areas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mosque_id')->index();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('area_type', 40)->default('MAIN_HALL');
            $table->unsignedInteger('capacity')->nullable();
            $table->string('gender', 20)->default('MIXED');
            $table->string('floor', 20)->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->boolean('bookable')->default(true);
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('mosque_access_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mosque_id')->index();
            $table->unsignedBigInteger('area_id')->nullable()->index();
            $table->string('access_policy', 20)->default('INSTITUTION_ONLY');
            $table->string('audience', 40)->nullable();
            $table->string('gender', 20)->default('MIXED');
            $table->string('day_of_week', 12)->nullable();
            $table->time('time_from')->nullable();
            $table->time('time_to')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('requires_escort')->default(false);
            $table->string('status', 20)->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('mosque_equipment', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mosque_id')->index();
            $table->unsignedBigInteger('area_id')->nullable()->index();
            $table->unsignedBigInteger('asset_id')->nullable()->index();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('equipment_type', 40);
            $table->unsignedInteger('quantity')->default(0);
            $table->string('condition', 20)->default('GOOD');
            $table->string('status', 20)->default('ACTIVE');
            $table->dateTime('last_checked_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('mosque_asset_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mosque_id')->index();
            $table->unsignedBigInteger('area_id')->nullable()->index();
            $table->unsignedBigInteger('asset_id')->index();
            $table->date('assigned_at');
            $table->date('released_at')->nullable();
            $table->unsignedBigInteger('assigned_by')->nullable()->index();
            $table->unsignedInteger('quantity')->default(0);
            $table->string('condition', 20)->default('GOOD');
            $table->string('status', 20)->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('mosque_bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mosque_id')->index();
            $table->unsignedBigInteger('area_id')->nullable()->index();
            $table->string('code', 40)->unique();
            $table->string('title', 200);
            $table->string('purpose', 120)->nullable();
            $table->string('booking_type', 20)->default('INTERNAL');
            $table->unsignedBigInteger('requested_by')->nullable()->index();
            $table->string('requester_name', 120)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('expected_attendees')->nullable();
            $table->string('status', 20)->default('REQUESTED');
            $table->string('approval_status', 20)->nullable();
            $table->unsignedBigInteger('workflow_instance_id')->nullable()->index();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->dateTime('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('facility_reference', 80)->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('mosque_cleaning_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mosque_id')->index();
            $table->unsignedBigInteger('area_id')->nullable()->index();
            $table->string('code', 40);
            $table->string('task_type', 20)->default('DAILY');
            $table->date('scheduled_date');
            $table->time('scheduled_time')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->string('assigned_name', 120)->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->dateTime('completed_at')->nullable();
            $table->unsignedBigInteger('inspected_by')->nullable()->index();
            $table->dateTime('inspected_at')->nullable();
            $table->string('inspection_result', 20)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('mosque_maintenance_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mosque_id')->index();
            $table->unsignedBigInteger('area_id')->nullable()->index();
            $table->unsignedBigInteger('asset_id')->nullable()->index();
            $table->unsignedBigInteger('equipment_id')->nullable()->index();
            $table->string('code', 40);
            $table->string('title', 200);
            $table->string('category', 20)->default('OTHER');
            $table->string('severity', 20)->default('MEDIUM');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('reported_by')->nullable()->index();
            $table->dateTime('reported_at')->nullable();
            $table->string('status', 20)->default('REPORTED');
            $table->string('maintenance_reference', 80)->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->decimal('cost', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('mosque_utilization_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mosque_id')->index();
            $table->unsignedBigInteger('area_id')->nullable()->index();
            $table->date('log_date');
            $table->string('prayer_type', 30)->nullable();
            $table->unsignedBigInteger('activity_id')->nullable()->index();
            $table->unsignedInteger('attendance_count')->default(0);
            $table->unsignedInteger('capacity')->nullable();
            $table->decimal('utilization_pct', 5, 2)->nullable();
            $table->string('source', 20)->default('MANUAL');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('mosque_donation_collections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mosque_id')->index();
            $table->string('box_code', 40);
            $table->date('collection_date');
            $table->unsignedBigInteger('opened_by')->nullable()->index();
            $table->unsignedBigInteger('closed_by')->nullable()->index();
            $table->unsignedBigInteger('verified_by')->nullable()->index();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('currency', 10)->default('IDR');
            $table->string('status', 20)->default('OPEN');
            $table->string('finance_reference', 80)->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('mosque_display_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mosque_id')->index();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('display_mode', 20)->default('FULLSCREEN');
            $table->json('theme')->nullable();
            $table->boolean('show_current_prayer')->default(true);
            $table->boolean('show_next_prayer')->default(true);
            $table->boolean('show_iqamah_countdown')->default(true);
            $table->boolean('show_imam')->default(true);
            $table->boolean('show_muadzin')->default(true);
            $table->boolean('show_activities')->default(true);
            $table->boolean('show_announcements')->default(true);
            $table->json('announcement_ids')->nullable();
            $table->unsignedInteger('refresh_seconds')->default(0);
            $table->string('status', 20)->default('ACTIVE');
            $table->boolean('active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mosque_display_settings');
        Schema::dropIfExists('mosque_donation_collections');
        Schema::dropIfExists('mosque_utilization_logs');
        Schema::dropIfExists('mosque_maintenance_requests');
        Schema::dropIfExists('mosque_cleaning_tasks');
        Schema::dropIfExists('mosque_bookings');
        Schema::dropIfExists('mosque_asset_assignments');
        Schema::dropIfExists('mosque_equipment');
        Schema::dropIfExists('mosque_access_rules');
        Schema::dropIfExists('mosque_areas');
        Schema::dropIfExists('mosques');
    }
};
