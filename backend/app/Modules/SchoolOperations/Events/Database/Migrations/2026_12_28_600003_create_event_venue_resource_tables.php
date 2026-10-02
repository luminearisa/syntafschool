<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Event (School Operations Fase 4) — 600003_create_event_venue_resource_tables.
 *
 * Catatan: kolom `_id` diberi indeks; kunci asing keras hanya dipakai pada
 * tabel anak yang memang terikat siklus hidup induknya (cascade), sehingga
 * pengujian lintas modul tidak bergantung urutan penghapusan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_venues', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('facility_id')->nullable()->index();
            $t->string('venue_code', 50)->nullable();
            $t->string('venue_name', 200);
            $t->string('venue_type', 40)->index()->default('INTERNAL');
            $t->string('building', 150)->nullable();
            $t->string('floor', 50)->nullable();
            $t->string('room', 100)->nullable();
            $t->text('address')->nullable();
            $t->integer('capacity')->nullable();
            $t->boolean('is_primary')->index()->default(true);
            $t->boolean('is_backup')->index()->default(false);
            $t->boolean('is_off_campus')->default(false);
            $t->string('booking_reference', 80)->nullable();
            $t->string('booking_status', 30)->index()->default('PENDING');
            $t->dateTime('requested_at')->nullable();
            $t->dateTime('confirmed_at')->nullable();
            $t->dateTime('start_at')->nullable()->index();
            $t->dateTime('end_at')->nullable()->index();
            $t->string('requirements', 40)->nullable();
            $t->text('requirement_detail')->nullable();
            $t->text('accessibility_notes')->nullable();
            $t->text('parking_notes')->nullable();
            $t->decimal('cost')->nullable();
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_venue_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('venue_id')->nullable()->index();
            $t->unsignedBigInteger('facility_id')->nullable()->index();
            $t->unsignedBigInteger('requested_by')->nullable()->index();
            $t->integer('required_capacity')->nullable();
            $t->dateTime('start_at')->index();
            $t->dateTime('end_at')->index();
            $t->string('requirements', 40)->nullable();
            $t->json('requirement_detail')->nullable();
            $t->string('availability_status', 30)->index()->default('AVAILABLE');
            $t->json('conflict_detail')->nullable();
            $t->integer('conflict_count')->default(0);
            $t->boolean('maintenance_flag')->default(false);
            $t->boolean('academic_conflict')->default(false);
            $t->boolean('religious_conflict')->default(false);
            $t->dateTime('checked_at')->nullable();
            $t->string('approval_status', 30)->index()->default('PENDING');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->unsignedBigInteger('facility_booking_id')->nullable();
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_resource_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->string('resource_type', 40)->index();
            $t->unsignedBigInteger('resource_id')->nullable();
            $t->string('resource_name', 200)->nullable();
            $t->integer('quantity')->default(1);
            $t->string('unit', 30)->nullable();
            $t->dateTime('required_from')->nullable()->index();
            $t->dateTime('required_until')->nullable()->index();
            $t->string('provider_contract', 100)->nullable();
            $t->string('provider_reference', 80)->nullable();
            $t->string('provider_status', 30)->index()->default('PENDING');
            $t->string('availability_status', 30)->index()->default('UNKNOWN');
            $t->string('approval_status', 30)->index()->default('PENDING');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->dateTime('issued_at')->nullable();
            $t->dateTime('returned_at')->nullable();
            $t->string('return_condition', 30)->nullable();
            $t->decimal('cost')->nullable();
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->text('note')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_asset_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('resource_request_id')->nullable()->index();
            $t->unsignedBigInteger('asset_id')->nullable()->index();
            $t->string('asset_name', 200)->nullable();
            $t->string('category', 50)->nullable();
            $t->integer('quantity')->default(1);
            $t->dateTime('required_from')->nullable()->index();
            $t->dateTime('required_until')->nullable()->index();
            $t->unsignedBigInteger('asset_loan_id')->nullable();
            $t->string('provider_status', 30)->index()->default('PENDING');
            $t->string('approval_status', 30)->index()->default('PENDING');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->dateTime('issued_at')->nullable();
            $t->dateTime('returned_at')->nullable();
            $t->string('return_condition', 30)->nullable();
            $t->text('damage_note')->nullable();
            $t->decimal('cost')->nullable();
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_inventory_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('resource_request_id')->nullable()->index();
            $t->unsignedBigInteger('item_id')->nullable()->index();
            $t->string('item_name', 200)->nullable();
            $t->string('category', 50)->nullable();
            $t->integer('quantity')->default(1);
            $t->string('unit', 30)->nullable();
            $t->date('required_date')->nullable()->index();
            $t->string('approval_status', 30)->index()->default('PENDING');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->dateTime('issued_at')->nullable();
            $t->integer('issued_quantity')->nullable();
            $t->integer('returned_quantity')->nullable();
            $t->string('provider_status', 30)->index()->default('PENDING');
            $t->string('provider_reference', 80)->nullable();
            $t->decimal('cost')->nullable();
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_catering_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('resource_request_id')->nullable()->index();
            $t->unsignedBigInteger('schedule_id')->nullable()->index();
            $t->date('request_date')->nullable()->index();
            $t->time('serving_time')->nullable();
            $t->string('meal_type', 30)->index();
            $t->string('menu_request', 255)->nullable();
            $t->integer('expected_participants')->nullable();
            $t->integer('confirmed_participants')->nullable();
            $t->integer('actual_participants')->nullable();
            $t->string('special_requirements', 40)->nullable();
            $t->text('special_detail')->nullable();
            $t->json('dietary_summary')->nullable();
            $t->string('provider_status', 30)->index()->default('PENDING');
            $t->string('provider_reference', 80)->nullable();
            $t->dateTime('production_scheduled_at')->nullable();
            $t->dateTime('distributed_at')->nullable();
            $t->integer('portion_used')->nullable();
            $t->decimal('cost_per_portion')->nullable();
            $t->decimal('total_cost')->nullable();
            $t->string('approval_status', 30)->index()->default('PENDING');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_transportation_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('resource_request_id')->nullable()->index();
            $t->unsignedBigInteger('schedule_id')->nullable()->index();
            $t->date('trip_date')->nullable()->index();
            $t->dateTime('departure_at')->nullable();
            $t->dateTime('return_at')->nullable();
            $t->string('vehicle_type', 40)->nullable();
            $t->integer('vehicle_count')->default(1);
            $t->integer('passenger_count')->nullable();
            $t->string('route_request', 255)->nullable();
            $t->string('pickup_location', 255)->nullable();
            $t->string('dropoff_location', 255)->nullable();
            $t->boolean('requires_driver')->default(true);
            $t->integer('assisted_needed')->default(0);
            $t->string('provider_status', 30)->index()->default('PENDING');
            $t->string('provider_reference', 80)->nullable();
            $t->integer('assigned_vehicles')->default(0);
            $t->integer('completed_trips')->default(0);
            $t->string('approval_status', 30)->index()->default('PENDING');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->decimal('cost')->nullable();
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('event_accommodations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('event_id')->index()->constrained('events')->cascadeOnDelete();
            $t->unsignedBigInteger('resource_request_id')->nullable()->index();
            $t->string('occupant_type', 30)->default('SPEAKER');
            $t->unsignedBigInteger('occupant_id')->nullable();
            $t->string('occupant_name', 150)->nullable();
            $t->unsignedBigInteger('facility_id')->nullable()->index();
            $t->unsignedBigInteger('dormitory_id')->nullable()->index();
            $t->string('room_reference', 80)->nullable();
            $t->date('check_in_date')->nullable()->index();
            $t->date('check_out_date')->nullable()->index();
            $t->integer('bed_count')->default(1);
            $t->string('provider_status', 30)->index()->default('PENDING');
            $t->string('provider_reference', 80)->nullable();
            $t->decimal('cost')->nullable();
            $t->string('status', 20)->index()->default('ACTIVE');
            $t->text('note')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_accommodations');
        Schema::dropIfExists('event_transportation_requests');
        Schema::dropIfExists('event_catering_requests');
        Schema::dropIfExists('event_inventory_requests');
        Schema::dropIfExists('event_asset_requests');
        Schema::dropIfExists('event_resource_requests');
        Schema::dropIfExists('event_venue_requests');
        Schema::dropIfExists('event_venues');
    }
};
