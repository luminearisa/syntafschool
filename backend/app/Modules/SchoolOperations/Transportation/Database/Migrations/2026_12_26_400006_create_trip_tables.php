<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perjalanan: trip aktual, penugasan kendaraan/pengemudi per trip, manifes,
 * penumpang, halte trip (rencana vs aktual), event naik/turun, laporan
 * keterlambatan, dan transfer penumpang saat penggantian kendaraan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->string('trip_number', 40)->unique();
            $table->unsignedBigInteger('route_id')->index();
            $table->unsignedBigInteger('route_version_id')->nullable();
            $table->unsignedBigInteger('schedule_id')->nullable()->index();
            $table->unsignedBigInteger('service_id')->nullable()->index();
            $table->unsignedBigInteger('campus_id')->nullable()->index();
            $table->date('trip_date')->index();
            $table->string('direction', 16)->default('PICKUP');
            $table->time('planned_departure');
            $table->time('planned_arrival')->nullable();
            $table->timestamp('planned_start_at')->nullable();
            $table->timestamp('planned_end_at')->nullable();
            $table->timestamp('actual_start_at')->nullable();
            $table->timestamp('actual_end_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->unsignedBigInteger('dispatched_by')->nullable();
            $table->string('status', 24)->default('PLANNED');
            $table->boolean('is_ad_hoc')->default(false);
            $table->string('trip_type', 24)->default('REGULAR'); // REGULAR, EVENT, FIELD_TRIP, COMPETITION, EMERGENCY, SPECIAL
            $table->unsignedBigInteger('event_reference_id')->nullable();
            $table->string('event_reference_type')->nullable();
            $table->unsignedBigInteger('vehicle_id')->nullable()->index();
            $table->unsignedBigInteger('driver_profile_id')->nullable()->index();
            $table->unsignedInteger('planned_passengers')->default(0);
            $table->unsignedInteger('boarded_count')->default(0);
            $table->unsignedInteger('dropped_off_count')->default(0);
            $table->unsignedInteger('no_show_count')->default(0);
            $table->decimal('start_odometer', 12, 2)->nullable();
            $table->decimal('end_odometer', 12, 2)->nullable();
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->integer('delay_minutes')->default(0);
            $table->string('delay_reason', 30)->nullable();
            $table->json('dispatch_validation')->nullable();
            $table->string('override_reason')->nullable();      // bila dispatch melewati blokir
            $table->unsignedBigInteger('override_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('notification_status', 20)->default('PENDING');
            $table->string('idempotency_key')->nullable()->unique();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['trip_date', 'status'], 'trips_date_status_idx');
            $table->index(['driver_profile_id', 'trip_date'], 'trips_driver_date_idx');
            $table->index(['vehicle_id', 'trip_date'], 'trips_vehicle_date_idx');
        });

        Schema::create('trip_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trip_id')->index();
            $table->string('assignment_type', 20);             // VEHICLE, DRIVER
            $table->unsignedBigInteger('vehicle_id')->nullable()->index();
            $table->unsignedBigInteger('driver_profile_id')->nullable()->index();
            $table->string('assignment_role', 20)->default('PRIMARY'); // PRIMARY, REPLACEMENT, BACKUP
            $table->unsignedBigInteger('replaces_assignment_id')->nullable();
            $table->string('reason')->nullable();
            $table->string('ownership_type', 20)->default('OWNED');
            $table->unsignedBigInteger('rental_id')->nullable();
            $table->json('validation')->nullable();
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->string('status', 20)->default('ACTIVE');   // ACTIVE, REPLACED, CANCELLED
            $table->timestamps();
        });

        Schema::create('trip_manifests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trip_id')->unique();
            $table->unsignedBigInteger('route_version_id')->nullable();
            $table->unsignedInteger('expected_passengers')->default(0);
            $table->unsignedInteger('boarded_passengers')->default(0);
            $table->unsignedInteger('dropped_off_passengers')->default(0);
            $table->unsignedInteger('absent_passengers')->default(0);
            $table->unsignedInteger('no_show_passengers')->default(0);
            $table->unsignedInteger('capacity')->default(0);
            $table->decimal('occupancy_percent', 5, 2)->default(0);
            $table->string('status', 20)->default('DRAFT');    // DRAFT, FINALIZED, CLOSED
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('trip_passengers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trip_id')->index();
            $table->unsignedBigInteger('manifest_id')->nullable()->index();
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->nullable()->index();   // § staff/teacher transport
            $table->unsignedBigInteger('assignment_id')->nullable();
            $table->unsignedBigInteger('pickup_stop_id')->nullable();
            $table->unsignedBigInteger('dropoff_stop_id')->nullable();
            $table->unsignedInteger('seat_number')->nullable();
            $table->string('passenger_type', 20)->default('STUDENT'); // STUDENT, STAFF, TEACHER, GUEST, PARENT
            $table->string('assignment_status', 20)->default('ASSIGNED');
            $table->string('boarding_status', 20)->default('EXPECTED');
            $table->string('dropoff_status', 20)->default('EXPECTED');
            $table->timestamp('boarded_at')->nullable();
            $table->timestamp('dropped_off_at')->nullable();
            $table->unsignedBigInteger('boarded_stop_id')->nullable();
            $table->unsignedBigInteger('dropped_off_stop_id')->nullable();
            $table->string('boarded_method', 20)->nullable();   // MANUAL, QR, NFC, RFID, SMART_CARD, DEVICE, GPS_GEOFENCE
            $table->string('dropped_off_method', 20)->nullable();
            $table->unsignedBigInteger('authorized_pickup_person_id')->nullable();
            $table->string('picked_up_by_name')->nullable();
            $table->text('special_instruction')->nullable();
            $table->boolean('special_needs')->default(false);
            $table->string('absence_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['trip_id', 'boarding_status'], 'tp_trip_boarding_idx');
            $table->index(['trip_id', 'dropoff_status'], 'tp_trip_dropoff_idx');
        });

        Schema::create('trip_stops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trip_id')->index();
            $table->unsignedBigInteger('route_stop_id')->nullable()->index();
            $table->unsignedInteger('sequence');
            $table->string('name');
            $table->string('stop_type', 30)->default('PICKUP_POINT');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('planned_arrival_at')->nullable();
            $table->timestamp('planned_departure_at')->nullable();
            $table->timestamp('estimated_arrival_at')->nullable();   // §43 ETA
            $table->timestamp('actual_arrival_at')->nullable();
            $table->timestamp('actual_departure_at')->nullable();
            $table->integer('arrival_delay_minutes')->nullable();
            $table->integer('departure_delay_minutes')->nullable();
            $table->unsignedInteger('planned_passengers')->default(0);
            $table->unsignedInteger('boarded_count')->default(0);
            $table->unsignedInteger('dropped_off_count')->default(0);
            $table->unsignedInteger('no_show_count')->default(0);
            $table->string('status', 20)->default('PENDING');   // PENDING, ARRIVED, BOARDING, DEPARTED, SKIPPED
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['trip_id', 'sequence'], 'trip_stop_seq_idx');
        });

        Schema::create('trip_stop_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trip_id')->index();
            $table->unsignedBigInteger('trip_stop_id')->index();
            $table->string('event_type', 30);                  // ARRIVED, BOARDING_STARTED, BOARDED, DEPARTED, SKIPPED, DROPPED_OFF
            $table->unsignedBigInteger('student_id')->nullable();
            $table->unsignedBigInteger('driver_profile_id')->nullable();
            $table->timestamp('occurred_at');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('device_id')->nullable();
            $table->string('source', 20)->default('DRIVER_APP');
            $table->string('idempotency_key')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();

            $table->index(['trip_id', 'event_type'], 'tse_trip_event_idx');
        });

        Schema::create('boarding_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trip_id')->index();
            $table->unsignedBigInteger('trip_passenger_id')->nullable()->index();
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->unsignedBigInteger('stop_id')->nullable();
            $table->string('method', 20)->default('MANUAL');
            $table->string('status_after', 20)->default('BOARDED');
            $table->timestamp('occurred_at');
            $table->string('card_id')->nullable();
            $table->string('device_id')->nullable();
            $table->string('scanned_token')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('source', 20)->default('DRIVER_APP');
            $table->string('external_reference')->nullable();
            $table->boolean('offline_sync')->default(false);
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('dropoff_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trip_id')->index();
            $table->unsignedBigInteger('trip_passenger_id')->nullable()->index();
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->unsignedBigInteger('stop_id')->nullable();
            $table->string('method', 20)->default('MANUAL');
            $table->string('status_after', 20)->default('DROPPED_OFF');
            $table->timestamp('occurred_at');
            $table->unsignedBigInteger('authorized_pickup_person_id')->nullable();
            $table->string('picked_up_by_name')->nullable();
            $table->boolean('authorized_pickup_verified')->default(false);
            $table->boolean('override_used')->default(false);
            $table->string('override_reason')->nullable();
            $table->string('device_id')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('source', 20)->default('DRIVER_APP');
            $table->boolean('offline_sync')->default(false);
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('trip_delay_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trip_id')->index();
            $table->unsignedInteger('delay_minutes');
            $table->string('reason', 30);                       // TRAFFIC, VEHICLE_PROBLEM, ...
            $table->timestamp('reported_at');
            $table->unsignedBigInteger('reported_by')->nullable();
            $table->unsignedBigInteger('trip_stop_id')->nullable();
            $table->boolean('notification_sent')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('passenger_transfers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trip_id')->index();
            $table->unsignedBigInteger('from_vehicle_id')->nullable();
            $table->unsignedBigInteger('to_vehicle_id')->nullable();
            $table->unsignedBigInteger('from_driver_profile_id')->nullable();
            $table->unsignedBigInteger('to_driver_profile_id')->nullable();
            $table->unsignedInteger('passenger_count')->default(0);
            $table->string('reason')->nullable();
            $table->timestamp('transferred_at');
            $table->unsignedBigInteger('authorized_by')->nullable();
            $table->json('passenger_ids')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'passenger_transfers', 'trip_delay_reports', 'dropoff_events', 'boarding_events',
            'trip_stop_events', 'trip_stops', 'trip_passengers', 'trip_manifests',
            'trip_assignments', 'trips',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
